<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\EmailAutomation;
use App\Support\Settings;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibila.\n");
    exit(1);
}

$limit = 100;
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $argument) {
        if (str_starts_with((string) $argument, '--limit=')) {
            $candidate = (int) substr((string) $argument, 8);
            if ($candidate > 0 && $candidate <= 1000) {
                $limit = $candidate;
            }
        }
    }
}

EmailAutomation::ensureSchema($db);
$settings = Settings::all($db);
$result = EmailAutomation::sendDueAbandonedCartEmails($db, $settings, $limit);

echo 'Abandoned cart email sync: ';
echo 'total=' . (int) ($result['total'] ?? 0) . ', ';
echo 'sent=' . (int) ($result['sent'] ?? 0) . ', ';
echo 'failed=' . (int) ($result['failed'] ?? 0) . "\n";
