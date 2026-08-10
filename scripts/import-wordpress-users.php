<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    exit("Conexiunea la baza de date nu este disponibilă.\n");
}

$csvPath = (string) ($argv[1] ?? '');
if ($csvPath === '') {
    exit("Utilizare: php scripts/import-wordpress-users.php /cale/catre/users-export.csv\n");
}
if (!is_file($csvPath)) {
    exit("Fișierul CSV nu există: {$csvPath}\n");
}

$handle = fopen($csvPath, 'rb');
if ($handle === false) {
    exit("Nu am putut deschide fișierul CSV.\n");
}

$firstLine = fgets($handle);
if ($firstLine === false) {
    fclose($handle);
    exit("Fișier CSV gol.\n");
}
$delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
rewind($handle);

$header = fgetcsv($handle, 0, $delimiter);
if (!is_array($header) || $header === []) {
    fclose($handle);
    exit("Nu am putut citi antetul CSV.\n");
}

$normalize = static fn (string $value): string => strtolower(trim($value));
$headerMap = [];
foreach ($header as $index => $columnName) {
    $key = $normalize((string) $columnName);
    if ($index === 0) {
        $key = ltrim($key, "\xEF\xBB\xBF");
    }
    if ($key !== '') {
        $headerMap[$key] = (int) $index;
    }
}

$pick = static function (array $row, array $map, array $aliases): string {
    foreach ($aliases as $alias) {
        $key = strtolower(trim((string) $alias));
        if (!array_key_exists($key, $map)) {
            continue;
        }
        $index = (int) $map[$key];
        $value = $row[$index] ?? '';
        return trim((string) $value);
    }
    return '';
};

$existsStmt = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$upsertStmt = $db->prepare(
    'INSERT INTO users (first_name, last_name, email, phone, birth_date, gender, password_hash, created_at)
     VALUES (:first_name, :last_name, :email, :phone, :birth_date, :gender, :password_hash, :created_at)
     ON DUPLICATE KEY UPDATE
        first_name = VALUES(first_name),
        last_name = VALUES(last_name),
        phone = VALUES(phone),
        birth_date = VALUES(birth_date),
        gender = VALUES(gender),
        password_hash = CASE
            WHEN VALUES(password_hash) IS NOT NULL AND VALUES(password_hash) <> "" THEN VALUES(password_hash)
            ELSE password_hash
        END'
);

$inserted = 0;
$updated = 0;
$skipped = 0;
$lineNo = 1;

while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
    $lineNo++;
    if (!is_array($row) || $row === []) {
        continue;
    }

    $email = strtolower($pick($row, $headerMap, ['email', 'user_email']));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $skipped++;
        continue;
    }

    $passwordHash = $pick($row, $headerMap, ['password_hash', 'user_pass']);
    if ($passwordHash === '') {
        // Keep import safe even if hash is missing: account can reset password later.
        $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    }

    $displayName = $pick($row, $headerMap, ['display_name', 'user_nicename', 'user_login']);
    $firstName = $pick($row, $headerMap, ['first_name', 'billing_first_name']);
    $lastName = $pick($row, $headerMap, ['last_name', 'billing_last_name']);
    if ($firstName === '' && $displayName !== '') {
        $parts = preg_split('/\s+/', $displayName) ?: [];
        $firstName = trim((string) ($parts[0] ?? ''));
        $lastName = trim(implode(' ', array_slice($parts, 1)));
    }
    if ($firstName === '') {
        $firstName = 'Client';
    }
    if ($lastName === '') {
        $lastName = 'WordPress';
    }

    $phone = $pick($row, $headerMap, ['phone', 'billing_phone']);
    $rawGender = strtolower($pick($row, $headerMap, ['gender', 'sex']));
    $gender = match ($rawGender) {
        'f', 'female', 'feminin', 'femeie' => 'feminin',
        'm', 'male', 'masculin', 'barbat' => 'masculin',
        default => null,
    };
    $rawBirthDate = $pick($row, $headerMap, ['birth_date', 'birthday', 'date_of_birth']);
    $birthDate = null;
    if ($rawBirthDate !== '') {
        $timestamp = strtotime($rawBirthDate);
        if ($timestamp !== false) {
            $birthDate = date('Y-m-d', $timestamp);
        }
    }
    $rawCreatedAt = $pick($row, $headerMap, ['created_at', 'user_registered', 'registered_at']);
    $createdAt = date('Y-m-d H:i:s');
    if ($rawCreatedAt !== '') {
        $timestamp = strtotime($rawCreatedAt);
        if ($timestamp !== false) {
            $createdAt = date('Y-m-d H:i:s', $timestamp);
        }
    }

    $existsStmt->execute(['email' => $email]);
    $exists = (bool) $existsStmt->fetchColumn();

    $upsertStmt->execute([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => $phone !== '' ? $phone : null,
        'birth_date' => $birthDate,
        'gender' => $gender,
        'password_hash' => $passwordHash,
        'created_at' => $createdAt,
    ]);

    if ($exists) {
        $updated++;
    } else {
        $inserted++;
    }
}

fclose($handle);

echo "Import utilizatori finalizat.\n";
echo "Inserați: {$inserted}\n";
echo "Actualizați: {$updated}\n";
echo "Săriți (date invalide): {$skipped}\n";

