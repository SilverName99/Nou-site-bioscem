<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Adresa publică a site-ului, folosită acolo unde un URL relativ nu ajunge:
 * în emailuri, în feed-uri, în orice ajunge în afara browserului.
 */
final class AppUrl
{
    public static function base(): string
    {
        try {
            $config = require __DIR__ . '/../../config/app.php';
            $appUrl = rtrim((string) ($config['url'] ?? ''), '/');
        } catch (Throwable) {
            $appUrl = '';
        }
        if ($appUrl !== '') {
            return $appUrl;
        }

        // Rezervă pentru cereri web; la rulările din cron nu există HTTP_HOST,
        // deci APP_URL din .env rămâne singura sursă corectă.
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';

        return $scheme . '://' . $host;
    }

    /**
     * Transformă „/contul-meu" în „https://bioscem.ro/contul-meu".
     * URL-urile absolute, mailto: și tel: rămân neatinse.
     */
    public static function absolut(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^(https?:)?//#i', $url) === 1 || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return $url;
        }

        $baza = self::base();
        if ($baza === '') {
            return $url;
        }

        return $baza . '/' . ltrim($url, '/');
    }
}
