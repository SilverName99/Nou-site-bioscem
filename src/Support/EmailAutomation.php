<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class EmailAutomation
{
    public static function ensureSchema(PDO $db): void
    {
        OrderMailer::ensureEmailSendHistorySchema($db);

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS email_logs (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    order_id INT UNSIGNED DEFAULT NULL,
                    email_type VARCHAR(80) NOT NULL,
                    recipient VARCHAR(190) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT \'sent\',
                    error_message TEXT DEFAULT NULL,
                    sent_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_order_email_type (order_id, email_type)
                )'
            );
        } catch (Throwable) {
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS cart_abandonments (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    session_id VARCHAR(190) NOT NULL UNIQUE,
                    email VARCHAR(190) DEFAULT NULL,
                    customer_name VARCHAR(190) DEFAULT NULL,
                    cart_snapshot LONGTEXT DEFAULT NULL,
                    last_seen_at DATETIME NOT NULL,
                    converted_at DATETIME DEFAULT NULL,
                    abandoned_email_sent_at DATETIME DEFAULT NULL,
                    abandoned_email_error TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )'
            );
        } catch (Throwable) {
        }
    }

    /**
     * Emailul de bun venit la crearea contului. Best-effort: dacă trimiterea
     * eșuează, contul rămâne creat — clientul nu are voie să vadă o eroare.
     */
    public static function sendAccountWelcome(
        PDO $db,
        array $settings,
        string $email,
        string $customerName = ''
    ): void {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        // Adresele interne generate când formularul nu cere email nu primesc nimic.
        if (str_ends_with(strtolower($email), '@local.invalid')) {
            return;
        }
        if (!OrderMailer::isTemplateActive('account_created', $settings)) {
            return;
        }

        $recipients = OrderMailer::resolveTemplateRecipients('account_created', $settings, $email);
        if ($recipients === []) {
            return;
        }

        $customerName = trim($customerName);
        if ($customerName === '' || strcasecmp($customerName, 'Client Nou') === 0) {
            // Fără nume real, folosim partea locală a emailului, nu „Client Nou".
            $customerName = ucfirst((string) strtok($email, '@'));
        }
        $storeName = trim((string) ($settings['order_email_from_name'] ?? 'Bioscem')) ?: 'Bioscem';

        $context = [
            'customer_name' => $customerName,
            'customer_email' => $email,
            'store_name' => $storeName,
            'year' => date('Y'),
            'order_action_url' => '/contul-meu',
        ];

        foreach ($recipients as $recipient) {
            $contextWithRecipient = $context;
            $contextWithRecipient['to'] = $recipient;
            try {
                OrderMailer::sendTemplate('account_created', $contextWithRecipient, $settings, $db, [
                    'source' => 'account_automation',
                    'meta' => [
                        'automation_template' => 'account_created',
                        'recipient_mode' => OrderMailer::templateRecipientMode('account_created', $settings),
                    ],
                ]);
            } catch (Throwable) {
                // Emailul de bun venit nu blochează niciodată înregistrarea.
            }
        }
    }

    public static function sendOrderTemplateById(PDO $db, array $settings, int $orderId, string $templateType): void
    {
        if ($orderId <= 0) {
            return;
        }

        self::ensureSchema($db);
        // Garantează coloana fan_awb_list (comenzile care pleacă în mai multe colete).
        ErpSync::ensureSchema($db);
        $stmt = $db->prepare(
            'SELECT id, order_number, status, billing_first_name, billing_last_name, billing_email,
                    fan_awb, fan_awb_list, fan_tracking_url, created_at, total
             FROM orders
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch() ?: null;
        if (!is_array($order)) {
            return;
        }

        self::sendOrderTemplate($db, $settings, $order, $templateType);
    }

    public static function sendOrderTemplate(PDO $db, array $settings, array $order, string $templateType): void
    {
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }

        if (!OrderMailer::isTemplateActive($templateType, $settings)) {
            return;
        }

        if (self::isOrderTemplateAlreadySent($db, $orderId, $templateType)) {
            return;
        }

        $customerEmail = trim((string) ($order['billing_email'] ?? ''));
        $recipients = OrderMailer::resolveTemplateRecipients($templateType, $settings, $customerEmail);
        if ($recipients === []) {
            return;
        }

        $customerName = trim((string) ($order['billing_first_name'] ?? '') . ' ' . (string) ($order['billing_last_name'] ?? ''));
        if ($customerName === '') {
            $customerName = 'Client';
        }

        // Comenzile din mai multe depozite au mai multe AWB-uri (fan_awb_list);
        // emailul de tracking primește câte un link pentru fiecare colet.
        $trackingLinks = [];
        $listaAwb = json_decode((string) ($order['fan_awb_list'] ?? ''), true);
        if (is_array($listaAwb)) {
            foreach ($listaAwb as $intrare) {
                if (!is_array($intrare)) {
                    continue;
                }
                $awbColet = trim((string) ($intrare['awb'] ?? ''));
                if ($awbColet === '') {
                    continue;
                }
                $urlColet = trim((string) ($intrare['tracking_url'] ?? ''));
                $trackingLinks[] = [
                    'awb' => $awbColet,
                    'url' => $urlColet !== '' ? $urlColet : FanCourierGateway::trackingUrl($awbColet),
                    'gestiune' => trim((string) ($intrare['gestiune'] ?? '')),
                ];
            }
        }

        $awb = trim((string) ($order['fan_awb'] ?? ''));
        $trackingUrl = trim((string) ($order['fan_tracking_url'] ?? ''));
        if ($trackingLinks !== []) {
            $awb = implode(', ', array_column($trackingLinks, 'awb'));
            $trackingUrl = (string) $trackingLinks[0]['url'];
        } elseif ($awb !== '') {
            $trackingUrl = FanCourierGateway::trackingUrl($awb);
        }
        if ($trackingUrl === '') {
            $trackingUrl = '/contul-meu?section=orders';
        }

        $storeName = trim((string) ($settings['order_email_from_name'] ?? 'Bioscem'));
        if ($storeName === '') {
            $storeName = 'Bioscem';
        }
        $orderItems = self::loadOrderItems($db, $orderId);
        $orderSummaryText = self::orderItemsSummaryText($orderItems);
        $orderSummaryRows = self::orderItemsSummaryRowsHtml($orderItems);

        $context = [
            'customer_name' => $customerName,
            'order_number' => (string) ($order['order_number'] ?? ''),
            'awb' => $awb,
            'courier_name' => $awb !== '' ? 'FAN Courier' : 'Curier rapid',
            'tracking_url' => $trackingUrl,
            'tracking_links' => $trackingLinks,
            'cart_summary' => '',
            'order_status' => self::orderStatusLabel((string) ($order['status'] ?? '')),
            'estimated_delivery' => self::estimatedDeliveryWindowLabel((string) ($order['created_at'] ?? '')),
            'customer_email' => $customerEmail,
            'store_name' => $storeName,
            'year' => date('Y'),
            'order_date' => self::orderDateLabel((string) ($order['created_at'] ?? '')),
            'order_total' => self::formatLei((float) ($order['total'] ?? 0)),
            'order_summary' => $orderSummaryText,
            'order_items_html' => $orderSummaryRows,
            'order_summary_rows' => $orderSummaryRows,
            'order_action_url' => $templateType === 'new_order'
                ? '/admin/orders'
                : ($templateType === 'cancelled' ? '/contact' : '/contul-meu?section=orders'),
        ];

        $sentCount = 0;
        $sentSubject = '';
        $sentRecipient = '';
        $lastErrorMessage = '';
        $recipientMode = OrderMailer::templateRecipientMode($templateType, $settings);

        foreach ($recipients as $recipient) {
            $contextWithRecipient = $context;
            $contextWithRecipient['to'] = $recipient;
            if (trim((string) $contextWithRecipient['customer_email']) === '') {
                $contextWithRecipient['customer_email'] = $recipient;
            }
            try {
                $mail = OrderMailer::sendTemplate($templateType, $contextWithRecipient, $settings, $db, [
                    'order_id' => $orderId,
                    'source' => 'order_automation',
                    'meta' => [
                        'automation_template' => $templateType,
                        'recipient_mode' => $recipientMode,
                    ],
                ]);
                if ($sentRecipient === '') {
                    $sentRecipient = $recipient;
                }
                if ($sentSubject === '') {
                    $sentSubject = trim((string) ($mail['subject'] ?? ''));
                }
                $sentCount++;
            } catch (RuntimeException $exception) {
                $lastErrorMessage = $exception->getMessage();
            }
        }

        if ($sentCount > 0) {
            self::writeOrderEmailLog(
                $db,
                $orderId,
                $templateType,
                $sentRecipient !== '' ? $sentRecipient : implode(', ', $recipients),
                $sentSubject,
                'sent',
                null
            );
            return;
        }

        self::writeOrderEmailLog(
            $db,
            $orderId,
            $templateType,
            implode(', ', $recipients),
            '',
            'error',
            $lastErrorMessage !== '' ? $lastErrorMessage : 'Nu am putut trimite emailul.'
        );
    }

    private static function orderStatusLabel(string $status): string
    {
        $status = trim(strtolower($status));
        return match ($status) {
            'pending' => 'În așteptare',
            'pending_payment' => 'Așteaptă plata',
            'processing' => 'În procesare',
            'completed' => 'Finalizată',
            'cancelled' => 'Anulată',
            'refunded' => 'Rambursată',
            'failed' => 'Eșuată',
            default => 'În procesare',
        };
    }

    private static function estimatedDeliveryWindowLabel(string $createdAt): string
    {
        $timestamp = strtotime($createdAt);
        if ($timestamp === false) {
            return '2-3 zile lucratoare';
        }

        $start = strtotime('+2 days', $timestamp);
        $end = strtotime('+3 days', $timestamp);
        if ($start === false || $end === false) {
            return '2-3 zile lucratoare';
        }

        $months = [
            1 => 'Ianuarie',
            2 => 'Februarie',
            3 => 'Martie',
            4 => 'Aprilie',
            5 => 'Mai',
            6 => 'Iunie',
            7 => 'Iulie',
            8 => 'August',
            9 => 'Septembrie',
            10 => 'Octombrie',
            11 => 'Noiembrie',
            12 => 'Decembrie',
        ];
        $startDay = (int) date('j', $start);
        $endDay = (int) date('j', $end);
        $monthIndex = (int) date('n', $end);
        $year = date('Y', $end);
        $month = $months[$monthIndex] ?? date('F', $end);

        return $startDay . '-' . $endDay . ' ' . $month . ' ' . $year;
    }

    private static function orderDateLabel(string $createdAt): string
    {
        $timestamp = strtotime($createdAt);
        if ($timestamp === false) {
            return date('j F Y');
        }

        $months = [
            1 => 'Ianuarie',
            2 => 'Februarie',
            3 => 'Martie',
            4 => 'Aprilie',
            5 => 'Mai',
            6 => 'Iunie',
            7 => 'Iulie',
            8 => 'August',
            9 => 'Septembrie',
            10 => 'Octombrie',
            11 => 'Noiembrie',
            12 => 'Decembrie',
        ];
        $day = (int) date('j', $timestamp);
        $monthIndex = (int) date('n', $timestamp);
        $year = date('Y', $timestamp);
        $month = $months[$monthIndex] ?? date('F', $timestamp);

        return $day . ' ' . $month . ' ' . $year;
    }

    private static function loadOrderItems(PDO $db, int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $stmt = $db->prepare(
            'SELECT product_name, quantity, line_total
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY id ASC'
        );
        $stmt->execute(['order_id' => $orderId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private static function orderItemsSummaryText(array $items): string
    {
        if ($items === []) {
            return 'Nu există produse în această comandă.';
        }

        $lines = [];
        foreach ($items as $item) {
            $name = trim((string) ($item['product_name'] ?? 'Produs'));
            if ($name === '') {
                $name = 'Produs';
            }
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $lineTotal = self::formatLei((float) ($item['line_total'] ?? 0));
            $lines[] = $name . ' x' . $qty . ' - ' . $lineTotal;
        }

        return implode("\n", $lines);
    }

    private static function orderItemsSummaryRowsHtml(array $items): string
    {
        if ($items === []) {
            return '<p style="margin:0;color:#64748b;font-size:14px;line-height:1.5;">Nu există produse în această comandă.</p>';
        }

        $rows = '';
        foreach ($items as $item) {
            $name = trim((string) ($item['product_name'] ?? 'Produs'));
            if ($name === '') {
                $name = 'Produs';
            }
            $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $lineTotal = htmlspecialchars(self::formatLei((float) ($item['line_total'] ?? 0)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $rows .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f4f7f4;border-radius:12px;padding:14px 16px;margin:0 0 10px;">'
                . '<div>'
                . '<p style="margin:0;color:#0f2532;font-size:22px;line-height:1.25;font-weight:700;">' . $safeName . '</p>'
                . '<p style="margin:4px 0 0;color:#5f7680;font-size:14px;line-height:1.35;">Cantitate: ' . $qty . '</p>'
                . '</div>'
                . '<div style="color:#0f2532;font-size:18px;line-height:1.2;font-weight:700;white-space:nowrap;">' . $lineTotal . '</div>'
                . '</div>';
        }

        return $rows;
    }

    private static function formatLei(float $amount): string
    {
        return number_format($amount, 2, ',', '.') . ' lei';
    }

    public static function touchCartAbandonment(PDO $db, string $sessionId, array $payload): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        self::ensureSchema($db);
        $email = trim((string) ($payload['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        $name = trim((string) ($payload['customer_name'] ?? ''));
        $snapshot = trim((string) ($payload['cart_snapshot'] ?? ''));
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'INSERT INTO cart_abandonments (session_id, email, customer_name, cart_snapshot, last_seen_at)
             VALUES (:session_id, :email, :customer_name, :cart_snapshot, :last_seen_at)
             ON DUPLICATE KEY UPDATE
                email = CASE WHEN VALUES(email) <> "" THEN VALUES(email) ELSE email END,
                customer_name = CASE WHEN VALUES(customer_name) <> "" THEN VALUES(customer_name) ELSE customer_name END,
                cart_snapshot = VALUES(cart_snapshot),
                last_seen_at = VALUES(last_seen_at)'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'email' => $email,
            'customer_name' => $name,
            'cart_snapshot' => $snapshot,
            'last_seen_at' => $now,
        ]);
    }

    public static function markCartConverted(PDO $db, string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        self::ensureSchema($db);
        $stmt = $db->prepare(
            'UPDATE cart_abandonments
             SET converted_at = NOW()
             WHERE session_id = :session_id AND converted_at IS NULL'
        );
        $stmt->execute(['session_id' => $sessionId]);
    }

    public static function sendDueAbandonedCartEmails(PDO $db, array $settings, int $limit = 100): array
    {
        self::ensureSchema($db);

        if (!OrderMailer::isTemplateActive('abandoned_cart', $settings)) {
            return ['sent' => 0, 'failed' => 0, 'total' => 0];
        }

        $minutes = (int) ($settings['email_abandoned_after_minutes'] ?? 60);
        if ($minutes <= 0) {
            $minutes = 60;
        }

        $limit = max(1, min(1000, $limit));
        $thresholdAt = (new DateTimeImmutable('now'))->modify("-{$minutes} minutes")->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'SELECT id, session_id, email, customer_name, cart_snapshot
             FROM cart_abandonments
             WHERE converted_at IS NULL
               AND abandoned_email_sent_at IS NULL
               AND email IS NOT NULL
               AND email <> ""
               AND last_seen_at <= :threshold_at
             ORDER BY last_seen_at ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':threshold_at', $thresholdAt);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $to = trim((string) ($row['email'] ?? ''));
            if ($id <= 0 || $to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $recipients = OrderMailer::resolveTemplateRecipients('abandoned_cart', $settings, $to);
            if ($recipients === []) {
                continue;
            }

            $sentAtLeastOne = false;
            $lastSubject = '';
            $lastError = null;
            foreach ($recipients as $recipient) {
                try {
                    $mail = OrderMailer::sendTemplate('abandoned_cart', [
                        'to' => $recipient,
                        'customer_name' => trim((string) ($row['customer_name'] ?? '')) ?: 'Client',
                        'customer_email' => $to,
                        'order_number' => '',
                        'awb' => '',
                        'tracking_url' => '',
                        'cart_summary' => trim((string) ($row['cart_snapshot'] ?? '')),
                        'order_action_url' => '/cos',
                        'cart_action_url' => '/cos',
                    ], $settings, $db, [
                        'source' => 'abandoned_cart_automation',
                        'meta' => [
                            'cart_abandonment_id' => $id,
                            'session_id' => (string) ($row['session_id'] ?? ''),
                            'recipient_mode' => OrderMailer::templateRecipientMode('abandoned_cart', $settings),
                        ],
                    ]);
                    $lastSubject = (string) ($mail['subject'] ?? '');
                    $sentAtLeastOne = true;
                } catch (RuntimeException $exception) {
                    $lastError = $exception;
                }
            }

            if ($sentAtLeastOne) {
                $ok = $db->prepare(
                    'UPDATE cart_abandonments
                     SET abandoned_email_sent_at = NOW(),
                         abandoned_email_error = NULL
                     WHERE id = :id'
                );
                $ok->execute(['id' => $id]);

                $log = $db->prepare(
                    'INSERT INTO email_logs (order_id, email_type, recipient, subject, status, error_message, sent_at)
                     VALUES (NULL, :email_type, :recipient, :subject, :status, NULL, NOW())'
                );
                $log->execute([
                    'email_type' => 'abandoned_cart',
                    'recipient' => implode(', ', $recipients),
                    'subject' => $lastSubject,
                    'status' => 'sent',
                ]);
                $sent++;
                continue;
            }

            $errorMessage = $lastError instanceof RuntimeException ? $lastError->getMessage() : 'Nu am putut trimite emailul.';
            try {
                $fail = $db->prepare(
                    'UPDATE cart_abandonments
                     SET abandoned_email_error = :error
                     WHERE id = :id'
                );
                $fail->execute([
                    'error' => substr($errorMessage, 0, 1000),
                    'id' => $id,
                ]);
            } catch (Throwable) {
            }
            $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'total' => count($rows)];
    }

    private static function isOrderTemplateAlreadySent(PDO $db, int $orderId, string $templateType): bool
    {
        $stmt = $db->prepare(
            'SELECT status
             FROM email_logs
             WHERE order_id = :order_id AND email_type = :email_type
             LIMIT 1'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'email_type' => $templateType,
        ]);
        $status = $stmt->fetchColumn();
        return $status !== false && (string) $status === 'sent';
    }

    private static function writeOrderEmailLog(
        PDO $db,
        int $orderId,
        string $templateType,
        string $recipient,
        string $subject,
        string $status,
        ?string $error
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO email_logs (order_id, email_type, recipient, subject, status, error_message, sent_at)
             VALUES (:order_id, :email_type, :recipient, :subject, :status, :error_message, :sent_at)
             ON DUPLICATE KEY UPDATE
                recipient = VALUES(recipient),
                subject = VALUES(subject),
                status = VALUES(status),
                error_message = VALUES(error_message),
                sent_at = VALUES(sent_at)'
        );

        $stmt->execute([
            'order_id' => $orderId,
            'email_type' => $templateType,
            'recipient' => substr($recipient, 0, 190),
            'subject' => substr($subject, 0, 255),
            'status' => $status === 'sent' ? 'sent' : 'error',
            'error_message' => $error !== null ? substr($error, 0, 1000) : null,
            'sent_at' => $status === 'sent' ? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') : null,
        ]);
    }
}
