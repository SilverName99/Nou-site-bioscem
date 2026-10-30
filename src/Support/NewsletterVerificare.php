<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Lista de verificare dinaintea trimiterii unui newsletter (pasul „Verificare”
 * din builder): subiect, link de dezabonare, câți abonați îl primesc, linkuri
 * și poze stricate, expeditor, cron.
 *
 * Fiecare punct are o stare:
 *  - „ok”: în regulă;
 *  - „avertisment”: merită o privire, dar nu oprește trimiterea;
 *  - „eroare”: newsletterul n-ar trebui să plece așa. Butoanele „Trimite” și
 *    „Programează” rămân blocate până se repară.
 *
 * Textele sunt pentru cineva fără cunoștințe tehnice: spun ce nu e bine și ce
 * are de făcut, nu cum se numește problema.
 */
final class NewsletterVerificare
{
    /** Gmail ascunde restul unui email mai mare de atât („Mesaj trunchiat”). */
    private const PRAG_GMAIL_OCTETI = 102 * 1024;

    /** Câte adrese din afara site-ului se verifică pe internet, cel mult. */
    private const MAX_LINKURI_EXTERNE = 15;

    /** Texte rămase din șabloane, care n-ar trebui să ajungă la abonați. */
    private const TEXTE_DE_INLOCUIT = [
        'Scrie textul tău aici',
        'Scrie conținutul',
        'Titlul tău',
        'Titlul email-ului',
        'Titlul Newsletter-ului',
        'Scrie citatul tău',
        'Lorem ipsum',
        'Scrie aici',
        'Titlul anunțului',
        'Numele clientului',
        'Un citat scurt',
        'TOAMNA20',
        'Numele produsului',
    ];

