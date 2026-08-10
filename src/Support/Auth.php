<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

final class Auth
{
    public const ROLE_GENERAL = 'administrator_general';
    public const ROLE_STORE = 'administrator_magazin';
    public const ROLE_BLOG = 'administrator_blog';
    public const ROLE_EMAILS = 'administrator_emailuri';

    public static function roles(): array
    {
        return [
            self::ROLE_GENERAL => 'Administrator General',
            self::ROLE_STORE => 'Administrator magazin',
            self::ROLE_BLOG => 'Administrator Blog',
            self::ROLE_EMAILS => 'Administrator email-uri',
        ];
    }

    public static function check(): bool
    {
        return isset($_SESSION['admin_id']);
    }

    public static function id(): ?int
    {
        return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
    }

    public static function attempt(PDO $db, string $email, string $password): bool
    {
        self::ensureSchema($db);

        $stmt = $db->prepare('SELECT id, password_hash, role, roles_json FROM admins WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        $admin = $stmt->fetch();

        if (!$admin) {
            return false;
        }

        $stored = (string) $admin['password_hash'];
        $isBcrypt = str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$2b$');
        $isValid = $isBcrypt ? password_verify($password, $stored) : hash_equals($stored, $password);

        if (!$isValid) {
            return false;
        }

        $roles = self::rolesFromStorage(
            (string) ($admin['role'] ?? self::ROLE_GENERAL),
            $admin['roles_json'] ?? null
        );
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_roles'] = $roles;
        $_SESSION['admin_role'] = self::primaryRole($roles);
        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_id']);
        unset($_SESSION['admin_roles']);
        unset($_SESSION['admin_role']);
    }

    public static function role(): string
    {
        return self::primaryRole(self::currentRoles());
    }

    public static function currentRoles(): array
    {
        $legacyRole = (string) ($_SESSION['admin_role'] ?? self::ROLE_GENERAL);
        $sessionRoles = $_SESSION['admin_roles'] ?? null;
        if (is_array($sessionRoles)) {
            return self::normalizeRoles($sessionRoles, $legacyRole);
        }

        return self::normalizeRoles([$legacyRole], self::ROLE_GENERAL);
    }

    public static function primaryRole(array $roles): string
    {
        $normalized = self::normalizeRoles($roles, self::ROLE_GENERAL);
        $priority = [
            self::ROLE_GENERAL,
            self::ROLE_EMAILS,
            self::ROLE_STORE,
            self::ROLE_BLOG,
        ];
        foreach ($priority as $role) {
            if (in_array($role, $normalized, true)) {
                return $role;
            }
        }

        return $normalized[0] ?? self::ROLE_GENERAL;
    }

    public static function isGeneralAdmin(): bool
    {
        return self::hasRole(self::ROLE_GENERAL);
    }

    public static function hasRole(string $role): bool
    {
        $role = self::normalizeRole($role);
        return in_array($role, self::currentRoles(), true);
    }

    public static function hasAnyRole(array $roles): bool
    {
        if ($roles === []) {
            return true;
        }
        $current = self::currentRoles();
        foreach ($roles as $role) {
            $normalizedRole = self::normalizeRole((string) $role);
            if (in_array($normalizedRole, $current, true)) {
                return true;
            }
        }
        return false;
    }

    public static function normalizeRole(string $role): string
    {
        $role = trim($role);
        if (!array_key_exists($role, self::roles())) {
            return self::ROLE_GENERAL;
        }

        return $role;
    }

    public static function normalizeRoles(array $roles, string $fallbackRole = self::ROLE_GENERAL): array
    {
        $normalized = [];
        foreach ($roles as $role) {
            $value = trim((string) $role);
            if ($value === '' || !array_key_exists($value, self::roles())) {
                continue;
            }
            if (!in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }
        if ($normalized === []) {
            $normalized[] = self::normalizeRole($fallbackRole);
        }

        return $normalized;
    }

    public static function rolesFromStorage(string $legacyRole, mixed $rolesJson): array
    {
        $roles = [];
        if (is_array($rolesJson)) {
            $roles = $rolesJson;
        } elseif (is_string($rolesJson) && trim($rolesJson) !== '') {
            $decoded = json_decode($rolesJson, true);
            if (is_array($decoded)) {
                $roles = $decoded;
            }
        }
        $roles[] = $legacyRole;

        return self::normalizeRoles($roles, $legacyRole);
    }

    public static function defaultPathForRole(string|array $role = ''): string
    {
        $roles = [];
        if (is_array($role)) {
            $roles = self::normalizeRoles($role, self::ROLE_GENERAL);
        } elseif ($role !== '') {
            $roles = [self::normalizeRole((string) $role)];
        } else {
            $roles = self::currentRoles();
        }

        if (in_array(self::ROLE_GENERAL, $roles, true)) {
            return '/admin';
        }
        if (in_array(self::ROLE_EMAILS, $roles, true)) {
            return '/admin/emails/newsletters?tab=templates';
        }
        if (in_array(self::ROLE_STORE, $roles, true)) {
            return '/admin/products';
        }
        if (in_array(self::ROLE_BLOG, $roles, true)) {
            return '/admin/blog/posts';
        }

        return '/admin';
    }

    public static function canAccessPath(string $path, string|array $role = ''): bool
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/admin';
        $roles = [];
        if (is_array($role)) {
            $roles = self::normalizeRoles($role, self::ROLE_GENERAL);
        } elseif ($role !== '') {
            $roles = [self::normalizeRole((string) $role)];
        } else {
            $roles = self::currentRoles();
        }

        if ($path === '/admin') {
            return true;
        }
        if (in_array(self::ROLE_GENERAL, $roles, true)) {
            return true;
        }

        foreach ($roles as $currentRole) {
            if (self::canAccessPathForRole($path, $currentRole)) {
                return true;
            }
        }

        return false;
    }

    private static function canAccessPathForRole(string $path, string $role): bool
    {
        $allowedPrefixes = match ($role) {
            self::ROLE_STORE => [
                '/admin/users/security',
                '/admin/products',
                '/admin/categories',
                '/admin/coupons',
                '/admin/orders',
                '/admin/settings/store',
                '/admin/settings/shipping',
                '/admin/settings/payments',
                '/admin/settings/floating-cart',
                '/admin/settings/mannequin',
                '/admin/settings/google',
                '/admin/products/fields',
                '/admin/products/templates',
                '/admin/products/reviews',
                '/admin/gallery',
                '/admin/pages',
                '/admin/design',
                '/admin/emails',
            ],
            self::ROLE_BLOG => [
                '/admin/blog/posts',
                '/admin/blog/templates',
                '/admin/blog/authors',
                '/admin/gallery',
            ],
            self::ROLE_EMAILS => [
                '/admin/blog/posts',
                '/admin/blog/templates',
                '/admin/blog/authors',
                '/admin/gallery',
                '/admin/emails',
            ],
            default => [],
        };

        foreach ($allowedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                if ($role === self::ROLE_STORE && str_starts_with($path, '/admin/emails/newsletters')) {
                    return false;
                }
                if ($role === self::ROLE_BLOG && (
                    str_starts_with($path, '/admin/users')
                    || str_starts_with($path, '/admin/products')
                    || str_starts_with($path, '/admin/orders')
                    || str_starts_with($path, '/admin/settings')
                    || str_starts_with($path, '/admin/emails')
                )) {
                    return false;
                }
                if ($role === self::ROLE_EMAILS && (
                    str_starts_with($path, '/admin/users')
                    || str_starts_with($path, '/admin/products')
                    || str_starts_with($path, '/admin/orders')
                    || str_starts_with($path, '/admin/settings')
                )) {
                    return false;
                }
                return true;
            }
        }

        return false;
    }

    private static function ensureSchema(PDO $db): void
    {
        try {
            $db->exec('ALTER TABLE admins ADD COLUMN role VARCHAR(50) NOT NULL DEFAULT "administrator_general" AFTER password_hash');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE admins SET role = "administrator_general" WHERE role IS NULL OR role = ""');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE admins ADD COLUMN roles_json LONGTEXT DEFAULT NULL AFTER role');
        } catch (Throwable) {
        }
        try {
            $rows = $db->query('SELECT id, role, roles_json FROM admins')->fetchAll() ?: [];
            $update = $db->prepare('UPDATE admins SET role = :role, roles_json = :roles_json WHERE id = :id');
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $roles = self::rolesFromStorage((string) ($row['role'] ?? self::ROLE_GENERAL), $row['roles_json'] ?? null);
                $primaryRole = self::primaryRole($roles);
                $rolesJson = json_encode($roles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($rolesJson === false) {
                    continue;
                }
                $update->execute([
                    'id' => (int) ($row['id'] ?? 0),
                    'role' => $primaryRole,
                    'roles_json' => $rolesJson,
                ]);
            }
        } catch (Throwable) {
        }
    }
}
