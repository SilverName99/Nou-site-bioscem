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

use App\Support\Database;
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
$rezultat = ErpSync::retryPending($db, $limit);

printf(
    "[%s] Comenzi încercate: %d — trimise: %d, eșuate: %d\n",
    date('Y-m-d H:i:s'),
    $rezultat['incercate'],
    $rezultat['reusite'],
    $rezultat['esuate']
);

exit($rezultat['esuate'] > 0 ? 1 : 0);
