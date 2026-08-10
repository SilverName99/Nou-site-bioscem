<?php

declare(strict_types=1);

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

$limit = 20;
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $argument) {
        if (str_starts_with((string) $argument, '--limit=')) {
            $candidate = (int) substr((string) $argument, 8);
            if ($candidate > 0 && $candidate <= 200) {
                $limit = $candidate;
            }
        }
    }
}

NewsletterService::ensureSchema($db);
$settings = Settings::all($db);
$result = NewsletterService::sendDueScheduledCampaigns($db, $settings, $limit);

echo 'Newsletter cron: ';
echo 'processed_campaigns=' . (int) ($result['processed_campaigns'] ?? 0) . ', ';
echo 'sent=' . (int) ($result['sent'] ?? 0) . ', ';
echo 'failed=' . (int) ($result['failed'] ?? 0) . "\n";
