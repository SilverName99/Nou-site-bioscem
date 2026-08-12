<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * EuPlătesc — plata cu cardul prin redirect.
 *
 * Spre deosebire de Stripe, EuPlătesc nu are un API care întoarce un URL de
 * plată: comanda se trimite ca formular POST semnat către gateway-ul lor, iar
 * clientul se întoarce pe `successurl` / `failedurl`. Confirmarea pe care ne
 * bazăm e cea server-to-server, trimisă pe `silenturl` (echivalentul unui
 * webhook) — pagina de retur e doar pentru ochii clientului.
 *
 * Semnătura (`fp_hash`) e un HMAC-MD5 peste valorile concatenate în ordine
 * fixă, fiecare prefixată cu lungimea ei; cheia secretă e hexazecimală și se
 * folosește ca octeți. Aceeași regulă validează și răspunsul primit.
 */
final class EuPlatescGateway
{
    /** Gateway-ul de producție (contul de test folosește același URL). */
    public const GATEWAY_URL = 'https://secure.euplatesc.ro/tdsprocess/tranzactd.php';

    /** Câmpurile semnate în cerere, în ordinea impusă de EuPlătesc. */
    private const REQUEST_HASH_FIELDS = [
        'amount',
        'curr',
        'invoice_id',
        'order_desc',
        'merch_id',
        'timestamp',
        'nonce',
    ];

    /** Câmpurile semnate în răspuns, în ordinea impusă de EuPlătesc. */
    private const RESPONSE_HASH_FIELDS = [
        'amount',
        'curr',
        'invoice_id',
        'ep_id',
        'merch_id',
        'action',
        'message',
        'approval',
        'timestamp',
        'nonce',
    ];

    /**
     * Semnătura EuPlătesc: fiecare valoare intră ca `lungime . valoare`, iar
     * valorile goale ca `-`. Cheia secretă e hex și se folosește ca octeți.
     *
     * @param array<int, string> $values
     */
    public static function hash(array $values, string $secretKey): string
    {
        $secretKey = trim($secretKey);
        if ($secretKey === '') {
            throw new RuntimeException('Cheia secretă EuPlătesc lipsește.');
        }

        $payload = '';
        foreach ($values as $value) {
            $value = (string) $value;
            $payload .= $value === '' ? '-' : strlen($value) . $value;
        }

        if (preg_match('/^[0-9a-fA-F]+$/', $secretKey) !== 1 || strlen($secretKey) % 2 !== 0) {
            throw new RuntimeException('Cheia secretă EuPlătesc trebuie să fie hexazecimală.');
        }
        $binaryKey = (string) hex2bin($secretKey);

        return strtoupper(hash_hmac('md5', $payload, $binaryKey));
    }

