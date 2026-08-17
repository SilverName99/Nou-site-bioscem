<?php

declare(strict_types=1);

/**
 * Cron pentru newsletter. Rulează des (din 5 în 5 minute e suficient) și face
 * două lucruri, în ordinea asta:
 *
 *  1. continuă campaniile rămase în curs — o listă de zeci de mii de abonați nu
 *     pleacă dintr-o singură execuție PHP, așa că trimiterea merge pe bucăți și
 *     fiecare rulare o duce mai departe de unde a rămas;
 *  2. pornește campaniile programate care au ajuns la scadență.
 *
 * `--seconds=` ține rularea sub limita de execuție a serverului: la expirare se
 * oprește curat, iar ce a rămas pleacă la următoarea trecere.
 *
 * Exemplu de cron (Hostinger), la fiecare 5 minute:
 *   /usr/bin/php /home/USER/domains/bioscem.ro/public_html/scripts/newsletter-campaigns.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\NewsletterService;
use App\Support\Settings;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibila.\n");
    exit(1);
}

$limit = 20;        // câte campanii programate se pornesc într-o trecere
$perRun = 2000;     // câți destinatari, cel mult, per campanie per trecere
$seconds = 240;     // bugetul de timp al rulării

foreach ((array) ($argv ?? []) as $argument) {
    $argument = (string) $argument;
    if (str_starts_with($argument, '--limit=')) {
        $candidate = (int) substr($argument, 8);
        if ($candidate > 0 && $candidate <= 200) {
            $limit = $candidate;
        }
    }
    if (str_starts_with($argument, '--per-run=')) {
        $candidate = (int) substr($argument, 10);
        if ($candidate > 0 && $candidate <= 50000) {
            $perRun = $candidate;
        }
    }
    if (str_starts_with($argument, '--seconds=')) {
        $candidate = (int) substr($argument, 10);
        if ($candidate > 0 && $candidate <= 3600) {
            $seconds = $candidate;
        }
    }
}

// Două rulări suprapuse ar trimite de două ori acelorași oameni în fereastra
// dintre selecție și marcarea ca trimis. Lacătul e pe fișier: dacă rularea
// precedentă încă lucrează, aceasta iese fără să facă nimic.
$lockPath = dirname(__DIR__) . '/storage/newsletter-cron.lock';
$lock = @fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Newsletter cron: ruleaza deja, ies.\n";
    exit(0);
}

@set_time_limit(0);
$deadline = time() + $seconds;

NewsletterService::ensureSchema($db);
$settings = Settings::all($db);

$continuate = NewsletterService::continueRunningCampaigns($db, $settings, $perRun, $deadline);
$programate = NewsletterService::sendDueScheduledCampaigns($db, $settings, $limit, $deadline);

flock($lock, LOCK_UN);
fclose($lock);

echo 'Newsletter cron: ';
echo 'reluate=' . (int) ($continuate['resumed_campaigns'] ?? 0) . ', ';
echo 'terminate=' . (int) ($continuate['finished_campaigns'] ?? 0) . ', ';
echo 'programate_pornite=' . (int) ($programate['processed_campaigns'] ?? 0) . ', ';
echo 'trimise=' . ((int) ($continuate['sent'] ?? 0) + (int) ($programate['sent'] ?? 0)) . ', ';
echo 'esuate=' . ((int) ($continuate['failed'] ?? 0) + (int) ($programate['failed'] ?? 0)) . "\n";
