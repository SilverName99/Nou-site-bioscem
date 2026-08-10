<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(array $config): ?PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        if (empty($config['database'])) {
            return null;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        try {
            self::$connection = new PDO(
                $dsn,
                (string) $config['username'],
                (string) $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            // Align the MySQL session time zone with the app's PHP time zone so that
            // NOW()/CURDATE() match date()-produced values (e.g. scheduled_at for
            // newsletters, sale periods). Uses a numeric offset so it works even when
            // the server has no named time-zone tables loaded. Computed at connect
            // time, so it respects DST.
            try {
                self::$connection->exec("SET time_zone = '" . date('P') . "'");
            } catch (PDOException) {
                // Fall back to the server default time zone if this is rejected.
            }
        } catch (PDOException) {
            self::$connection = null;
        }

        return self::$connection;
    }
}
