<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class FanCourierGateway
{
    private const API_BASE = 'https://api.fancourier.ro';

    private static ?string $cachedToken = null;
    private static int $cachedTokenAt = 0;

    public static function quoteInternalTariff(array $credentials, array $params): array
    {
        $query = self::buildTariffQuery($params);

        try {
            $response = self::request('GET', '/reports/awb/internal-tariff?' . http_build_query($query), $credentials);
            return self::extractData($response);
        } catch (RuntimeException $exception) {
            // Some FAN accounts still expect RO labels for payment fields.
            if (($query['info']['payment'] ?? null) === 'sender') {
                $query['info']['payment'] = 'expeditor';
            } elseif (($query['info']['payment'] ?? null) === 'recipient') {
                $query['info']['payment'] = 'destinatar';
            }

            $response = self::request('GET', '/reports/awb/internal-tariff?' . http_build_query($query), $credentials);
            return self::extractData($response, $exception->getMessage());
        }
    }

    /**
     * Nomenclatorul oficial de puncte FANbox / PayPoint / oficii.
     *
     * E singura sursă bună pentru `pickupLocationId`: la emiterea AWB-ului FAN
     * cere ID-ul punctului din lista ASTA, nu un cod inventat de noi și nu
     * adresa lui. Un id care nu vine de aici e respins cu „fanBoxIsInvalid".
     *
     * @return list<array<string, mixed>>
     */
    public static function pickupPoints(array $credentials, string $type = 'fanbox'): array
    {
        $tip = in_array($type, ['fanbox', 'paypoint', 'office'], true) ? $type : 'fanbox';
        $response = self::request('GET', '/reports/pickup-points?' . http_build_query(['type' => $tip]), $credentials);
        $data = self::extractData($response);
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $rand) {
            if (is_array($rand)) {
                $out[] = $rand;
            }
        }

        return $out;
    }

    public static function createInternalAwb(array $credentials, array $payload): array
    {
        $response = self::request('POST', '/intern-awb', $credentials, $payload);
        $data = self::extractData($response);

        $awb = self::extractAwbNumber($response);
        if ($awb === null) {
            throw new RuntimeException('FAN nu a returnat un numar AWB valid. Raspuns: ' . self::responsePreview($response));
        }

        return [
            'awb' => $awb,
            'raw' => $data,
        ];
    }

    public static function trackAwb(array $credentials, int $clientId, string $awb, string $language = 'ro'): array
    {
        $query = [
            'clientId' => $clientId,
            'language' => $language,
            'awb' => [$awb],
        ];

        $response = self::request('GET', '/reports/awb/tracking?' . http_build_query($query), $credentials);
        $data = self::extractData($response);
        $first = is_array($data) && isset($data[0]) && is_array($data[0]) ? $data[0] : [];
        $events = is_array($first['events'] ?? null) ? $first['events'] : [];
        $latest = $events !== [] ? $events[count($events) - 1] : null;

        return [
            'awb' => (string) ($first['awbNumber'] ?? $awb),
            'recipient_confirmation_name' => (string) (($first['confirmation']['name'] ?? '') ?: ''),
            'recipient_confirmation_date' => (string) (($first['confirmation']['date'] ?? '') ?: ''),
            'latest_event_id' => is_array($latest) ? (string) ($latest['id'] ?? '') : '',
            'latest_event_name' => is_array($latest) ? (string) ($latest['name'] ?? '') : '',
            'latest_event_location' => is_array($latest) ? (string) ($latest['location'] ?? '') : '',
            'latest_event_at' => is_array($latest) ? (string) ($latest['date'] ?? '') : '',
            'events' => $events,
            'raw' => $first,
        ];
    }

    public static function trackingUrl(string $awb): string
    {
        $encodedAwb = urlencode($awb);
        return 'https://www.fancourier.ro/awb-tracking/?tracking=' . $encodedAwb . '&awbnr=' . $encodedAwb;
    }

    private static function buildTariffQuery(array $params): array
    {
        $query = [
            'clientId' => (int) ($params['clientId'] ?? 0),
            'info' => [
                'service' => (string) ($params['service'] ?? 'Standard'),
                'payment' => (string) ($params['payment'] ?? 'recipient'),
                'weight' => (float) ($params['weight'] ?? 1),
                'packages' => [
                    'parcel' => max(1, (int) ($params['parcel'] ?? 1)),
                    'envelope' => max(0, (int) ($params['envelope'] ?? 0)),
                ],
                'declaredValue' => max(0, (float) ($params['declaredValue'] ?? 0)),
            ],
            'recipient' => [
                'locality' => (string) ($params['recipientLocality'] ?? ''),
                'county' => (string) ($params['recipientCounty'] ?? ''),
            ],
        ];

        $dimensions = [
            'height' => (float) ($params['height'] ?? 0),
            'width' => (float) ($params['width'] ?? 0),
            'length' => (float) ($params['length'] ?? 0),
        ];
        foreach ($dimensions as $key => $value) {
            if ($value > 0) {
                $query['info']['dimensions'][$key] = $value;
            }
        }

        $senderCounty = trim((string) ($params['senderCounty'] ?? ''));
        $senderLocality = trim((string) ($params['senderLocality'] ?? ''));
        if ($senderCounty !== '') {
            $query['sender']['county'] = $senderCounty;
        }
        if ($senderLocality !== '') {
            $query['sender']['locality'] = $senderLocality;
        }

        return $query;
    }

    private static function extractAwbNumber(array $response): ?string
    {
        $candidates = [
            $response['data'][0]['info']['awbNumber'] ?? null,
            $response['data'][0]['info']['awbNo'] ?? null,
            $response['data'][0]['awbNumber'] ?? null,
            $response['data'][0]['awbNo'] ?? null,
            $response['data']['awbNumber'] ?? null,
            $response['data']['awbNo'] ?? null,
            $response['data'][0]['awb'] ?? null,
            $response['data']['awb'] ?? null,
            $response['awb'] ?? null,
            $response['data'][0]['shipment']['awbNumber'] ?? null,
            $response['data'][0]['shipment']['awbNo'] ?? null,
            $response['data']['shipment']['awbNumber'] ?? null,
            $response['data']['shipment']['awbNo'] ?? null,
            $response['data'][0]['shipments'][0]['awbNumber'] ?? null,
            $response['data'][0]['shipments'][0]['awbNo'] ?? null,
            $response['data']['shipments'][0]['awbNumber'] ?? null,
            $response['data']['shipments'][0]['awbNo'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = self::normalizeAwbCandidate((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $recursive = self::extractAwbFromNode($response);
        if ($recursive !== '') {
            return $recursive;
        }

        return null;
    }

    private static function extractAwbFromNode(mixed $node): string
    {
        if (!is_array($node)) {
            return '';
        }

        foreach ($node as $key => $value) {
            $keyText = is_string($key) ? $key : (string) $key;
            $keyLower = self::strLower($keyText);
            if (preg_match('/awb|waybill|barcod/', $keyLower) === 1) {
                if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                    $awb = self::normalizeAwbCandidate((string) $value);
                    if ($awb !== '') {
                        return $awb;
                    }
                }
            }

            if (is_array($value)) {
                $nested = self::extractAwbFromNode($value);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private static function normalizeAwbCandidate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // Ignore links and generic labels.
        if (preg_match('#^https?://#i', $value) === 1) {
            return '';
        }

        if (preg_match('/\d{10,20}/', $value, $matches) === 1) {
            return (string) ($matches[0] ?? '');
        }

        $compact = preg_replace('/[^A-Za-z0-9]/', '', $value);
        if (!is_string($compact) || $compact === '') {
            return '';
        }
        if (self::strLength($compact) < 10 || self::strLength($compact) > 25) {
            return '';
        }
        if (preg_match('/\d{8,}/', $compact) !== 1) {
            return '';
        }

        return $compact;
    }

    private static function responsePreview(array $response): string
    {
        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $text = is_string($json) && $json !== '' ? $json : '[raspuns JSON indisponibil]';
        if (self::strLength($text) > 420) {
            return self::strSubstr($text, 0, 420) . '...';
        }

        return $text;
    }

    private static function extractData(array $response, string $previousError = ''): array
    {
        $status = strtolower((string) ($response['status'] ?? 'success'));
        if ($status !== '' && $status !== 'success') {
            $message = trim((string) ($response['message'] ?? 'Eroare FAN API.'));
            if ($previousError !== '') {
                $message .= ' (fallback: ' . $previousError . ')';
            }
            throw new RuntimeException($message);
        }

        $data = $response['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    private static function request(string $method, string $path, array $credentials, array $payload = []): array
    {
        $token = self::token($credentials);
        $url = self::API_BASE . $path;
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        $body = null;
        if ($payload !== []) {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                throw new RuntimeException('Nu am putut serializa payload-ul FAN.');
            }
            $headers[] = 'Content-Type: application/json';
        }

        $result = self::rawRequest($method, $url, $headers, $body);
        if ($result['status'] >= 400) {
            $text = trim($result['body']);
            $message = $text !== '' ? $text : ('FAN API HTTP ' . $result['status']);
            throw new RuntimeException($message);
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Raspuns FAN invalid: ' . $result['body']);
        }

        return $decoded;
    }

    private static function token(array $credentials): string
    {
        if (self::$cachedToken !== null && (time() - self::$cachedTokenAt) < 23 * 3600) {
            return self::$cachedToken;
        }

        $username = trim((string) ($credentials['username'] ?? ''));
        $password = trim((string) ($credentials['password'] ?? ''));
        if ($username === '' || $password === '') {
            throw new RuntimeException('Datele FAN API (username/parola) nu sunt configurate.');
        }

        $url = self::API_BASE . '/login?' . http_build_query([
            'username' => $username,
            'password' => $password,
        ]);
        $result = self::rawRequest('POST', $url, ['Accept: application/json'], null);
        $token = '';
        $status = (int) ($result['status'] ?? 0);
        if ($status >= 200 && $status < 400) {
            $token = self::extractAuthTokenFromBody((string) ($result['body'] ?? ''));
        }

        // Some FAN environments still expect GET for login.
        if ($token === '') {
            $fallback = self::rawRequest('GET', $url, ['Accept: application/json'], null);
            $fallbackStatus = (int) ($fallback['status'] ?? 0);
            if ($fallbackStatus >= 200 && $fallbackStatus < 400) {
                $token = self::extractAuthTokenFromBody((string) ($fallback['body'] ?? ''));
            }
            if ($status >= 400 && $fallbackStatus >= 400) {
                throw new RuntimeException('Autentificare FAN eșuată: HTTP ' . $status . ' / ' . $fallbackStatus);
            }
            if ($status >= 400 && $fallbackStatus < 400) {
                $status = $fallbackStatus;
            }
        }

        if ($status >= 400 && $token === '') {
            throw new RuntimeException('Autentificare FAN eșuată: HTTP ' . $status);
        }
        if ($token === '') {
            $raw = trim((string) ($result['body'] ?? ''));
            if (self::strLength($raw) > 180) {
                $raw = self::strSubstr($raw, 0, 180) . '...';
            }
            throw new RuntimeException('Autentificare FAN eșuată: token lipsă. Răspuns: ' . $raw);
        }

        self::$cachedToken = $token;
        self::$cachedTokenAt = time();
        return $token;
    }

    private static function extractAuthTokenFromBody(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $decoded = json_decode($body, true);
        $candidates = [];
        if (is_array($decoded)) {
            $candidates = [
                $decoded['token'] ?? null,
                $decoded['access_token'] ?? null,
                $decoded['jwt'] ?? null,
                $decoded['data']['token'] ?? null,
                $decoded['data']['access_token'] ?? null,
                $decoded['data']['jwt'] ?? null,
                $decoded['data'][0]['token'] ?? null,
                $decoded['data'][0]['access_token'] ?? null,
                $decoded['result']['token'] ?? null,
                $decoded['result']['access_token'] ?? null,
                is_string($decoded['data'] ?? null) ? $decoded['data'] : null,
            ];
        } elseif (is_string($decoded)) {
            $candidates[] = $decoded;
        }
        $candidates[] = $body;

        foreach ($candidates as $candidate) {
            $token = self::normalizeAuthToken((string) ($candidate ?? ''));
            if ($token !== '') {
                return $token;
            }
        }

        if (preg_match('/"(?:token|access_token|jwt)"\s*:\s*"([^"]+)"/i', $body, $matches) === 1) {
            return self::normalizeAuthToken((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private static function normalizeAuthToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }
        $token = trim($token, "\"' \t\n\r\0\x0B");
        if (stripos($token, 'bearer ') === 0) {
            $token = trim(substr($token, 7));
        }

        $lower = self::strLower($token);
        if (in_array($lower, ['ok', 'success', 'error', 'true', 'false', 'null'], true)) {
            return '';
        }
        // Keep this permissive, because some environments return shorter opaque tokens.
        if (self::strLength($token) < 8) {
            return '';
        }

        return $token;
    }

    private static function strLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value);
        }

        return strlen($value);
    }

    private static function strSubstr(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? (string) mb_substr($value, $start)
                : (string) mb_substr($value, $start, $length);
        }

        return $length === null
            ? (string) substr($value, $start)
            : (string) substr($value, $start, $length);
    }

    private static function strLower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return (string) mb_strtolower($value);
        }

        return strtolower($value);
    }

    private static function rawRequest(string $method, string $url, array $headers, ?string $body): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new RuntimeException('FAN API cURL error: ' . $error);
            }

            return [
                'status' => $httpCode,
                'body' => (string) $response,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 25,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('FAN API request eșuat (file_get_contents).');
        }

        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\d+(?:\.\d+)?\s+(\d+)#', $line, $matches) === 1) {
                $status = (int) $matches[1];
                break;
            }
        }

        return [
            'status' => $status,
            'body' => (string) $response,
        ];
    }
}
