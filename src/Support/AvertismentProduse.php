<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Avertismentul unui produs (ex. „ATENȚIE – PRODUS REFRIGERAT”).
 *
 * Fiecare produs poate avea, din Admin → Produse, trei câmpuri de text simplu:
 * titlul, textul (un rând gol = paragraf nou) și eticheta bifei de la
 * finalizarea comenzii. Avertismentul e ACTIV doar când textul nu e gol; tot
 * ce urmează se întâmplă doar pentru produsele cu avertisment activ:
 *
 *  - la adăugarea în coș apare o fereastră cu titlul și textul („Am înțeles”);
 *    conținutul vine în răspunsul JSON al adăugării sau, pe drumul clasic
 *    (POST + redirect), pe pagina /cos — niciodată în HTML-ul paginilor de
 *    produs/categorie, care pot sta în cache (de pagini sau CDN);
 *  - la finalizarea comenzii: caseta cu textul deasupra butonului de plasare și
 *    o bifă obligatorie, verificată pe server, pe coșul de la trimitere, pentru
 *    orice metodă de plată;
 *  - pe comandă rămâne ce a confirmat clientul (titlu, produse, textul exact,
 *    data și ora), într-un tabel separat — `orders` nu se atinge;
 *  - emailul de confirmare a comenzii repetă avertismentul.
 *
 * Avertismentele identice (același titlu și text) apar o singură dată, cu
 * toate produsele lor; etichetele identice dau o singură bifă.
 *
 * Textele se păstrează exact cum au fost scrise și se escapează la fiecare
 * afișare (fereastră, checkout, admin, email): HTML-ul scris în câmpuri apare
 * ca text, nu se interpretează.
 *
 * Oprire de urgență: AVERTISMENT_PRODUSE=0 în .env oprește fereastra, caseta,
 * bifa obligatorie și blocul din email. Datele rămân; adminul le vede în
 * continuare.
 */
final class AvertismentProduse
{
    /** Eticheta bifei când produsul are text, dar câmpul etichetei e gol. */
    public const ETICHETA_IMPLICITA = 'Am citit și am înțeles avertismentul de mai sus.';

    public const MAX_TITLU = 200;
    public const MAX_TEXT = 8000;
    public const MAX_ETICHETA = 300;

    /** Câmpul din formularul de checkout: avertisment_bifa[<cheie>] = 1. */
    public const CAMP_BIFA = 'avertisment_bifa';

    private const VERSIUNE_SCHEMA = '1';
    private const CHEIE_VERSIUNE = 'product_warnings_schema_version';
    private const SESIUNE_DE_ARATAT = 'avertisment_produs_de_aratat';
    private const FUS_ORAR = 'Europe/Bucharest';

    /** Avertismentele citite în cererea curentă, pe id de produs. */
    private static array $cache = [];

    /** Pornit? (oprire: AVERTISMENT_PRODUSE=0 în .env) */
    public static function activ(): bool
    {
        $valoare = strtolower(trim((string) Env::get('AVERTISMENT_PRODUSE', '1')));
        return !in_array($valoare, ['0', 'false', 'off', 'nu'], true);
    }

    /**
     * Cele două tabele, create o singură dată (versiunea stă în `settings`).
     * Se cheamă doar pe drumurile care scriu: salvarea din admin, scriptul și
     * plasarea unei comenzi cu avertisment — și niciodată într-o tranzacție
     * deschisă, fiindcă un CREATE TABLE face commit implicit.
     */
    public static function ensureSchema(PDO $db): void
    {
        static $gata = false;
        if ($gata) {
            return;
        }
        $gata = true;

        try {
            $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = :k LIMIT 1');
            $stmt->execute(['k' => self::CHEIE_VERSIUNE]);
            if ((string) ($stmt->fetchColumn() ?: '') === self::VERSIUNE_SCHEMA) {
                return;
            }
        } catch (Throwable) {
            // Fără tabelul de setări mergem pe drumul lung.
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS product_warnings (
                    product_id INT UNSIGNED NOT NULL PRIMARY KEY,
                    title VARCHAR(255) NOT NULL DEFAULT "",
                    body TEXT DEFAULT NULL,
                    checkbox_label VARCHAR(500) NOT NULL DEFAULT "",
                    updated_at DATETIME NOT NULL
                ) DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable) {
            // Tabelul există deja.
        }
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS order_product_warnings (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    order_id INT UNSIGNED NOT NULL,
                    title VARCHAR(255) NOT NULL DEFAULT "",
                    body TEXT NOT NULL,
                    checkbox_label TEXT NOT NULL,
                    checkbox_ticked TINYINT(1) NOT NULL DEFAULT 1,
                    product_ids_json TEXT NOT NULL,
                    product_names_json TEXT NOT NULL,
                    confirmed_at DATETIME NOT NULL,
                    KEY idx_order_product_warnings_order (order_id)
                ) DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable) {
            // Tabelul există deja.
        }

