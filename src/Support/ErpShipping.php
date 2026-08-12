<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Adresa de expediere per depozit, când ERP-ul folosește gestiuni pe județe.
 *
 * ERP-ul spune ce depozit deservește fiecare județ (GET /site/gestiuni, ținut
 * într-un cache scurt); adresele FAN ale depozitelor stau pe site, în setarea
 * `fan_depozite_json` (completate în Setări livrare → Setări de livrare).
 *
 * Regula de siguranță e aceeași ca la stoc: dacă ERP-ul nu răspunde, județul
 * nu e mapat sau adresa depozitului e incompletă, se folosește expeditorul
 * global — o comandă nu rămâne niciodată fără adresă de ridicare.
 */
final class ErpShipping
{
    private const CACHE_TTL_SECONDS = 300;

    /** @var array{gestiuniPeJudete: bool, gestiuni: array<int, array{id: string, name: string, judete: string[]}>}|null */
    private static ?array $memo = null;

    /**
     * Expeditorul FAN pentru județul de livrare al clientului, sau null dacă
     * se aplică expeditorul global. Cheile întoarse sunt cele `fan_sender_*`,
     * deci rezultatul se poate folosi ca strat peste setările globale:
     *
     *   $sender = ErpShipping::senderForCounty($db, $settings, $county);
     *   $effective = $sender !== null ? array_merge($settings, $sender) : $settings;
     *
     * @return array<string, string>|null
     */
    public static function senderForCounty(?PDO $db, array $settings, string $county): ?array
    {
        if (!$db instanceof PDO || trim($county) === '') {
            return null;
        }
        if ((string) ($settings['erp_enabled'] ?? '0') !== '1') {
            return null;
        }

        $mapare = self::gestiuni($db);
        if ($mapare === null || ($mapare['gestiuniPeJudete'] ?? false) !== true) {
            return null;
        }

        $gestiuneId = self::gestiuneForCounty($mapare['gestiuni'], $county);
        if ($gestiuneId === null) {
            return null;
        }

        $adresa = self::depotAddress($settings, $gestiuneId);
        if ($adresa === null) {
            return null;
        }

        // Numele/telefonul/emailul lipsă cad pe expeditorul global.
        return array_filter([
            'fan_sender_name' => $adresa['name'] ?? '',
            'fan_sender_phone' => $adresa['phone'] ?? '',
            'fan_sender_email' => $adresa['email'] ?? '',
            'fan_sender_county' => $adresa['county'] ?? '',
            'fan_sender_locality' => $adresa['locality'] ?? '',
            'fan_sender_street' => $adresa['street'] ?? '',
            'fan_sender_street_no' => $adresa['street_no'] ?? '',
            'fan_sender_zip_code' => $adresa['zip_code'] ?? '',
        ], static fn (string $v): bool => trim($v) !== '');
    }

    /**
     * Gestiunea care deservește un județ (după numele FAN al județului).
     * Când județul apare la mai multe gestiuni, câștigă prima — alegerea e
     * oricum ajustabilă de operator, per comandă, în ERP.
     *
     * @param array<int, array{id: string, name: string, judete: string[]}> $gestiuni
     */
    public static function gestiuneForCounty(array $gestiuni, string $county): ?string
    {
        $wanted = self::normalizeCounty($county);
        if ($wanted === '') {
            return null;
        }
        foreach ($gestiuni as $gestiune) {
            foreach ((array) ($gestiune['judete'] ?? []) as $judet) {
                if (self::normalizeCounty((string) $judet) === $wanted) {
                    return (string) $gestiune['id'];
                }
            }
        }
        // Județele din ERP sunt coduri (CJ, B); numele FAN nu se potrivește pe
        // cod decât pentru abrevieri — încercăm și potrivirea nume ↔ cod scurt.
        foreach ($gestiuni as $gestiune) {
            foreach ((array) ($gestiune['judete'] ?? []) as $judet) {
                $cod = strtoupper(trim((string) $judet));
                if ($cod !== '' && strlen($cod) <= 3 && self::countyMatchesCode($county, $cod)) {
                    return (string) $gestiune['id'];
                }
            }
        }
        return null;
    }

