<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\FanCourierGateway;
use App\Support\Settings;
use RuntimeException;
use Throwable;

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

try {
    $db->exec('ALTER TABLE orders ADD COLUMN fan_tracking_status VARCHAR(190) DEFAULT NULL AFTER fan_tracking_url');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN fan_tracking_last_event_at DATETIME DEFAULT NULL AFTER fan_tracking_status');
} catch (Throwable) {
}
try {
    $db->exec('ALTER TABLE orders ADD COLUMN fan_tracking_synced_at DATETIME DEFAULT NULL AFTER fan_tracking_last_event_at');
} catch (Throwable) {
}

$settings = Settings::all($db);
$clientId = (int) ($settings['fan_client_id'] ?? 0);
$username = trim((string) ($settings['fan_api_username'] ?? ''));
$password = trim((string) ($settings['fan_api_password'] ?? ''));

if ($clientId <= 0 || $username === '' || $password === '') {
    fwrite(STDERR, "Setarile FAN API sunt incomplete (client_id/username/parola).\n");
    exit(1);
}

$credentials = [
    'client_id' => $clientId,
    'username' => $username,
    'password' => $password,
];

$stmt = $db->prepare(
    'SELECT id, order_number, fan_awb
     FROM orders
     WHERE fan_awb IS NOT NULL AND fan_awb <> ""
       AND deleted_at IS NULL
     ORDER BY fan_tracking_synced_at IS NULL DESC, fan_tracking_synced_at ASC, id DESC
     LIMIT :limit'
);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll();

if ($orders === []) {
    echo "Nu exista comenzi cu AWB pentru sincronizare.\n";
    exit(0);
}

$updated = 0;
$failed = 0;

foreach ($orders as $order) {
    $orderId = (int) ($order['id'] ?? 0);
    $awb = trim((string) ($order['fan_awb'] ?? ''));
    if ($orderId <= 0 || $awb === '') {
        continue;
    }

    try {
        $tracking = FanCourierGateway::trackAwb($credentials, $clientId, $awb, 'ro');
        $latestStatus = trim((string) ($tracking['latest_event_name'] ?? '')) ?: 'Status indisponibil';
        $latestEventAtRaw = trim((string) ($tracking['latest_event_at'] ?? ''));
        $latestEventAt = null;
        if ($latestEventAtRaw !== '') {
            $timestamp = strtotime($latestEventAtRaw);
            if ($timestamp !== false) {
                $latestEventAt = date('Y-m-d H:i:s', $timestamp);
            }
        }

        $update = $db->prepare(
            'UPDATE orders
             SET fan_tracking_status = :status,
                 fan_tracking_last_event_at = :last_event_at,
                 fan_tracking_synced_at = NOW(),
                 fan_tracking_url = :tracking_url
             WHERE id = :id'
        );
        $update->execute([
            'status' => $latestStatus,
            'last_event_at' => $latestEventAt,
            'tracking_url' => FanCourierGateway::trackingUrl($awb),
            'id' => $orderId,
        ]);

        $updated++;
    } catch (RuntimeException $exception) {
        $failed++;
        $message = substr(trim($exception->getMessage()), 0, 250);
        fwrite(STDERR, "AWB {$awb} (order #{$order['order_number']}): {$message}\n");
    }
}

echo "Sync complet: {$updated} actualizate, {$failed} erori.\n";