    /**
     * Câmpurile formularului de plată, gata semnate.
     *
     * @param array<string, mixed> $settings setările site-ului
     * @param array{
     *     order_number: string,
     *     amount: float,
     *     description?: string,
     *     first_name?: string, last_name?: string,
     *     address?: string, city?: string, county?: string,
     *     zip?: string, phone?: string, email?: string,
     *     success_url: string, failed_url: string, silent_url: string, back_url?: string
     * } $order
     * @return array<string, string>
     */
    public static function buildRequest(array $settings, array $order): array
    {
        $merchantId = trim((string) ($settings['euplatesc_merchant_id'] ?? ''));
        $secretKey = trim((string) ($settings['euplatesc_secret_key'] ?? ''));
        if ($merchantId === '' || $secretKey === '') {
            throw new RuntimeException('Contul EuPlătesc nu este configurat în admin (Setări plăți).');
        }

        $amount = round((float) ($order['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Valoarea totală a comenzii este invalidă.');
        }

        $currency = strtoupper(trim((string) ($settings['euplatesc_currency'] ?? 'RON')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'RON';
        }

        $invoiceId = trim((string) ($order['order_number'] ?? ''));
        if ($invoiceId === '') {
            throw new RuntimeException('Comanda nu are număr.');
        }

        $description = trim((string) ($order['description'] ?? ''));
        if ($description === '') {
            $description = 'Comanda ' . $invoiceId;
        }

        $fields = [
            'amount' => number_format($amount, 2, '.', ''),
            'curr' => $currency,
            'invoice_id' => $invoiceId,
            'order_desc' => $description,
            'merch_id' => $merchantId,
            // EuPlătesc cere ora UTC, în formatul YmdHis.
            'timestamp' => gmdate('YmdHis'),
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $fields['fp_hash'] = self::hash(
            array_map(static fn (string $key): string => $fields[$key], self::REQUEST_HASH_FIELDS),
            $secretKey
        );

        // Datele clientului sunt opționale, dar prefac formularul 3-D Secure
        // și ajută la verificările antifraudă.
        $optional = [
            'fname' => (string) ($order['first_name'] ?? ''),
            'lname' => (string) ($order['last_name'] ?? ''),
            'ad' => (string) ($order['address'] ?? ''),
            'city' => (string) ($order['city'] ?? ''),
            'state' => (string) ($order['county'] ?? ''),
            'zip' => (string) ($order['zip'] ?? ''),
            'country' => 'Romania',
            'phone' => (string) ($order['phone'] ?? ''),
            'email' => (string) ($order['email'] ?? ''),
        ];
        foreach ($optional as $key => $value) {
            $value = trim($value);
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }

        // ExtraData nu intră în semnătură; sunt setări de flux.
        $fields['ExtraData[silenturl]'] = (string) ($order['silent_url'] ?? '');
        $fields['ExtraData[successurl]'] = (string) ($order['success_url'] ?? '');
        $fields['ExtraData[failedurl]'] = (string) ($order['failed_url'] ?? '');
        $fields['ExtraData[backtosite]'] = (string) ($order['back_url'] ?? ($order['failed_url'] ?? ''));
        $fields['ExtraData[backtositeMethod]'] = 'GET';
        $fields['ExtraData[lang]'] = 'ro';

        return array_filter($fields, static fn (string $v): bool => $v !== '');
    }

    /**
     * Verifică semnătura unui răspuns EuPlătesc (retur sau silent URL).
     *
     * @param array<string, mixed> $response
     */
    public static function verifyResponse(array $response, string $secretKey): bool
    {
        $primit = strtoupper(trim((string) ($response['fp_hash'] ?? '')));
        if ($primit === '') {
            return false;
        }

        try {
            $asteptat = self::hash(
                array_map(
                    static fn (string $key): string => (string) ($response[$key] ?? ''),
                    self::RESPONSE_HASH_FIELDS
                ),
                $secretKey
            );
        } catch (RuntimeException) {
            return false;
        }

        return hash_equals($asteptat, $primit);
    }

    /**
     * Tranzacție aprobată? EuPlătesc trimite `action = 0` la succes; orice
     * altă valoare e refuz, anulare sau eroare, detaliată în `message`.
     *
     * @param array<string, mixed> $response
     */
    public static function isApproved(array $response): bool
    {
        return trim((string) ($response['action'] ?? '')) === '0';
    }

    /** Mesajul de afișat/înregistrat pentru o tranzacție neaprobată. */
    public static function errorMessage(array $response): string
    {
        $message = trim((string) ($response['message'] ?? ''));
        $action = trim((string) ($response['action'] ?? ''));
        if ($message === '') {
            $message = 'Plata a fost respinsă de procesator.';
        }
        return $action !== '' ? $message . ' (cod ' . $action . ')' : $message;
    }

    /** Configurarea minimă e completă? */
    public static function isConfigured(array $settings): bool
    {
        return trim((string) ($settings['euplatesc_merchant_id'] ?? '')) !== ''
            && trim((string) ($settings['euplatesc_secret_key'] ?? '')) !== '';
    }

    /** Metoda de plată cu cardul e disponibilă în checkout? */
    public static function isEnabled(array $settings): bool
    {
        return (string) ($settings['euplatesc_enabled'] ?? '0') === '1'
            && self::isConfigured($settings);
    }
}
