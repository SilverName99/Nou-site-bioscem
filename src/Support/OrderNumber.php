<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Numerotarea comenzilor de pe site.
 *
 * Modul „sequential" dă numere simple, crescătoare (203800, 203801, …),
 * rezervate atomic, ca două comenzi simultane să nu primească același număr.
 * Modul „legacy" păstrează formatul vechi, BV + dată + aleator, ca supapă
 * de întoarcere dacă ceva merge prost.
 */
final class OrderNumber
{
    public const MOD_SECVENTIAL = 'sequential';
    public const MOD_VECHI = 'legacy';

    /** De unde pornește numerotarea dacă nimeni n-a setat altceva. */
    public const START_IMPLICIT = 203800;

    /** Cheia din `settings` care ține următorul număr de emis. */
    public const CHEIE_URMATOR = 'order_number_next';

    /**
     * Câte numere consecutive încercăm dacă dăm peste unele deja folosite.
     * Se întâmplă doar dacă cineva dă contorul înapoi din panou.
     */
    private const MAX_INCERCARI = 100;

    /** Numărul următoarei comenzi, deja rezervat. */
    public static function urmatorul(?PDO $db, array $settings): string
    {
        $mod = trim((string) ($settings[self::cheieMod()] ?? self::MOD_SECVENTIAL));
        if ($mod !== self::MOD_SECVENTIAL || !$db instanceof PDO) {
            return self::vechi();
        }

        $seed = self::normalizeazaStart($settings[self::CHEIE_URMATOR] ?? null);

        for ($i = 0; $i < self::MAX_INCERCARI; $i++) {
            $numar = self::rezerva($db, $seed);
            if ($numar === null) {
                break;
            }
            if (!self::estefolosit($db, (string) $numar)) {
                return (string) $numar;
            }
            // Numărul e deja pe o comandă veche: contorul a fost dat înapoi
            // manual. Mergem mai departe până găsim unul liber.
        }

        // Numerotarea nu are voie să blocheze o comandă reală.
        return self::vechi();
    }

    /** Formatul vechi, păstrat pentru comenzile deja emise și ca rezervă. */
    public static function vechi(): string
    {
        return 'BV' . date('YmdHis') . random_int(100, 999);
    }

    public static function cheieMod(): string
    {
        return 'order_number_mode';
    }

    /** Curăță ce a scris utilizatorul în câmpul „următorul număr". */
    public static function normalizeazaStart(mixed $valoare): int
    {
        // Acceptăm doar cifre, cu eventuale separatoare de mii („203 800", „203.800").
        $curat = preg_replace('/[\s.,]+/', '', trim((string) $valoare)) ?? '';
        if ($curat === '' || preg_match('/^\d+$/', $curat) !== 1) {
            return self::START_IMPLICIT;
        }

        $numar = (int) $curat;

        return $numar > 0 ? $numar : self::START_IMPLICIT;
    }

    /** Ce număr urmează să fie emis, pentru afișare în panou. */
    public static function urmatorulAfisat(?PDO $db, array $settings): int
    {
        $implicit = self::normalizeazaStart($settings[self::CHEIE_URMATOR] ?? null);
        if (!$db instanceof PDO) {
            return $implicit;
        }

        try {
            $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = :k LIMIT 1');
            $stmt->execute([':k' => self::CHEIE_URMATOR]);
            $valoare = $stmt->fetchColumn();
        } catch (Throwable) {
            return $implicit;
        }

        return $valoare === false ? $implicit : self::normalizeazaStart($valoare);
    }

    /**
     * Ia următorul număr și avansează contorul într-o singură operație,
     * ca două comenzi venite în aceeași secundă să nu-l citească la fel.
     */
    private static function rezerva(PDO $db, int $seed): ?int
    {
        $atomic = self::rezervaAtomic($db, $seed);

        return $atomic ?? self::rezervaSimplu($db, $seed);
    }

    /** Varianta MySQL: UPDATE-ul singur decide numărul, fără citire separată. */
    private static function rezervaAtomic(PDO $db, int $seed): ?int
    {
        try {
            $db->prepare('INSERT IGNORE INTO settings (`key`, `value`) VALUES (:k, :v)')
                ->execute([':k' => self::CHEIE_URMATOR, ':v' => (string) $seed]);

            $stmt = $db->prepare(
                'UPDATE settings
                    SET `value` = LAST_INSERT_ID(GREATEST(CAST(`value` AS UNSIGNED), 1)) + 1
                  WHERE `key` = :k'
            );
            $stmt->execute([':k' => self::CHEIE_URMATOR]);

            $numar = (int) $db->lastInsertId();
            if ($numar <= 0) {
                return null;
            }

            // Ne asigurăm că lastInsertId chiar reflectă numărul rezervat acum
            // și nu o valoare rămasă din altă operație pe aceeași conexiune.
            $check = $db->prepare('SELECT `value` FROM settings WHERE `key` = :k LIMIT 1');
            $check->execute([':k' => self::CHEIE_URMATOR]);
            // Cereri paralele pot împinge contorul și mai sus; important e să nu
            // fie mai mic decât numărul pe care tocmai l-am luat.
            if (self::normalizeazaStart($check->fetchColumn()) < $numar + 1) {
                return null;
            }

            return $numar;
        } catch (Throwable) {
            return null;
        }
    }

    /** Rezervă pe alte motoare de bază de date (ex. SQLite în teste). */
    private static function rezervaSimplu(PDO $db, int $seed): ?int
    {
        try {
            $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = :k LIMIT 1');
            $stmt->execute([':k' => self::CHEIE_URMATOR]);
            $valoare = $stmt->fetchColumn();

            $numar = $valoare === false ? $seed : self::normalizeazaStart($valoare);

            // Scriere portabilă: UPDATE, iar dacă rândul lipsea, INSERT.
            $update = $db->prepare('UPDATE settings SET `value` = :v WHERE `key` = :k');
            $update->execute([':v' => (string) ($numar + 1), ':k' => self::CHEIE_URMATOR]);
            if ($update->rowCount() === 0) {
                $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k, :v)')
                    ->execute([':k' => self::CHEIE_URMATOR, ':v' => (string) ($numar + 1)]);
            }

            return $numar;
        } catch (Throwable) {
            return null;
        }
    }

    private static function estefolosit(PDO $db, string $numar): bool
    {
        try {
            $stmt = $db->prepare('SELECT 1 FROM orders WHERE order_number = :n LIMIT 1');
            $stmt->execute([':n' => $numar]);

            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            // Dacă nu putem verifica, indexul unic din `orders` rămâne plasa.
            return false;
        }
    }
}