        try {
            $db->query('SELECT checkbox_label FROM product_warnings LIMIT 0');
            $db->query('SELECT confirmed_at FROM order_product_warnings LIMIT 0');
            $db->prepare(
                'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            )->execute(['k' => self::CHEIE_VERSIUNE, 'v' => self::VERSIUNE_SCHEMA]);
        } catch (Throwable) {
            // Data viitoare încercăm din nou.
        }
    }

    /** Text simplu: rânduri unix, fără spații la capete, tăiat la lungimea maximă. */
    public static function curata(string $text, int $maxim): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);
        if (mb_strlen($text) > $maxim) {
            $text = rtrim(mb_substr($text, 0, $maxim));
        }
        return $text;
    }

    /** Ca curata(), pe un singur rând (titlul și eticheta bifei). */
    public static function curataRand(string $text, int $maxim): string
    {
        return self::curata((string) preg_replace('/[ \t]*[\r\n]+[ \t]*/', ' ', $text), $maxim);
    }

    /**
     * Paragrafele textului: un rând gol (sau doar cu spații) desparte
     * paragrafele; un singur Enter rămâne rând nou în același paragraf.
     *
     * @return list<string>
     */
    public static function paragrafe(string $text): array
    {
        $bucati = preg_split('/\n[ \t]*\n/', str_replace(["\r\n", "\r"], "\n", trim($text))) ?: [];
        $rezultat = [];
        foreach ($bucati as $bucata) {
            $bucata = trim((string) $bucata);
            if ($bucata !== '') {
                $rezultat[] = $bucata;
            }
        }
        return $rezultat;
    }

    /** Paragrafele ca HTML escapat (<p> cu stilul dat; Enter simplu → <br>). */
    public static function paragrafeHtml(string $text, string $stil): string
    {
        $html = '';
        foreach (self::paragrafe($text) as $paragraf) {
            $html .= '<p style="' . $stil . '">'
                . nl2br(htmlspecialchars($paragraf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false)
                . '</p>';
        }
        return $html;
    }

    /**
     * Ce e salvat pentru produsele date (și cele inactive, fără text), pe id.
     * Un tabel lipsă înseamnă „niciun avertisment”.
     *
     * @param list<int> $ids
     * @return array<int, array{titlu: string, text: string, eticheta: string}>
     */
    public static function pentruProduse(?PDO $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if (!$db instanceof PDO || $ids === []) {
            return [];
        }
        $lipsa = array_values(array_filter($ids, static fn (int $id): bool => !array_key_exists($id, self::$cache)));
        if ($lipsa !== []) {
            foreach ($lipsa as $id) {
                self::$cache[$id] = null;
            }
            try {
                $stmt = $db->prepare(
                    'SELECT product_id, title, body, checkbox_label FROM product_warnings
                      WHERE product_id IN (' . implode(',', array_fill(0, count($lipsa), '?')) . ')'
                );
                $stmt->execute($lipsa);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rand) {
                    self::$cache[(int) $rand['product_id']] = [
                        'titlu' => (string) ($rand['title'] ?? ''),
                        'text' => (string) ($rand['body'] ?? ''),
                        'eticheta' => (string) ($rand['checkbox_label'] ?? ''),
                    ];
                }
            } catch (Throwable) {
                // Tabelul încă nu există: niciun produs n-are avertisment.
            }
        }
        $rezultat = [];
        foreach ($ids as $id) {
            if (is_array(self::$cache[$id] ?? null)) {
                $rezultat[$id] = self::$cache[$id];
            }
        }
        return $rezultat;
    }

    /**
     * Doar avertismentele active (text nevid), cu oprirea din .env aplicată.
     *
     * @param list<int> $ids
     * @return array<int, array{titlu: string, text: string, eticheta: string}>
     */
    public static function activePentruProduse(?PDO $db, array $ids): array
    {
        if (!self::activ()) {
            return [];
        }
        return array_filter(
            self::pentruProduse($db, $ids),
            static fn (array $a): bool => trim($a['text']) !== ''
        );
    }

    /**
     * Salvează cele trei câmpuri ale unui produs. Toate goale = rândul dispare.
     */
    public static function salveaza(PDO $db, int $productId, string $titlu, string $text, string $eticheta): bool
    {
        if ($productId <= 0) {
            return false;
        }
        $titlu = self::curataRand($titlu, self::MAX_TITLU);
        $text = self::curata($text, self::MAX_TEXT);
        $eticheta = self::curataRand($eticheta, self::MAX_ETICHETA);
        self::ensureSchema($db);
        try {
            if ($titlu === '' && $text === '' && $eticheta === '') {
                $db->prepare('DELETE FROM product_warnings WHERE product_id = ?')->execute([$productId]);
            } else {
                $db->prepare(
                    'INSERT INTO product_warnings (product_id, title, body, checkbox_label, updated_at)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body),
                         checkbox_label = VALUES(checkbox_label), updated_at = VALUES(updated_at)'
                )->execute([$productId, $titlu, $text, $eticheta, self::acum()->format('Y-m-d H:i:s')]);
            }
        } catch (Throwable) {
            return false;
        }
        unset(self::$cache[$productId]);
        return true;
    }

    // ─────────────────────────────────────────────────────────────
    // Fereastra de la adăugarea în coș
    // ─────────────────────────────────────────────────────────────

    /**
     * Ce primește fereastra pentru produsul abia adăugat, sau null dacă produsul
     * n-are avertisment activ. `script` e scriptul ferestrei, încărcat la cerere.
     *
     * @return array{titlu: string, paragrafe: list<string>, script: string}|null
     */
    public static function pentruFereastra(?PDO $db, int $productId): ?array
    {
        $avertisment = self::activePentruProduse($db, [$productId])[$productId] ?? null;
        if ($avertisment === null) {
            return null;
        }
        return [
            'titlu' => $avertisment['titlu'],
            'paragrafe' => self::paragrafe($avertisment['text']),
            'script' => self::urlScript(),
        ];
    }

    /** Scriptul ferestrei, cu versiunea fișierului (cache-ul browserului îl reîmprospătează singur). */
    public static function urlScript(): string
    {
        $versiune = @filemtime(__DIR__ . '/../../public/assets/js/avertisment-produs.js') ?: 0;
        return '/assets/js/avertisment-produs.js?v=' . $versiune;
    }

    /**
     * Drumul clasic (formular fără JS-ul coșului): adăugarea redirecționează la
     * /cos, deci fereastra se arată acolo. În sesiune stă doar id-ul produsului;
     * textul se citește la afișare.
     */
    public static function tineMinteDeAratat(int $productId): void
    {
        if ($productId <= 0 || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $lista = is_array($_SESSION[self::SESIUNE_DE_ARATAT] ?? null) ? $_SESSION[self::SESIUNE_DE_ARATAT] : [];
        $lista[] = $productId;
        $_SESSION[self::SESIUNE_DE_ARATAT] = array_slice(array_values(array_unique(array_map('intval', $lista))), -5);
    }

    /**
     * Ferestrele de arătat pe pagina curentă (o singură dată: le scoate din sesiune).
     *
     * @return list<array{titlu: string, paragrafe: list<string>, script: string}>
     */
    public static function preiaDeAratat(?PDO $db): array
    {
        $lista = $_SESSION[self::SESIUNE_DE_ARATAT] ?? null;
        if (!is_array($lista) || $lista === []) {
            return [];
        }
        unset($_SESSION[self::SESIUNE_DE_ARATAT]);
        $ferestre = [];
        $vazute = [];
        foreach ($lista as $productId) {
            $fereastra = self::pentruFereastra($db, (int) $productId);
            if ($fereastra === null) {
                continue;
            }
            $cheie = sha1($fereastra['titlu'] . "\0" . implode("\n\n", $fereastra['paragrafe']));
            if (!isset($vazute[$cheie])) {
                $vazute[$cheie] = true;
                $ferestre[] = $fereastra;
            }
        }
        return $ferestre;
    }

    // ─────────────────────────────────────────────────────────────
    // Finalizarea comenzii
    // ─────────────────────────────────────────────────────────────

    /**
     * Ce cere coșul la finalizarea comenzii, în ordinea coșului:
     *  - `avertismente`: casetele, câte una pe avertisment distinct (titlu +
     *    text), fiecare cu toate produsele lui și etichetele bifelor lor;
     *  - `bife`: câte o bifă pe etichetă distinctă; `dupa` = indexul ultimei
     *    casete pe care o confirmă (bifa se arată imediat sub ea).
     * Ofertele BBD ale aceluiași produs contează o dată. Coș fără avertismente
     * active = [].
     *
     * `cheie` identifică bifa în formular și cuprinde eticheta, textele
     * casetelor pe care le confirmă și produsele din ele: dacă între afișare
     * și trimitere se schimbă ceva (alt produs adăugat din alt tab, unul scos,
     * text modificat din admin), bifa veche nu mai trece și clientul vede din
     * nou caseta. Așa, pe comandă rămân exact produsele din caseta bifată.
     *
     * @param list<array<string, mixed>> $linii liniile din CheckoutCalculator::buildSummary
     * @return array{}|array{
     *     avertismente: list<array{titlu: string, text: string, produse: list<string>, produse_ids: list<int>, etichete: list<string>}>,
     *     bife: list<array{cheie: string, eticheta: string, dupa: int}>
     * }
     */
    public static function pentruCos(?PDO $db, array $linii): array
    {
        $ids = [];
        foreach ($linii as $linie) {
            if (is_array($linie) && (int) ($linie['id'] ?? 0) > 0) {
                $ids[] = (int) $linie['id'];
            }
        }
        $active = self::activePentruProduse($db, $ids);
        if ($active === []) {
            return [];
        }

        $avertismente = [];
        $vazute = [];
        foreach ($linii as $linie) {
            $id = is_array($linie) ? (int) ($linie['id'] ?? 0) : 0;
            if (!isset($active[$id]) || isset($vazute[$id])) {
                continue;
            }
            $vazute[$id] = true;
            $a = $active[$id];
            $eticheta = trim($a['eticheta']) !== '' ? $a['eticheta'] : self::ETICHETA_IMPLICITA;
            $cheieText = sha1($a['titlu'] . "\0" . $a['text']);
            $avertismente[$cheieText] ??= [
                'titlu' => $a['titlu'],
                'text' => $a['text'],
                'produse' => [],
                'produse_ids' => [],
                'etichete' => [],
            ];
            $nume = trim((string) ($linie['name'] ?? ''));
            $avertismente[$cheieText]['produse'][] = $nume !== '' ? $nume : ('Produsul #' . $id);
            $avertismente[$cheieText]['produse_ids'][] = $id;
            if (!in_array($eticheta, $avertismente[$cheieText]['etichete'], true)) {
                $avertismente[$cheieText]['etichete'][] = $eticheta;
            }
        }

        // O bifă pe etichetă: ce casete confirmă și sub care se arată.
        $bife = [];
        foreach (array_values($avertismente) as $index => $a) {
            $idsCaseta = $a['produse_ids'];
            sort($idsCaseta);
            $amprenta = sha1($a['titlu'] . "\0" . $a['text']) . ':' . implode(',', $idsCaseta);
            foreach ($a['etichete'] as $eticheta) {
                $bife[$eticheta] ??= ['eticheta' => $eticheta, 'casete' => []];
                $bife[$eticheta]['casete'][] = $amprenta;
                $bife[$eticheta]['dupa'] = $index;
            }
        }
        $listaBife = [];
        foreach ($bife as $bifa) {
            $casete = $bifa['casete'];
            sort($casete);
            $listaBife[] = [
                'cheie' => substr(sha1($bifa['eticheta'] . "\0" . implode('|', $casete)), 0, 16),
                'eticheta' => $bifa['eticheta'],
                'dupa' => $bifa['dupa'],
            ];
        }
        return ['avertismente' => array_values($avertismente), 'bife' => $listaBife];
    }

    /** Cheile bifelor cerute (pentru sincronizarea casetei de pe /checkout). */
    public static function cheiBife(array $cos): array
    {
        return array_values(array_column((array) ($cos['bife'] ?? []), 'cheie'));
    }

    /**
     * Verificarea de la trimiterea comenzii: fiecare bifă cerută de coșul de
     * ACUM trebuie să vină bifată. Întoarce mesajul de eroare sau null.
     */
    public static function verificaBifele(array $cos, array $post): ?string
    {
        if ($cos === []) {
            return null;
        }
        $bife = is_array($post[self::CAMP_BIFA] ?? null) ? $post[self::CAMP_BIFA] : [];
        $ceruteAcum = self::cheiBife($cos);
        foreach ($cos['bife'] as $bifa) {
            if ((string) ($bife[$bifa['cheie']] ?? '') === '1') {
                continue;
            }
            if (array_diff(array_map('strval', array_keys($bife)), $ceruteAcum) !== []) {
                // A bifat o casetă pe care coșul de acum n-o mai are (sau o are altfel).
                return 'Avertismentul pentru produsele din coș s-a schimbat între timp. '
                    . 'Citește-l din nou, deasupra butonului de plasare, și bifează „' . $bifa['eticheta'] . '”.';
            }
            return 'Pentru a plasa comanda, citește avertismentul de deasupra butonului de plasare '
                . 'și bifează „' . $bifa['eticheta'] . '”.';
        }
        return null;
    }

    /**
     * Ce a confirmat clientul, pe comandă: câte un rând pe avertisment (casetă),
     * cu toate produsele lui și etichetele bifate (câte una pe rând). Se cheamă
     * în tranzacția comenzii (tabelul e creat înainte, cu ensureSchema).
     */
    public static function salveazaConfirmari(PDO $db, int $orderId, array $cos, ?DateTimeImmutable $moment = null): void
    {
        if ($orderId <= 0 || $cos === []) {
            return;
        }
        $momentul = ($moment ?? self::acum())->setTimezone(new DateTimeZone(self::FUS_ORAR))->format('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'INSERT INTO order_product_warnings
                (order_id, title, body, checkbox_label, checkbox_ticked, product_ids_json, product_names_json, confirmed_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)'
        );
        foreach ($cos['avertismente'] as $a) {
            $stmt->execute([
                $orderId,
                $a['titlu'],
                $a['text'],
                implode("\n", $a['etichete']),
                json_encode(array_values($a['produse_ids'])),
                json_encode(array_values($a['produse']), JSON_UNESCAPED_UNICODE),
                $momentul,
            ]);
        }
    }

    /**
     * Confirmările salvate pe comenzi, pe id de comandă.
     *
     * @param list<int> $orderIds
     * @return array<int, list<array{titlu: string, text: string, etichete: list<string>, bifat: bool, produse: list<string>, confirmat_la: string}>>
     */
    public static function confirmariComenzi(?PDO $db, array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn (int $id): bool => $id > 0)));
        if (!$db instanceof PDO || $orderIds === []) {
            return [];
        }
        try {
            $stmt = $db->prepare(
                'SELECT order_id, title, body, checkbox_label, checkbox_ticked, product_names_json, confirmed_at
                   FROM order_product_warnings
                  WHERE order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')
                  ORDER BY id ASC'
            );
            $stmt->execute($orderIds);
            $randuri = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            // Tabelul încă nu există: nicio comandă n-are confirmări.
            return [];
        }
        $rezultat = [];
        foreach ($randuri as $rand) {
            $produse = json_decode((string) ($rand['product_names_json'] ?? '[]'), true);
            $rezultat[(int) $rand['order_id']][] = [
                'titlu' => (string) ($rand['title'] ?? ''),
                'text' => (string) ($rand['body'] ?? ''),
                // Una sau mai multe etichete, câte una pe rând.
                'etichete' => array_values(array_filter(
                    array_map('trim', explode("\n", (string) ($rand['checkbox_label'] ?? ''))),
                    static fn (string $e): bool => $e !== ''
                )),
                'bifat' => (int) ($rand['checkbox_ticked'] ?? 0) === 1,
                'produse' => is_array($produse) ? array_values(array_map('strval', $produse)) : [],
                'confirmat_la' => (string) ($rand['confirmed_at'] ?? ''),
            ];
        }
        return $rezultat;
    }

    /** „20.10.2026 16:44” din valoarea salvată (deja în ora României). */
    public static function dataAfisata(string $valoare): string
    {
        $ts = strtotime($valoare);
        return $ts === false ? $valoare : date('d.m.Y H:i', $ts);
    }

    /**
     * Pentru fereastra comenzii din admin: rândul scurt („Avertisment confirmat
     * de client: «…» (produse) — data”) plus textul și etichetele bifate.
     *
     * @return list<array{linie: string, text: string, etichete: list<string>}>
     */
    public static function pentruAdmin(array $confirmari): array
    {
        $rezultat = [];
        foreach ($confirmari as $c) {
            $titlu = trim($c['titlu']) !== '' ? $c['titlu'] : '(fără titlu)';
            $rezultat[] = [
                'linie' => 'Avertisment confirmat de client: «' . $titlu . '»'
                    . ($c['produse'] !== [] ? ' (' . implode(', ', $c['produse']) . ')' : '')
                    . ' — ' . self::dataAfisata($c['confirmat_la'])
                    . ($c['bifat'] ? '' : ' — NEBIFAT'),
                'text' => $c['text'],
                'etichete' => $c['etichete'],
            ];
        }
        return $rezultat;
    }

    /**
     * Blocul din emailul de confirmare a comenzii: avertismentele confirmate,
     * fiecare o dată, cu produsele lui; după ultima casetă a fiecărei bife,
     * rândul cu ce a bifat clientul (o dată pe etichetă). Gol dacă comanda
     * n-are avertismente sau dacă e oprit din .env.
     */
    public static function htmlEmail(array $confirmari): string
    {
        if (!self::activ() || $confirmari === []) {
            return '';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $confirmari = array_values($confirmari);
        $ultimaCaseta = [];
        foreach ($confirmari as $index => $c) {
            foreach ($c['etichete'] as $eticheta) {
                $ultimaCaseta[$eticheta] = $index;
            }
        }
        $html = '';
        foreach ($confirmari as $index => $c) {
            $html .= '<div style="margin:16px 0 8px;padding:14px 16px;border:1px solid #fca5a5;border-left:4px solid #dc2626;border-radius:10px;background:#fef2f2;">';
            if (trim($c['titlu']) !== '') {
                $html .= '<p style="margin:0 0 4px;color:#7f1d1d;font-size:16px;font-weight:700;line-height:1.35;">' . $e($c['titlu']) . '</p>';
            }
            if ($c['produse'] !== []) {
                $html .= '<p style="margin:0 0 10px;color:#991b1b;font-size:13px;line-height:1.4;">Pentru: ' . $e(implode(', ', $c['produse'])) . '</p>';
            }
            $html .= self::paragrafeHtml($c['text'], 'margin:0 0 8px;color:#1f2937;font-size:14px;line-height:1.55;');
            $html .= '</div>';
            foreach ($ultimaCaseta as $eticheta => $ultima) {
                if ($ultima !== $index) {
                    continue;
                }
                $html .= '<p style="margin:0 0 16px;color:#475569;font-size:13px;line-height:1.5;">Ai confirmat la plasarea comenzii'
                    . ($c['confirmat_la'] !== '' ? ' (' . $e(self::dataAfisata($c['confirmat_la'])) . ')' : '')
                    . ': „' . $e((string) $eticheta) . '”.</p>';
            }
        }
        return $html;
    }

    private static function acum(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::FUS_ORAR));
    }
}