    /**
     * @param array $blocks Blocurile din builder (nesalvate încă, exact ce se vede).
     * @param int[] $listIds Listele alese la pasul „Destinatari”.
     * @return array{puncte: list<array{cheie:string,stare:string,titlu:string,text:string,detalii:list<string>}>, destinatari:int, blocheaza:bool, erori:int, avertismente:int}
     */
    public static function verifica(
        PDO $db,
        array $settings,
        array $blocks,
        string $subject,
        array $listIds,
        bool $verificaPeInternet = true
    ): array {
        $puncte = [];
        $subject = trim($subject);
        $normalizate = NewsletterService::normalizeBlocks($blocks);
        $html = NewsletterService::renderHtmlFromBlocks($normalizate, $subject);

        // 1. Subiectul
        $lung = mb_strlen($subject);
        if ($subject === '') {
            $puncte[] = self::punct('subiect', 'eroare', 'Subiectul lipsește', 'Scrie la pasul „Conținut” ce vor vedea abonații în inbox, înainte să deschidă emailul.');
        } elseif (in_array(mb_strtolower($subject), ['newsletter', 'subiect', 'test'], true)) {
            $puncte[] = self::punct('subiect', 'avertisment', 'Subiectul e prea general', '„' . $subject . '” nu spune nimic abonaților. Un subiect concret (de exemplu „Reduceri de toamnă la ceaiuri”) e deschis mai des.');
        } elseif ($lung > 90) {
            $puncte[] = self::punct('subiect', 'avertisment', 'Subiectul e lung (' . $lung . ' de caractere)', 'Pe telefon se vede doar începutul (cam 40–50 de caractere). Pune ce e important la început.');
        } elseif (preg_match('/!{2,}/u', $subject) || (mb_strlen($subject) > 8 && mb_strtoupper($subject) === $subject && preg_match('/\p{L}/u', $subject))) {
            $puncte[] = self::punct('subiect', 'avertisment', 'Subiectul poate ajunge în Spam', 'Majusculele peste tot sau semnele „!!” sunt semnale de spam pentru Gmail și Yahoo.');
        } else {
            $puncte[] = self::punct('subiect', 'ok', 'Subiect: „' . $subject . '”', '');
        }

        // 2. Linkul de dezabonare (obligatoriu prin lege, GDPR)
        $baseUrl = NewsletterService::appBaseUrl();
        $configurat = '';
        try {
            $config = require __DIR__ . '/../../config/app.php';
            $configurat = trim((string) ($config['url'] ?? ''));
        } catch (Throwable) {
        }
        if (!str_contains($html, 'newsletter-unsubscribe-footer:start')) {
            $puncte[] = self::punct('dezabonare', 'eroare', 'Lipsește linkul de dezabonare', 'Fără el newsletterul nu are voie să plece. Anunță programatorul.');
        } elseif ($configurat === '') {
            $puncte[] = self::punct('dezabonare', 'eroare', 'Adresa site-ului nu e setată', 'În fișierul .env de pe server lipsește APP_URL. Fără ea, linkul de dezabonare din emailurile trimise de cron nu funcționează. Anunță programatorul.');
        } else {
            $puncte[] = self::punct('dezabonare', 'ok', 'Linkul de dezabonare e pus automat', 'Fiecare abonat primește, jos, linkul lui personal „Dezabonează-mă”. În previzualizare și în testul trimis ție linkul e inactiv.');
        }

        // 3. Destinatarii
        $listIds = array_values(array_filter(array_map('intval', $listIds), fn($v) => $v > 0));
        $destinatari = $listIds === [] ? 0 : NewsletterService::totalRecipients($db, $listIds);
        if ($listIds === []) {
            $puncte[] = self::punct('destinatari', 'eroare', 'Nu ai ales nicio listă', 'Alege la pasul „Destinatari” cui trimiți newsletterul.');
        } elseif ($destinatari === 0) {
            $puncte[] = self::punct('destinatari', 'eroare', 'Listele alese nu au niciun abonat activ', 'Alege altă listă sau adaugă abonați.');
        } else {
            $puncte[] = self::punct('destinatari', 'ok', self::numar($destinatari, 'abonat activ', 'abonați activi') . ' vor primi newsletterul', 'Cine e în mai multe liste îl primește o singură dată. Dezabonații nu-l primesc.');
        }

        // 4. Expeditorul și trimiterea
        $from = trim((string) ($settings['order_email_from_address'] ?? ''));
        $metoda = strtolower(trim((string) ($settings['email_delivery_method'] ?? 'smtp')));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || str_ends_with($from, '@localhost')) {
            $puncte[] = self::punct('expeditor', 'eroare', 'Adresa expeditorului nu e completată', 'Completeaz-o în Email-uri → Setări trimitere („From Email”).');
        } elseif ($metoda === 'smtp' && trim((string) ($settings['smtp_host'] ?? '')) === '') {
            $puncte[] = self::punct('expeditor', 'avertisment', 'Serverul de email (SMTP) nu e setat', 'Emailurile pleacă prin metoda de rezervă a serverului, pe care multe găzduiri le aruncă fără să spună. Completează SMTP în Email-uri → Setări trimitere.');
        } else {
            $puncte[] = self::punct('expeditor', 'ok', 'Expeditor: ' . $from, '');
        }

        // 5. Cronul (fără el, programările și listele mari nu pleacă)
        $ultimaRulare = trim((string) ($settings['newsletter_cron_ultima_rulare'] ?? ''));
        $ts = $ultimaRulare !== '' ? strtotime($ultimaRulare) : false;
        if ($ts === false) {
            $puncte[] = self::punct('cron', 'avertisment', 'Nu știu dacă trimiterea automată (cronul) merge', 'Cronul de newsletter n-a raportat încă nicio rulare. Fără el, newsletterele programate nu pleacă, iar listele mari se opresc după primele câteva sute de emailuri.');
        } elseif ($ts < time() - 20 * 60) {
            $puncte[] = self::punct('cron', 'avertisment', 'Trimiterea automată (cronul) nu a mai rulat din ' . date('d.m.Y, H:i', $ts), 'Ar trebui să ruleze la fiecare 5 minute. Fără el, programările nu pleacă. Anunță programatorul.');
        } else {
            $puncte[] = self::punct('cron', 'ok', 'Trimiterea automată merge', 'Ultima rulare: ' . date('H:i', $ts) . '.');
        }

        // 6. Linkurile și pozele
        [$punctLinkuri, $punctPoze] = self::verificaLinkuriSiPoze($db, $html, $normalizate, $baseUrl, $verificaPeInternet);
        $puncte[] = $punctLinkuri;
        $puncte[] = $punctPoze;

