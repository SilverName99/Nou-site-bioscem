<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class CustomerAuth
{
    private const SESSION_KEY = 'customer_user_id';

    public static function check(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]) && (int) $_SESSION[self::SESSION_KEY] > 0;
    }

    public static function id(): ?int
    {
        return self::check() ? (int) $_SESSION[self::SESSION_KEY] : null;
    }

    public static function login(int $userId): void
    {
        if ($userId > 0) {
            $_SESSION[self::SESSION_KEY] = $userId;
        }
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public static function user(PDO $db): ?array
    {
        $userId = self::id();
        if ($userId === null || $userId <= 0) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT id, first_name, last_name, email, phone, birth_date, gender, loyalty_points, created_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            self::logout();
            return null;
        }

        return $row;
    }
}

