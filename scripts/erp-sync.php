<?php

declare(strict_types=1);

/**
 * Reîncearcă trimiterea în ERP a comenzilor rămase în urmă.
 *
 * De rulat din cron, la câteva minute:
 *   *\/5 * * * * php /cale/catre/site/scripts/erp-sync.php >/dev/null 2>&1
 *
 * Trimite doar comenzile al căror termen de reîncercare a venit, deci poate fi
 * rulat oricât de des fără să bombardeze ERP-ul.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Http\Controllers\AdminController;
use App\Support\Database;
use App\Support\ErpClient;
use App\Support\ErpSync;
use App\Support\Settings;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));

if (!$db instanceof PDO) {
    fwrite(STDERR, "Nu am putut deschide conexiunea la baza de date.\n");
    exit(1);
}

$settings = Settings::all($db);
if ((string) ($settings['erp_enabled'] ?? '0') !== '1') {
    echo "Integrarea cu ERP-ul este dezactivată; nu fac nimic.\n";
    exit(0);
}

$limit = isset($argv[1]) ? max(1, min(200, (int) $argv[1])) : 25;

// 1) Comenzile care n-au ajuns încă în ERP.
$rezultat = ErpSync::retryPending($db, $limit);

printf(
    "[%s] Comenzi încercate: %d — trimise: %d, eșuate: %d\n",
    date('Y-m-d H:i:s'),
    $rezultat['incercate'],
    $rezultat['reusite'],
    $rezultat['esuate']
);

// 2) Anulările care n-au apucat să ajungă în ERP (ERP oprit în acel moment).
$anulari = ErpSync::retryCancels($db, $limit);

if ($anulari['incercate'] > 0) {
    printf(
        "[%s] Anulări reluate: %d — duse la capăt: %d, eșuate: %d\n",
        date('Y-m-d H:i:s'),
        $anulari['incercate'],
        $anulari['reusite'],
        $anulari['esuate']
    );
}

// 3) Notificările pe care ERP-ul n-a reușit să ni le livreze (site jos în
//    momentul aprobării). Le luăm noi și le confirmăm după ce le aplicăm.
$notificari = ['preluate' => 0, 'aplicate' => 0, 'esuate' => 0];
$client = ErpClient::fromSettings($settings);

if ($client !== null) {
    try {
        $pending = $client->pendingNotifications();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Nu am putut citi notificările din ERP: ' . $exception->getMessage() . "\n");
        $pending = [];
    }

    $controller = new AdminController();
    $confirmate = [];

    foreach ($pending as $notificare) {
        $notificari['preluate']++;
        $payload = $notificare['payload'] ?? null;
        if (!is_array($payload)) {
            // Fără payload nu avem ce aplica; o confirmăm ca să nu se repete.
            $confirmate[] = (int) ($notificare['id'] ?? 0);
            continue;
        }

        try {
            $aplicat = $controller->handleErpEvent($payload);
        } catch (Throwable $exception) {
            $notificari['esuate']++;
            fwrite(STDERR, 'Notificare eșuată: ' . $exception->getMessage() . "\n");
            continue;
        }

        if (($aplicat['ok'] ?? false) !== true) {
            $notificari['esuate']++;
            fwrite(STDERR, 'Notificare respinsă: ' . (string) ($aplicat['message'] ?? '') . "\n");
            continue;
        }

        $notificari['aplicate']++;
        $confirmate[] = (int) ($notificare['id'] ?? 0);

        // AWB-ul generat aici trebuie să ajungă și în ERP.
        $awb = trim((string) ($aplicat['awb'] ?? ''));
        if ($awb !== '') {
            try {
                $client->reportAwb(
                    (string) ($payload['numarSite'] ?? ''),
                    $awb,
                    (string) ($aplicat['trackingUrl'] ?? '')
                );
            } catch (Throwable $exception) {
                fwrite(STDERR, 'Nu am putut raporta AWB-ul în ERP: ' . $exception->getMessage() . "\n");
            }
        }
    }

    $confirmate = array_values(array_filter($confirmate, static fn (int $id): bool => $id > 0));
    if ($confirmate !== []) {
        try {
            $client->confirmNotifications($confirmate);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Nu am putut confirma notificările: ' . $exception->getMessage() . "\n");
        }
    }
}

printf(
    "[%s] Notificări din ERP: preluate %d — aplicate %d, eșuate %d\n",
    date('Y-m-d H:i:s'),
    $notificari['preluate'],
    $notificari['aplicate'],
    $notificari['esuate']
);

exit(($rezultat['esuate'] > 0 || $anulari['esuate'] > 0 || $notificari['esuate'] > 0) ? 1 : 0);
