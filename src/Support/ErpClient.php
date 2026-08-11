<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Client HTTP pentru ERP-ul ANDAXI.
 *
 * Autentificarea se face cu cheia generată în ERP (Setări → Setări site),
 * trimisă în antetul `X-Andaxi-Site-Key`. Toate metodele aruncă
 * RuntimeException cu un mesaj citibil de operator — apelantul decide dacă
 * reîncearcă sau doar notează eroarea pe comandă.
 */
final class ErpClient
{
    private const HEADER = 'X-Andaxi-Site-Key';

    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 20)
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->apiKey = trim($apiKey);
        $this->timeout = max(5, $timeout);
    }

    /** Construiește clientul din setările salvate în admin. */
    public static function fromSettings(array $settings): ?self
    {
        $url = trim((string) ($settings['erp_url'] ?? ''));
        $key = trim((string) ($settings['erp_api_key'] ?? ''));
        if ($url === '' || $key === '') {
            return null;
        }
        return new self($url, $key, (int) ($settings['erp_timeout'] ?? 20));
    }

    /** Clientul activ, sau null dacă integrarea e oprită/neconfigurată. */
    public static function fromDb(PDO $db): ?self
    {
        try {
            $settings = Settings::all($db);
        } catch (Throwable) {
            return null;
        }
        if ((string) ($settings['erp_enabled'] ?? '0') !== '1') {
            return null;
        }
        return self::fromSettings($settings);
    }

    /** Verifică dacă ERP-ul răspunde și are gestiunea configurată. */
    public function ping(): array
    {
        return $this->request('GET', '/api/site/ping');
    }

    /**
     * Disponibilul pe SKU-uri. Cheia rezultatului e SKU-ul (majuscule).
     *
     * @param string[] $skus
     * @return array<string, array{cunoscut: bool, disponibil: float, existent: bool}>
     */
    public function stockBySku(array $skus): array
    {
        $clean = [];
        foreach ($skus as $sku) {
            $value = trim((string) $sku);
            if ($value !== '') {
                $clean[strtoupper($value)] = $value;
            }
        }
        if ($clean === []) {
            return [];
        }

        $response = $this->request('POST', '/api/site/stoc', ['skuuri' => array_values($clean)]);

        $out = [];
        foreach ((is_array($response) ? $response : []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }
            $out[$sku] = [
                'cunoscut' => (bool) ($row['cunoscut'] ?? false),
                'disponibil' => (float) ($row['disponibil'] ?? 0),
                'existent' => (bool) ($row['existent'] ?? false),
            ];
        }
        return $out;
    }

    /**
     * Notificările pe care ERP-ul nu a reușit să ni le livreze (site jos,
     * rețea căzută). Cron-ul le preia și le aplică, apoi le confirmă.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingNotifications(): array
    {
        $rows = $this->request('GET', '/api/site/notificari');
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Confirmă notificările procesate, ca ERP-ul să nu le mai trimită.
     *
     * @param int[] $ids
     */
    public function confirmNotifications(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->request('POST', '/api/site/notificari/confirma', ['ids' => array_values($ids)]);
    }

    /** Raportează în ERP AWB-ul generat pe site. */
    public function reportAwb(string $numarSite, string $awb, string $trackingUrl = ''): void
    {
        if (trim($numarSite) === '' || trim($awb) === '') {
            return;
        }
        $this->request('POST', '/api/site/comenzi/awb', [
            'numarSite' => trim($numarSite),
            'awb' => trim($awb),
            'trackingUrl' => trim($trackingUrl),
        ]);
    }

    /** Trimite o comandă în ERP. Idempotent: ERP-ul deduplică pe numărul comenzii. */
    public function pushOrder(array $payload): array
    {
        return $this->request('POST', '/api/comenzi-site/ingest', $payload);
    }

    /**
     * @param array<mixed>|null $body
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new RuntimeException('Integrarea cu ERP-ul nu este configurată complet.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensia cURL lipsește pe server; nu pot contacta ERP-ul.');
        }

        $url = $this->baseUrl . $path;
        $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body !== null && $json === false) {
            throw new RuntimeException('Nu am putut serializa datele pentru ERP.');
        }

        $headers = [
            'Accept: application/json',
            self::HEADER . ': ' . $this->apiKey,
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Nu am putut contacta ERP-ul: ' . ($error !== '' ? $error : 'eroare de rețea'));
        }

        $decoded = json_decode((string) $raw, true);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->errorMessage($status, $decoded, (string) $raw));
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** Traduce răspunsurile de eroare ale ERP-ului în mesaje pentru operator. */
    private function errorMessage(int $status, mixed $decoded, string $raw): string
    {
        $detail = '';
        if (is_array($decoded)) {
            $message = $decoded['message'] ?? null;
            if (is_array($message)) {
                $detail = implode(' ', array_map('strval', $message));
            } elseif (is_string($message)) {
                $detail = $message;
            }
        }
        if ($detail === '') {
            $detail = trim(mb_substr($raw, 0, 300));
        }

        return match (true) {
            $status === 401 => 'ERP: cheie de integrare invalidă sau integrarea e dezactivată în ERP. ' . $detail,
            $status === 404 => 'ERP: adresa configurată nu răspunde la această rută (verifică URL-ul). ' . $detail,
            $status >= 500 => 'ERP: eroare pe server (' . $status . '). ' . $detail,
            default => 'ERP a respins cererea (' . $status . '). ' . $detail,
        };
    }
}
