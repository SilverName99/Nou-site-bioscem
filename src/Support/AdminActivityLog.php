<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

final class AdminActivityLog
{
    public static function ensureSchema(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS admin_activity_log (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT UNSIGNED DEFAULT NULL,
                    admin_email VARCHAR(190) DEFAULT NULL,
                    action VARCHAR(100) NOT NULL,
                    context_json LONGTEXT DEFAULT NULL,
                    ip VARCHAR(45) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_created_at (created_at),
                    KEY idx_action (action)
                )'
            );
        } catch (Throwable) {
        }
    }

    public static function log(PDO $db, string $action, array $context = [], ?int $adminId = null): void
    {
        try {
            $id = $adminId ?? Auth::id();
            $email = null;
            if ($id !== null) {
                $stmt = $db->prepare('SELECT email FROM admins WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $id]);
                $row = $stmt->fetch();
                $email = is_array($row) ? ($row['email'] ?? null) : null;
            }

            $ip = self::clientIp();
            $json = $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

            $stmt = $db->prepare(
                'INSERT INTO admin_activity_log (admin_id, admin_email, action, context_json, ip)
                 VALUES (:admin_id, :admin_email, :action, :context_json, :ip)'
            );
            $stmt->execute([
                'admin_id'     => $id,
                'admin_email'  => $email,
                'action'       => $action,
                'context_json' => $json,
                'ip'           => $ip,
            ]);
        } catch (Throwable) {
        }
    }

    public static function recent(PDO $db, int $limit = 300, ?string $actionFilter = null): array
    {
        try {
            $limit = max(1, min(1000, $limit));
            if ($actionFilter !== null && $actionFilter !== '') {
                $stmt = $db->prepare(
                    'SELECT id, admin_id, admin_email, action, context_json, ip, created_at
                     FROM admin_activity_log
                     WHERE action = :action
                     ORDER BY id DESC
                     LIMIT ' . $limit
                );
                $stmt->execute(['action' => $actionFilter]);
            } else {
                $stmt = $db->query(
                    'SELECT id, admin_id, admin_email, action, context_json, ip, created_at
                     FROM admin_activity_log
                     ORDER BY id DESC
                     LIMIT ' . $limit
                );
            }
            return $stmt ? ($stmt->fetchAll() ?: []) : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function clientIp(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            $val = trim((string) ($_SERVER[$key] ?? ''));
            if ($val !== '') {
                return explode(',', $val)[0];
            }
        }
        return '';
    }
}
