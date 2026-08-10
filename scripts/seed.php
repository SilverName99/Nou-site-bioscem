<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    exit("Conexiunea la baza de date nu este disponibilă.\n");
}

$seedPath = __DIR__ . '/../database/seed.sql';
$seedSql = file_get_contents($seedPath);

if ($seedSql === false) {
    exit("Nu am putut citi fișierul seed.sql.\n");
}

$db->exec($seedSql);
echo "Seed finalizat cu succes.\n";