        // 7. Texte rămase din șablon
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ramase = [];
        foreach (self::TEXTE_DE_INLOCUIT as $exemplu) {
            if (mb_stripos($text, $exemplu) !== false) {
                $ramase[] = '„' . $exemplu . '…”';
            }
        }
        if (preg_match_all('/\{\{\s*[a-z_]+\s*\}\}/i', $text, $tokenuri)) {
            foreach (array_unique($tokenuri[0]) as $token) {
                $ramase[] = $token . ' (se completează doar în emailurile de comandă, nu în newsletter)';
            }
        }
        if ($ramase !== []) {
            $puncte[] = self::punct('texte', 'avertisment', 'Au rămas texte de exemplu din șablon', 'Înlocuiește-le cu textul tău:', $ramase);
        } else {
            $puncte[] = self::punct('texte', 'ok', 'Nu au rămas texte de exemplu', '');
        }

        // 8. Mărimea
        $octeti = strlen($html);
        if ($octeti > self::PRAG_GMAIL_OCTETI) {
            $puncte[] = self::punct('marime', 'avertisment', 'Emailul e mare (' . round($octeti / 1024) . ' KB)', 'Gmail ascunde ce trece de 102 KB în spatele unui link „Afișează tot mesajul”, iar linkul de dezabonare e chiar la final. Scurtează textul sau scoate câteva blocuri.');
        }

        $erori = count(array_filter($puncte, fn($p) => $p['stare'] === 'eroare'));
        $avertismente = count(array_filter($puncte, fn($p) => $p['stare'] === 'avertisment'));

