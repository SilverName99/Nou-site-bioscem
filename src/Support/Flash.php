<?php

declare(strict_types=1);

namespace App\Support;

final class Flash
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['flash'][$type] = $message;
    }

    public static function get(string $type): ?string
    {
        if (!isset($_SESSION['flash'][$type])) {
            return null;
        }

        $message = (string) $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        return $message;
    }
}