    /**
     * Maparea gestiuni→județe din ERP, cu cache pe disc (TTL scurt).
     *
     * @return array{gestiuniPeJudete: bool, gestiuni: array<int, array{id: string, name: string, judete: string[]}>}|null
     */
    public static function gestiuni(PDO $db): ?array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $file = self::cacheFile();
        if (is_file($file) && time() - (int) @filemtime($file) <= self::CACHE_TTL_SECONDS) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['gestiuni'])) {
                self::$memo = [
                    'gestiuniPeJudete' => (bool) ($decoded['gestiuniPeJudete'] ?? false),
                    'gestiuni' => (array) $decoded['gestiuni'],
                ];
                return self::$memo;
            }
        }

        $client = ErpClient::fromDb($db);
        if ($client === null) {
            return self::staleCache();
        }

        try {
            $mapare = $client->gestiuni();
        } catch (Throwable) {
            // ERP indisponibil: mai bine o mapare veche decât niciuna.
            return self::staleCache();
        }

        self::$memo = $mapare;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $json = json_encode($mapare, JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            @file_put_contents($file, $json, LOCK_EX);
        }
        return $mapare;
    }

    /** Golește cache-ul (după schimbarea setărilor ERP). */
    public static function flush(): void
    {
        self::$memo = null;
        $file = self::cacheFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Adresa FAN a unui depozit, din `fan_depozite_json`. Null dacă lipsește
     * sau nu are minimul necesar pentru AWB (județ + localitate + stradă).
     *
     * @return array<string, string>|null
     */
    public static function depotAddress(array $settings, string $gestiuneId): ?array
    {
        $decoded = json_decode((string) ($settings['fan_depozite_json'] ?? ''), true);
        if (!is_array($decoded)) {
            return null;
        }
        $adresa = $decoded[$gestiuneId] ?? null;
        if (!is_array($adresa)) {
            return null;
        }
        $out = [];
        foreach (['name', 'phone', 'email', 'county', 'locality', 'street', 'street_no', 'zip_code'] as $key) {
            $out[$key] = trim((string) ($adresa[$key] ?? ''));
        }
        if ($out['county'] === '' || $out['locality'] === '' || $out['street'] === '') {
            return null;
        }
        return $out;
    }

    /** Nume de județ normalizat: fără diacritice, fără „Municipiul", litere mici. */
    private static function normalizeCounty(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($translit) && $translit !== '') {
            $value = $translit;
        }
        $value = strtolower($value);
        $value = (string) preg_replace('/^municipiul\s+/', '', $value);
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /** „Cluj" ↔ „CJ": potrivire nume FAN cu un cod scurt de județ. */
    private static function countyMatchesCode(string $county, string $cod): bool
    {
        static $coduri = [
            'alba' => 'AB', 'arad' => 'AR', 'arges' => 'AG', 'bacau' => 'BC',
            'bihor' => 'BH', 'bistrita-nasaud' => 'BN', 'botosani' => 'BT',
            'brasov' => 'BV', 'braila' => 'BR', 'bucuresti' => 'B', 'buzau' => 'BZ',
            'caras-severin' => 'CS', 'calarasi' => 'CL', 'cluj' => 'CJ',
            'constanta' => 'CT', 'covasna' => 'CV', 'dambovita' => 'DB',
            'dolj' => 'DJ', 'galati' => 'GL', 'giurgiu' => 'GR', 'gorj' => 'GJ',
            'harghita' => 'HR', 'hunedoara' => 'HD', 'ialomita' => 'IL',
            'iasi' => 'IS', 'ilfov' => 'IF', 'maramures' => 'MM',
            'mehedinti' => 'MH', 'mures' => 'MS', 'neamt' => 'NT', 'olt' => 'OT',
            'prahova' => 'PH', 'satu mare' => 'SM', 'salaj' => 'SJ',
            'sibiu' => 'SB', 'suceava' => 'SV', 'teleorman' => 'TR',
            'timis' => 'TM', 'tulcea' => 'TL', 'vaslui' => 'VS', 'valcea' => 'VL',
            'vrancea' => 'VN',
        ];
        return ($coduri[self::normalizeCounty($county)] ?? null) === $cod;
    }

    /** @return array{gestiuniPeJudete: bool, gestiuni: array<int, array{id: string, name: string, judete: string[]}>}|null */
    private static function staleCache(): ?array
    {
        $file = self::cacheFile();
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded) || !isset($decoded['gestiuni'])) {
            return null;
        }
        self::$memo = [
            'gestiuniPeJudete' => (bool) ($decoded['gestiuniPeJudete'] ?? false),
            'gestiuni' => (array) $decoded['gestiuni'],
        ];
        return self::$memo;
    }

    private static function cacheFile(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/erp-gestiuni.json';
    }
}