        return [
            'puncte' => $puncte,
            'destinatari' => $destinatari,
            'blocheaza' => $erori > 0,
            'erori' => $erori,
            'avertismente' => $avertismente,
        ];
    }

    /**
     * Ce oprește trimiterea și pe server, chiar dacă cineva ocolește pagina:
     * fără subiect, fără abonați, fără adresa site-ului (linkul de dezabonare).
     * Restul punctelor rămân doar în pagină.
     */
    public static function motivBlocare(PDO $db, array $campaign): string
    {
        if (trim((string) ($campaign['subject'] ?? '')) === '') {
            return 'Newsletterul nu are subiect.';
        }
        $listIds = NewsletterService::campaignListIds($campaign);
        if ($listIds === []) {
            return 'Newsletterul nu are nicio listă de abonați aleasă.';
        }
        if (NewsletterService::totalRecipients($db, $listIds) === 0) {
            return 'Listele alese nu au niciun abonat activ.';
        }
        return '';
    }

    private static function verificaLinkuriSiPoze(PDO $db, string $html, array $blocks, string $baseUrl, bool $peInternet): array
    {
        $gazdaSite = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?? ''));
        $radacinaPublica = realpath(__DIR__ . '/../../public') ?: '';

        // Linkurile din email (fără subsolul de dezabonare, care e al nostru).
        $faraSubsol = NewsletterService::faraFooterDezabonare($html);
        preg_match_all('/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si', $faraSubsol, $potriviri, PREG_SET_ORDER);
        $probleme = [];
        $avertismente = [];
        $externe = [];
        $total = 0;
        foreach ($potriviri as $m) {
            $href = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $eticheta = trim(preg_replace('/\s+/', ' ', strip_tags($m[2])) ?? '');
            if ($eticheta === '' && preg_match('/alt=["\']([^"\']*)["\']/i', $m[2], $alt)) {
                $eticheta = 'poza „' . trim($alt[1]) . '”';
            }
            $nume = $eticheta !== '' ? '„' . mb_substr($eticheta, 0, 40) . '”' : 'un link';
            $total++;
            if ($href === '' || $href === '#') {
                $probleme[] = $nume . ' nu duce nicăieri (adresa lipsește).';
                continue;
            }
            if (preg_match('/^(mailto|tel):/i', $href)) {
                continue;
            }
            if (preg_match('/\{\{.*\}\}/', $href)) {
                $probleme[] = $nume . ' are în adresă un cod {{…}} care nu se completează în newsletter.';
                continue;
            }
            if (!preg_match('#^https?://#i', $href)) {
                $probleme[] = $nume . ' are o adresă care nu începe cu https:// („' . mb_substr($href, 0, 60) . '”).';
                continue;
            }
            $gazda = strtolower((string) (parse_url($href, PHP_URL_HOST) ?? ''));
            if ($gazda === '' || !str_contains($gazda, '.') && $gazda !== 'localhost' && !filter_var($gazda, FILTER_VALIDATE_IP)) {
                $probleme[] = $nume . ' are o adresă greșită („' . mb_substr($href, 0, 60) . '”).';
                continue;
            }
            if ($gazdaSite !== '' && $gazda === $gazdaSite) {
                $problema = self::verificaLinkIntern($db, $href);
                if ($problema !== '') {
                    $probleme[] = $nume . ' ' . $problema;
                }
                continue;
            }
            if (str_starts_with(strtolower($href), 'http://')) {
                $avertismente[] = $nume . ' folosește http:// (nesigur); dacă se poate, pune https://.';
            }
            $externe[$href] = $nume;
        }

        // Pozele: cele din blocuri fără adresă nu apar deloc în email.
        preg_match_all('/<img\s[^>]*src=["\']([^"\']*)["\'][^>]*>/si', $faraSubsol, $poze, PREG_SET_ORDER);
        $problemePoze = [];
        $avertismentePoze = [];
        $externePoze = [];
        $totalPoze = 0;
        foreach ($poze as $m) {
            $src = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $totalPoze++;
            if (preg_match('/alt=["\']\s*["\']/', $m[0]) && !str_contains($src, '/assets/img/social/')) {
                $avertismentePoze[] = 'O poză nu are „text alternativ” (îl văd cei cu pozele blocate): ' . basename((string) parse_url($src, PHP_URL_PATH));
            }
            $gazda = strtolower((string) (parse_url($src, PHP_URL_HOST) ?? ''));
            $cale = (string) (parse_url($src, PHP_URL_PATH) ?? '');
            if ($gazdaSite !== '' && $gazda === $gazdaSite && $radacinaPublica !== '') {
                $fisier = realpath($radacinaPublica . '/' . ltrim(rawurldecode($cale), '/'));
                if ($fisier === false || !is_file($fisier) || !str_starts_with($fisier, $radacinaPublica . DIRECTORY_SEPARATOR)) {
                    $problemePoze[] = 'Poza ' . basename($cale) . ' nu există pe site (a fost ștearsă sau mutată).';
                }
                continue;
            }
            if (!preg_match('#^https?://#i', $src)) {
                $problemePoze[] = 'O poză are o adresă greșită: „' . mb_substr($src, 0, 60) . '”.';
                continue;
            }
            $externePoze[$src] = 'Poza ' . basename($cale);
        }
        foreach ($blocks as $bloc) {
            if (($bloc['type'] ?? '') === 'image' && trim((string) ($bloc['image_url'] ?? '')) === '') {
                $avertismentePoze[] = 'Un bloc „Imagine” nu are poză aleasă, așa că nu apare în email.';
            }
        }

        // Adresele din afara site-ului: o întrebare scurtă, în paralel.
        if ($peInternet) {
            $raspunsuri = self::verificaPeInternet(array_slice(array_keys($externe + $externePoze), 0, self::MAX_LINKURI_EXTERNE));
            foreach ($raspunsuri as $adresa => $cod) {
                $nume = $externe[$adresa] ?? $externePoze[$adresa] ?? $adresa;
                $estePoza = !isset($externe[$adresa]);
                if ($cod === 404 || $cod === 410) {
                    if ($estePoza) {
                        $problemePoze[] = $nume . ' nu mai există la adresa ei (eroare ' . $cod . ').';
                    } else {
                        $probleme[] = $nume . ' duce la o pagină care nu există (eroare ' . $cod . ').';
                    }
                } elseif ($cod >= 500 || $cod === 0) {
                    $mesaj = $nume . ' nu a putut fi verificat acum (' . ($cod === 0 ? 'fără răspuns' : 'eroare ' . $cod) . '); deschide-l din testul trimis ție.';
                    if ($estePoza) {
                        $avertismentePoze[] = $mesaj;
                    } else {
                        $avertismente[] = $mesaj;
                    }
                }
            }
        }

        $punctLinkuri = $probleme !== []
            ? self::punct('linkuri', 'eroare', self::numar(count($probleme), 'link stricat', 'linkuri stricate'), 'Repară-le la pasul „Conținut” (selectează blocul și completează adresa):', array_merge($probleme, $avertismente))
            : ($avertismente !== []
                ? self::punct('linkuri', 'avertisment', 'Linkurile merg, cu mici observații', '', $avertismente)
                : self::punct('linkuri', 'ok', $total === 0 ? 'Emailul nu are linkuri' : 'Toate cele ' . self::numar($total, 'link', 'linkuri') . ' au adresă bună', $total === 0 ? 'Un buton spre magazin aduce de obicei mai multe vizite.' : ''));
        $punctPoze = $problemePoze !== []
            ? self::punct('poze', 'eroare', self::numar(count($problemePoze), 'poză stricată', 'poze stricate'), 'Alege din nou poza la pasul „Conținut”:', array_values(array_unique(array_merge($problemePoze, $avertismentePoze))))
            : ($avertismentePoze !== []
                ? self::punct('poze', 'avertisment', 'Pozele merg, cu mici observații', '', array_values(array_unique($avertismentePoze)))
                : self::punct('poze', 'ok', $totalPoze === 0 ? 'Emailul nu are poze' : 'Toate pozele se încarcă', ''));

        return [$punctLinkuri, $punctPoze];
    }

    /** Un link spre propriul site: produsele șterse sau ascunse dau 404. */
    private static function verificaLinkIntern(PDO $db, string $href): string
    {
        $cale = rawurldecode((string) (parse_url($href, PHP_URL_PATH) ?? '/'));
        if (preg_match('#^/produs/([^/]+)/?$#', $cale, $m)) {
            try {
                $stmt = $db->prepare('SELECT is_active, deleted_at FROM products WHERE slug = :slug LIMIT 1');
                $stmt->execute(['slug' => $m[1]]);
                $produs = $stmt->fetch();
                if (!is_array($produs)) {
                    return 'duce la un produs care nu (mai) există pe site.';
                }
                if ((int) ($produs['is_active'] ?? 1) !== 1 || !empty($produs['deleted_at'])) {
                    return 'duce la un produs ascuns sau șters (vizitatorii văd „pagina nu există”).';
                }
            } catch (Throwable) {
            }
        }
        return '';
    }

    /**
     * Cere scurt fiecare adresă (HEAD, 4 secunde în total, în paralel) și
     * întoarce codul HTTP; 0 = fără răspuns. Doar pentru adrese din afara
     * site-ului, din admin, la cerere: nu pleacă nimic către abonați.
     *
     * @param list<string> $adrese
     * @return array<string,int>
     */
    private static function verificaPeInternet(array $adrese): array
    {
        if ($adrese === [] || !function_exists('curl_multi_init')) {
            return [];
        }
        $multi = curl_multi_init();
        $manere = [];
        foreach ($adrese as $adresa) {
            $ch = curl_init($adresa);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (verificare newsletter)',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            curl_multi_add_handle($multi, $ch);
            $manere[$adresa] = $ch;
        }
        $pornit = microtime(true);
        do {
            $stare = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 0.2);
            }
        } while ($active && $stare === CURLM_OK && microtime(true) - $pornit < 5);

        $coduri = [];
        foreach ($manere as $adresa => $ch) {
            $cod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            // Unele servere refuză HEAD (405/403/501) dar pagina merge: nu e o problemă.
            if (in_array($cod, [403, 405, 501], true)) {
                $cod = 200;
            }
            $coduri[$adresa] = $cod;
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $coduri;
    }

    private static function punct(string $cheie, string $stare, string $titlu, string $text, array $detalii = []): array
    {
        return [
            'cheie' => $cheie,
            'stare' => $stare,
            'titlu' => $titlu,
            'text' => $text,
            'detalii' => array_values($detalii),
        ];
    }

    private static function numar(int $n, string $singular, string $plural): string
    {
        if ($n === 1) {
            return '1 ' . $singular;
        }
        // „20 de abonați”: de la 20 în sus, cu „de” (regula din română).
        $deLa = $n % 100;
        return $n . ($n >= 20 && ($deLa === 0 || $deLa >= 20) ? ' de ' : ' ') . $plural;
    }
}
