<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class StripeGateway
{
    private const API_BASE = 'https://api.stripe.com';

    public static function createCheckoutSession(string $secretKey, array $fields): array
    {
        return self::request('POST', '/v1/checkout/sessions', $secretKey, $fields);
    }

    public static function retrieveCheckoutSession(string $secretKey, string $sessionId, array $fields = []): array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            throw new RuntimeException('ID-ul sesiunii Stripe lipsește.');
        }

        return self::request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), $secretKey, $fields);
    }

    public static function verifyWebhookSignature(
        string $payload,
        string $signatureHeader,
        string $endpointSecret,
        int $tolerance = 300
    ): bool {
        if ($payload === '' || $signatureHeader === '' || $endpointSecret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $chunk) {
            if (!str_contains($chunk, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $chunk, 2);
            $parts[trim($key)][] = trim($value);
        }

        $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
        $signatures = $parts['v1'] ?? [];
        if ($timestamp <= 0 || $signatures === []) {
            return false;
        }

        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signedPayload, $endpointSecret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private static function request(string $method, string $path, string $secretKey, array $fields = []): array
    {
        if ($secretKey === '') {
            throw new RuntimeException('Cheia Stripe secret lipsește.');
        }

        $url = self::API_BASE . $path;
        $payload = http_build_query($fields);

        if (function_exists('curl_init')) {
            $ch = curl_init();
            if ($ch === false) {
                throw new RuntimeException('Nu s-a putut inițializa conexiunea Stripe.');
            }

            $headers = [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ];

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $rawBody = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($rawBody === false) {
                throw new RuntimeException('Stripe API indisponibil: ' . $error);
            }
        } else {
            $requestUrl = $method === 'GET' && $payload !== '' ? $url . '?' . $payload : $url;
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", [
                        'Authorization: Bearer ' . $secretKey,
                        'Content-Type: application/x-www-form-urlencoded',
                    ]),
                    'content' => $method === 'POST' ? $payload : '',
                    'timeout' => 30,
                    'ignore_errors' => true,
                ],
            ]);

            $rawBody = @file_get_contents($requestUrl, false, $context);
            if ($rawBody === false) {
                throw new RuntimeException('Stripe API indisponibil.');
            }

            $status = 0;
            $headers = $http_response_header ?? [];
            foreach ($headers as $headerLine) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#', (string) $headerLine, $matches)) {
                    $status = (int) $matches[1];
                    break;
                }
            }
        }

        $response = json_decode($rawBody, true);
        if (!is_array($response)) {
            throw new RuntimeException('Răspuns Stripe invalid.');
        }

        if ($status < 200 || $status >= 300) {
            $message = (string) ($response['error']['message'] ?? 'Eroare Stripe.');
            throw new RuntimeException($message);
        }

        return $response;
    }
}

