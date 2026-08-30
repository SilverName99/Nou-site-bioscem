<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class OrderMailer
{
    public static function ensureEmailSendHistorySchema(?PDO $db): void
    {
        if (!$db instanceof PDO) {
            return;
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS email_send_history (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    order_id INT UNSIGNED DEFAULT NULL,
                    email_type VARCHAR(80) NOT NULL,
                    source VARCHAR(80) NOT NULL DEFAULT "system",
                    recipient VARCHAR(190) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT "sent",
                    error_message TEXT DEFAULT NULL,
                    provider VARCHAR(30) DEFAULT NULL,
                    meta_json LONGTEXT DEFAULT NULL,
                    sent_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_email_send_history_created (created_at),
                    KEY idx_email_send_history_type (email_type),
                    KEY idx_email_send_history_status (status),
                    KEY idx_email_send_history_order (order_id)
                )'
            );
        } catch (Throwable) {
        }
    }

    public static function templateDefinitions(): array
    {
        $newOrderDefaultBody = self::newOrderAdvancedDefaultBody();
        $processingDefaultBody = self::processingAdvancedDefaultBody();
        $shippedDefaultBody = self::shippedAdvancedDefaultBody();
        $deliveredDefaultBody = self::deliveredAdvancedDefaultBody();
        $cancelledDefaultBody = self::cancelledAdvancedDefaultBody();
        $abandonedCartDefaultBody = self::abandonedCartAdvancedDefaultBody();

        return [
            'new_order' => [
                'label' => 'Comandă nouă',
                'subject_key' => 'email_template_new_order_subject',
                'body_key' => 'email_template_new_order_body',
                'active_key' => 'email_template_new_order_active',
                'recipient_mode_key' => 'email_template_new_order_recipient_mode',
                'admin_recipients_key' => 'email_template_new_order_admin_recipients',
                'default_recipient_mode' => 'client',
                'default_subject' => 'Comandă nouă {{order_number}}',
                'default_body' => $newOrderDefaultBody,
            ],
            'new_order_op' => [
                'label' => 'Comandă nouă — plată prin OP',
                'subject_key' => 'email_template_new_order_op_subject',
                'body_key' => 'email_template_new_order_op_body',
                'active_key' => 'email_template_new_order_op_active',
                'recipient_mode_key' => 'email_template_new_order_op_recipient_mode',
                'admin_recipients_key' => 'email_template_new_order_op_admin_recipients',
                'default_recipient_mode' => 'client',
                'default_subject' => 'Date de plată pentru comanda {{order_number}}',
                'default_body' => self::newOrderOpAdvancedDefaultBody(),
            ],
            'processing' => [
                'label' => 'Comandă în procesare',
                'subject_key' => 'email_template_processing_subject',
                'body_key' => 'email_template_processing_body',
                'active_key' => 'email_template_processing_active',
                'recipient_mode_key' => 'email_template_processing_recipient_mode',
                'admin_recipients_key' => 'email_template_processing_admin_recipients',
                'default_recipient_mode' => 'client',
                'default_subject' => 'Comandă {{order_number}} în procesare',
                'default_body' => $processingDefaultBody,
            ],
            'shipped' => [
                'label' => 'Comandă expediată',
                'subject_key' => 'email_template_shipped_subject',
                'body_key' => 'email_template_shipped_body',
                'active_key' => 'email_template_shipped_active',
                'recipient_mode_key' => 'email_template_shipped_recipient_mode',
                'admin_recipients_key' => 'email_template_shipped_admin_recipients',
                'default_recipient_mode' => 'client',
                'default_subject' => 'Comandă {{order_number}} expediată',
                'default_body' => $shippedDefaultBody,
            ],
            'delivered' => [
                'label' => 'Comandă livrată/finalizată',
                'subject_key' => 'email_template_delivered_subject',
                'body_key' => 'email_template_delivered_body',
                'active_key' => 'email_template_delivered_active',
                'recipient_mode_key' => 'email_template_delivered_recipient_mode',
                'admin_recipients_key' => 'email_template_delivered_admin_recipients',
                'default_recipient_mode' => 'client',
                'default_subject' => 'Comandă livrată {{order_number}}',
                'default_body' => $deliveredDefaultBody,
            ],
            'cancelled' => [
                'label' => 'Comandă anulată',
                'subject_key' => 'email_template_cancelled_subject',
                'body_key' => 'email_template_cancelled_body',
                'active_key' => 'email_template_cancelled_active',
                'recipient_mode_key' => 'email_template_cancelled_recipient_mode',
                'admin_recipients_key' => 'email_template_cancelled_admin_recipients',
                'default_recipient_mode' => 'client',
                'default_subject' => 'Comandă anulată {{order_number}}',
                'default_body' => $cancelledDefaultBody,
            ],
            'account_created' => [
                'label' => 'Bun venit (cont nou)',
                'subject_key' => 'email_template_account_created_subject',
                'body_key' => 'email_template_account_created_body',
                'active_key' => 'email_template_account_created_active',
                'recipient_mode_key' => 'email_template_account_created_recipient_mode',
                'admin_recipients_key' => 'email_template_account_created_admin_recipients',
                'default_recipient_mode' => 'client',
                'default_subject' => 'Bine ai venit la {{store_name}}! 🌿',
                'default_body' => self::accountCreatedAdvancedDefaultBody(),
            ],
            'abandoned_cart' => [
                'label' => 'Abandon coș',
                'subject_key' => 'email_template_abandoned_cart_subject',
                'body_key' => 'email_template_abandoned_cart_body',
                'active_key' => 'email_template_abandoned_cart_active',
                'recipient_mode_key' => 'email_template_abandoned_cart_recipient_mode',
                'admin_recipients_key' => 'email_template_abandoned_cart_admin_recipients',
                'default_recipient_mode' => 'client',
                'default_subject' => 'Ai uitat produse în coș',
                'default_body' => $abandonedCartDefaultBody,
            ],
        ];
    }

    public static function isTemplateActive(string $type, array $settings): bool
    {
        $definitions = self::templateDefinitions();
        $definition = $definitions[$type] ?? null;
        if (!is_array($definition)) {
            return false;
        }

        $activeKey = (string) ($definition['active_key'] ?? '');
        if ($activeKey === '') {
            return true;
        }

        return ((string) ($settings[$activeKey] ?? '1')) === '1';
    }

    public static function templateRecipientModeKey(string $type): string
    {
        $definitions = self::templateDefinitions();
        $definition = $definitions[$type] ?? null;
        $key = is_array($definition) ? trim((string) ($definition['recipient_mode_key'] ?? '')) : '';
        if ($key !== '') {
            return $key;
        }

        return 'email_template_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($type)) . '_recipient_mode';
    }

    public static function templateAdminRecipientsKey(string $type): string
    {
        $definitions = self::templateDefinitions();
        $definition = $definitions[$type] ?? null;
        $key = is_array($definition) ? trim((string) ($definition['admin_recipients_key'] ?? '')) : '';
        if ($key !== '') {
            return $key;
        }

        return 'email_template_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($type)) . '_admin_recipients';
    }

    public static function templateRecipientMode(string $type, array $settings): string
    {
        $definitions = self::templateDefinitions();
        $definition = $definitions[$type] ?? null;
        if (!is_array($definition)) {
            return 'client';
        }

        $allowedModes = ['client', 'admin', 'client_admin'];
        $mode = strtolower(trim((string) ($settings[self::templateRecipientModeKey($type)] ?? '')));
        if ($mode === 'both') {
            $mode = 'client_admin';
        }
        if (!in_array($mode, $allowedModes, true)) {
            $mode = strtolower(trim((string) ($definition['default_recipient_mode'] ?? 'client')));
        }
        return in_array($mode, $allowedModes, true) ? $mode : 'client';
    }

    public static function parseRecipientEmails(string $emailsRaw): array
    {
        $tokens = preg_split('/[\s,;]+/', trim($emailsRaw)) ?: [];
        $emails = [];
        $seen = [];
        foreach ($tokens as $token) {
            $email = trim((string) $token);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $dedupeKey = strtolower($email);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $emails[] = $email;
        }
        return $emails;
    }

    public static function templateAdminRecipients(string $type, array $settings): array
    {
        $raw = (string) ($settings[self::templateAdminRecipientsKey($type)] ?? '');
        return self::parseRecipientEmails($raw);
    }

    public static function resolveTemplateRecipients(string $type, array $settings, string $customerEmail = ''): array
    {
        $mode = self::templateRecipientMode($type, $settings);
        $recipients = [];

        if (in_array($mode, ['client', 'client_admin'], true)) {
            $customerEmail = trim($customerEmail);
            if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $customerEmail;
            }
        }

        if (in_array($mode, ['admin', 'client_admin'], true)) {
            $recipients = array_merge($recipients, self::templateAdminRecipients($type, $settings));
        }

        $unique = [];
        $seen = [];
        foreach ($recipients as $email) {
            $safeEmail = trim((string) $email);
            if ($safeEmail === '' || !filter_var($safeEmail, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $dedupeKey = strtolower($safeEmail);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $unique[] = $safeEmail;
        }

        return $unique;
    }

    public static function sendCompletedAwb(array $order, array $settings, ?PDO $db = null): void
    {
        $customerEmail = trim((string) ($order['billing_email'] ?? ''));
        $recipients = self::resolveTemplateRecipients('delivered', $settings, $customerEmail);
        if ($recipients === []) {
            throw new RuntimeException('Nu există destinatari configurați pentru template-ul livrat/finalizat.');
        }

        $orderNumber = trim((string) ($order['order_number'] ?? ''));
        $awb = trim((string) ($order['fan_awb'] ?? ''));
        if ($awb === '') {
            throw new RuntimeException('Comanda nu are AWB FAN.');
        }

        $trackingUrl = trim((string) ($order['fan_tracking_url'] ?? ''));
        $trackingUrl = FanCourierGateway::trackingUrl($awb);

        $firstName = trim((string) ($order['billing_first_name'] ?? ''));
        $lastName = trim((string) ($order['billing_last_name'] ?? ''));
        $customerName = trim($firstName . ' ' . $lastName);
        if ($customerName === '') {
            $customerName = 'Client';
        }

        $sentCount = 0;
        $lastError = null;
        foreach ($recipients as $recipient) {
            try {
                self::sendTemplate('delivered', [
                    'to' => $recipient,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail !== '' ? $customerEmail : $recipient,
                    'order_number' => $orderNumber,
                    'awb' => $awb,
                    'tracking_url' => $trackingUrl,
                    'cart_summary' => '',
                ], $settings, $db, [
                    'order_id' => (int) ($order['id'] ?? 0),
                    'source' => 'completed_awb',
                    'meta' => [
                        'context' => 'completed_awb',
                        'recipient_mode' => self::templateRecipientMode('delivered', $settings),
                    ],
                ]);
                $sentCount++;
            } catch (RuntimeException $exception) {
                $lastError = $exception;
            }
        }

        if ($sentCount <= 0) {
            if ($lastError instanceof RuntimeException) {
                throw $lastError;
            }
            throw new RuntimeException('Nu am putut trimite emailul de confirmare AWB.');
        }
    }

    public static function sendTemplate(
        string $type,
        array $context,
        array $settings,
        ?PDO $db = null,
        array $historyMeta = []
    ): array
    {
        $to = trim((string) ($context['to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adresa destinatarului este invalida.');
        }

        $definitions = self::templateDefinitions();
        $definition = $definitions[$type] ?? null;
        if (!is_array($definition)) {
            throw new RuntimeException('Tip de template email necunoscut: ' . $type);
        }

        $subjectTemplate = trim((string) ($settings[(string) $definition['subject_key']] ?? (string) $definition['default_subject']));
        $bodyTemplate = trim((string) ($settings[(string) $definition['body_key']] ?? (string) $definition['default_body']));
        if ($subjectTemplate === '') {
            $subjectTemplate = (string) $definition['default_subject'];
        }
        if ($bodyTemplate === '') {
            $bodyTemplate = (string) $definition['default_body'];
        }
        // Șabloanele salvate cu editorul vizual înainte de separarea de
        // newsletter au rămas cu subsolul de dezabonare („Email de test…").
        // Într-un email de comandă nu are ce căuta.
        $bodyTemplate = NewsletterService::faraFooterDezabonare($bodyTemplate);
        if ($type === 'processing') {
            $legacySubjects = self::processingLegacySubjectCandidates();
            if (in_array(trim($subjectTemplate), $legacySubjects, true)) {
                $subjectTemplate = 'Comandă {{order_number}} în procesare';
            }
            if (self::processingBodyNeedsUpgrade($bodyTemplate)) {
                $bodyTemplate = self::processingAdvancedDefaultBody();
            }
        } elseif ($type === 'new_order') {
            $legacySubjects = self::newOrderLegacySubjectCandidates();
            if (in_array(trim($subjectTemplate), $legacySubjects, true)) {
                $subjectTemplate = 'Comandă nouă {{order_number}}';
            }
            if (self::newOrderBodyNeedsUpgrade($bodyTemplate)) {
                $bodyTemplate = self::newOrderAdvancedDefaultBody();
            }
        } elseif ($type === 'shipped') {
            $legacySubjects = self::shippedLegacySubjectCandidates();
            if (in_array(trim($subjectTemplate), $legacySubjects, true)) {
                $subjectTemplate = 'Comandă {{order_number}} expediată';
            }
            if (self::shippedBodyNeedsUpgrade($bodyTemplate)) {
                $bodyTemplate = self::shippedAdvancedDefaultBody();
            }
        } elseif ($type === 'delivered') {
            $legacySubjects = self::deliveredLegacySubjectCandidates();
            if (in_array(trim($subjectTemplate), $legacySubjects, true)) {
                $subjectTemplate = 'Comandă livrată {{order_number}}';
            }
            if (self::deliveredBodyNeedsUpgrade($bodyTemplate)) {
                $bodyTemplate = self::deliveredAdvancedDefaultBody();
            }
        } elseif ($type === 'cancelled') {
            $legacySubjects = self::cancelledLegacySubjectCandidates();
            if (in_array(trim($subjectTemplate), $legacySubjects, true)) {
                $subjectTemplate = 'Comandă anulată {{order_number}}';
            }
            if (self::cancelledBodyNeedsUpgrade($bodyTemplate)) {
                $bodyTemplate = self::cancelledAdvancedDefaultBody();
            }
        } elseif ($type === 'abandoned_cart') {
            $legacySubjects = self::abandonedCartLegacySubjectCandidates();
            if (in_array(trim($subjectTemplate), $legacySubjects, true)) {
                $subjectTemplate = 'Ai uitat produse în coș';
            }
            if (self::abandonedCartBodyNeedsUpgrade($bodyTemplate)) {
                $bodyTemplate = self::abandonedCartAdvancedDefaultBody();
            }
        }

        $vars = self::templateVars($context);
        $subject = self::applyTemplate($subjectTemplate, $vars);
        if ($subject === '') {
            $subject = 'Notificare comanda';
        }

        $bodyHtml = self::applyTemplate($bodyTemplate, $vars);
        if (!str_contains($bodyHtml, '<')) {
            $bodyHtml = nl2br(htmlspecialchars($bodyHtml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        // Datele de plată prin OP trebuie să ajungă la client chiar dacă
        // șablonul de email n-a fost editat să conțină {{payment_instructions}}:
        // fără ele, emailul e frumos și inutil.
        $instructiuniPlata = trim((string) ($context['payment_instructions'] ?? ''));
        if ($instructiuniPlata !== '' && !str_contains($bodyTemplate, '{{payment_instructions}}')) {
            $bodyHtml .= self::paymentInstructionsHtml($instructiuniPlata);
        }

        // Şi la fel pentru şablonul de OP: dacă cineva a şters din el
        // {{payment_details}}, emailul ar cere o plată fără să spună unde.
        $datelePlatii = trim((string) ($context['payment_details'] ?? ''));
        if (
            $datelePlatii !== ''
            && !str_contains($bodyTemplate, '{{payment_details}}')
            && !str_contains($bodyTemplate, '{{payment_instructions}}')
        ) {
            $bodyHtml .= self::paymentInstructionsHtml($datelePlatii);
        }

        $heading = htmlspecialchars((string) ($definition['label'] ?? 'Notificare comanda'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = <<<HTML
<!doctype html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Comanda finalizata</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#1f2937;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:20px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:600px;max-width:95%;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
          <tr>
            <td style="padding:20px 24px;background:#0f766e;color:#ffffff;">
              <h1 style="margin:0;font-size:20px;">{$heading}</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 24px;">
              {$bodyHtml}
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

        $provider = self::resolveProvider($settings);
        $orderId = max(0, (int) ($historyMeta['order_id'] ?? ($context['order_id'] ?? 0)));
        $source = trim((string) ($historyMeta['source'] ?? 'template'));
        if ($source === '') {
            $source = 'template';
        }
        $emailType = trim((string) ($historyMeta['email_type'] ?? $type));
        if ($emailType === '') {
            $emailType = $type;
        }
        $meta = is_array($historyMeta['meta'] ?? null) ? $historyMeta['meta'] : [];
        $meta['template_type'] = $type;
        $orderNumber = trim((string) ($context['order_number'] ?? ''));
        if ($orderNumber !== '') {
            $meta['order_number'] = $orderNumber;
        }

        try {
            self::sendHtmlEmail($to, $subject, $html, $settings);
            self::logEmailSend($db, [
                'order_id' => $orderId,
                'email_type' => $emailType,
                'source' => $source,
                'recipient' => $to,
                'subject' => $subject,
                'status' => 'sent',
                'error_message' => null,
                'provider' => $provider,
                'meta' => $meta,
            ]);
        } catch (RuntimeException $exception) {
            self::logEmailSend($db, [
                'order_id' => $orderId,
                'email_type' => $emailType,
                'source' => $source,
                'recipient' => $to,
                'subject' => $subject,
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'provider' => $provider,
                'meta' => $meta,
            ]);
            throw $exception;
        }

        return [
            'subject' => $subject,
            'html' => $html,
        ];
    }

    public static function sendCustom(
        string $to,
        string $subject,
        string $htmlBody,
        array $settings,
        ?PDO $db = null,
        array $historyMeta = []
    ): void
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adresa destinatarului este invalida.');
        }

        $safeBody = $htmlBody !== '' ? $htmlBody : '<p>Email test.</p>';
        // If the body is already a complete HTML document, send it as-is (no wrapper card)
        if (stripos(ltrim($safeBody), '<!doctype') === 0 || stripos(ltrim($safeBody), '<html') === 0) {
            $html = $safeBody;
        } else {
            $html = <<<HTML
<!doctype html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$subject}</title>
  <style>
    @media only screen and (max-width:640px){
      .email-card{border:none!important;border-radius:0!important;padding:0!important;margin:0!important;max-width:100%!important;}
      body{padding:0!important;background:#ffffff!important;}
    }
  </style>
</head>
<body style="margin:0;padding:20px;background:#f4f6f8;font-family:Arial,sans-serif;color:#1f2937;">
  <div class="email-card" style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;">
    {$safeBody}
  </div>
</body>
</html>
HTML;
        }
        $provider = self::resolveProvider($settings);
        $orderId = max(0, (int) ($historyMeta['order_id'] ?? 0));
        $source = trim((string) ($historyMeta['source'] ?? 'custom'));
        if ($source === '') {
            $source = 'custom';
        }
        $emailType = trim((string) ($historyMeta['email_type'] ?? 'custom'));
        if ($emailType === '') {
            $emailType = 'custom';
        }
        $meta = is_array($historyMeta['meta'] ?? null) ? $historyMeta['meta'] : [];

        try {
            self::sendHtmlEmail($to, $subject, $html, $settings);
            self::logEmailSend($db, [
                'order_id' => $orderId,
                'email_type' => $emailType,
                'source' => $source,
                'recipient' => $to,
                'subject' => $subject,
                'status' => 'sent',
                'error_message' => null,
                'provider' => $provider,
                'meta' => $meta,
            ]);
        } catch (RuntimeException $exception) {
            self::logEmailSend($db, [
                'order_id' => $orderId,
                'email_type' => $emailType,
                'source' => $source,
                'recipient' => $to,
                'subject' => $subject,
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'provider' => $provider,
                'meta' => $meta,
            ]);
            throw $exception;
        }
    }

    private static function sendHtmlEmail(string $to, string $subject, string $html, array $settings): void
    {
        $fromName = self::sanitizeHeader((string) ($settings['order_email_from_name'] ?? 'Bioscem'));
        $fromAddress = trim((string) ($settings['order_email_from_address'] ?? 'no-reply@localhost'));
        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $fromAddress = 'no-reply@localhost';
        }

        $subject = self::sanitizeHeader($subject);
        $deliveryMethod = strtolower(trim((string) ($settings['email_delivery_method'] ?? 'smtp')));
        if ($deliveryMethod === 'sendgrid') {
            self::sendWithSendGrid($to, $subject, $html, $fromName, $fromAddress, $settings);
            return;
        }

        // SMTP autentificat când e configurat; mail() rămâne doar rezervă
        // pentru instalările fără SMTP (pe Hostinger mail() e aruncat tăcut).
        $smtpHost = trim((string) ($settings['smtp_host'] ?? ''));
        if ($deliveryMethod === 'smtp' && $smtpHost !== '') {
            self::sendWithSmtp($to, $subject, $html, $fromName, $fromAddress, $settings);
            return;
        }

        self::sendWithPhpMail($to, $subject, $html, $fromName, $fromAddress);
    }

    /**
     * Client SMTP propriu (EHLO, STARTTLS/SSL, AUTH LOGIN, MAIL/RCPT/DATA).
     * Orice răspuns neașteptat al serverului devine o excepție cu textul
     * serverului — ca eșecurile să fie vizibile, nu „succes" fals ca la mail().
     */
    private static function sendWithSmtp(
        string $to,
        string $subject,
        string $html,
        string $fromName,
        string $fromAddress,
        array $settings
    ): void {
        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $port = (int) ($settings['smtp_port'] ?? 587);
        if ($port <= 0) {
            $port = 587;
        }
        $criptare = strtolower(trim((string) ($settings['smtp_encryption'] ?? 'tls')));
        $utilizator = trim((string) ($settings['smtp_username'] ?? ''));
        $parola = (string) ($settings['smtp_password'] ?? '');

        // Portul 465 vorbește TLS de la primul octet; 587 pornește în clar și urcă prin STARTTLS.
        $sslDirect = $criptare === 'ssl' || $port === 465;
        $adresa = ($sslDirect ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $context = stream_context_create([
            'ssl' => [
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);
        $fp = @stream_socket_client($adresa, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($fp)) {
            throw new RuntimeException("Nu m-am putut conecta la serverul SMTP {$host}:{$port} ({$errstr}).");
        }
        stream_set_timeout($fp, 20);

        $citeste = static function () use ($fp): string {
            $raspuns = '';
            while (($linie = fgets($fp, 2048)) !== false) {
                $raspuns .= $linie;
                // Ultima linie a unui răspuns SMTP are spațiu după cod („250 "), nu liniuță („250-").
                if (strlen($linie) < 4 || $linie[3] !== '-') {
                    break;
                }
            }
            return $raspuns;
        };
        $trimite = static function (string $comanda, array $coduriOk) use ($fp, $citeste): string {
            fwrite($fp, $comanda . "\r\n");
            $raspuns = $citeste();
            $cod = (int) substr($raspuns, 0, 3);
            if (!in_array($cod, $coduriOk, true)) {
                fclose($fp);
                $curat = trim(preg_replace('/\s+/', ' ', $raspuns) ?? $raspuns);
                throw new RuntimeException("SMTP a refuzat comanda (" . strtok($comanda, ' ') . "): {$curat}");
            }
            return $raspuns;
        };

        try {
            $salut = $citeste();
            if ((int) substr($salut, 0, 3) !== 220) {
                throw new RuntimeException('SMTP: serverul nu a salutat (' . trim($salut) . ').');
            }

            // Domeniul expeditorului, nu al serverului: din cron nu există
            // SERVER_NAME, iar un „localhost" în EHLO și în Message-ID e un
            // semnal clasic de spam.
            $domeniuExpeditor = self::domeniuDinEmail($fromAddress);
            $ehloHost = $domeniuExpeditor !== ''
                ? $domeniuExpeditor
                : (preg_replace('/[^a-zA-Z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost');
            $trimite('EHLO ' . $ehloHost, [250]);

            if (!$sslDirect && $criptare === 'tls') {
                $trimite('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP: negocierea STARTTLS a eșuat.');
                }
                $trimite('EHLO ' . $ehloHost, [250]);
            }

            if ($utilizator !== '') {
                $trimite('AUTH LOGIN', [334]);
                $trimite(base64_encode($utilizator), [334]);
                $trimite(base64_encode($parola), [235]);
            }

            // Plicul: expeditorul real e contul SMTP, ca SPF/DKIM să se alinieze.
            $expeditorPlic = filter_var($utilizator, FILTER_VALIDATE_EMAIL) ? $utilizator : $fromAddress;
            $trimite('MAIL FROM:<' . $expeditorPlic . '>', [250]);
            $trimite('RCPT TO:<' . $to . '>', [250, 251]);
            $trimite('DATA', [354]);

            $subiectCodat = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $numeCodat = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
            // Mesajele doar-HTML sunt penalizate de filtrele antispam; trimitem
            // și varianta text, în multipart/alternative.
            $separator = 'bnd_' . bin2hex(random_bytes(12));
            $anteturi = [
                'Date: ' . date('r'),
                'From: ' . $numeCodat . ' <' . $expeditorPlic . '>',
                'Reply-To: ' . $fromAddress,
                'To: <' . $to . '>',
                'Subject: ' . $subiectCodat,
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $separator . '"',
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $ehloHost . '>',
            ];
            $corp = '--' . $separator . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode(self::htmlInText($html)), 76, "\r\n")
                . '--' . $separator . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($html), 76, "\r\n")
                . '--' . $separator . "--\r\n";
            fwrite($fp, implode("\r\n", $anteturi) . "\r\n\r\n" . $corp . "\r\n.\r\n");
            $raspunsData = $citeste();
            if ((int) substr($raspunsData, 0, 3) !== 250) {
                throw new RuntimeException('SMTP a refuzat mesajul: ' . trim($raspunsData));
            }

            fwrite($fp, "QUIT\r\n");
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /** Domeniul dintr-o adresă de email („no-reply@bioscem.ro" → „bioscem.ro"). */
    private static function domeniuDinEmail(string $email): string
    {
        $pozitie = strrpos($email, '@');
        if ($pozitie === false) {
            return '';
        }
        $domeniu = preg_replace('/[^a-zA-Z0-9.-]/', '', substr($email, $pozitie + 1)) ?? '';
        // „localhost" nu e un domeniu real; mai bine cădem pe alternativă.
        return $domeniu === 'localhost' ? '' : $domeniu;
    }

    /** Varianta text a unui email HTML, pentru partea text/plain. */
    public static function htmlInText(string $html): string
    {
        // Linkurile devin „text (adresa)", ca să nu se piardă la citirea în text.
        $text = preg_replace(
            '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            '$2 ($1)',
            $html
        ) ?? $html;
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $text) ?? $text;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|tr|h[1-6]|li)>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Curățăm spațiile și rândurile goale rămase din HTML.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);
        return $text !== '' ? $text : 'Deschide acest mesaj într-un client de email care afișează HTML.';
    }

    private static function sendWithPhpMail(
        string $to,
        string $subject,
        string $html,
        string $fromName,
        string $fromAddress
    ): void {
        if (!function_exists('mail')) {
            throw new RuntimeException('Functia mail() nu este disponibila pe server.');
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::sanitizeHeader($fromName) . ' <' . $fromAddress . '>',
            'Reply-To: ' . $fromAddress,
            'X-Mailer: PHP/' . phpversion(),
        ];

        $ok = @mail($to, $subject, $html, implode("\r\n", $headers));
        if (!$ok) {
            throw new RuntimeException('mail() a esuat la trimiterea email-ului.');
        }
    }

    private static function sendWithSendGrid(
        string $to,
        string $subject,
        string $html,
        string $fromName,
        string $fromAddress,
        array $settings
    ): void {
        $apiKey = trim((string) ($settings['sendgrid_api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('Cheia API SendGrid nu este configurata.');
        }

        $payload = [
            'personalizations' => [[
                'to' => [['email' => $to]],
            ]],
            'from' => [
                'email' => $fromAddress,
                'name' => $fromName,
            ],
            'subject' => $subject,
            'content' => [[
                'type' => 'text/html',
                'value' => $html,
            ]],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Nu am putut serializa payload-ul SendGrid.');
        }

        $response = self::rawRequest(
            'POST',
            'https://api.sendgrid.com/v3/mail/send',
            [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            $body
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = trim($response['body']) !== '' ? trim($response['body']) : ('SendGrid HTTP ' . $response['status']);
            throw new RuntimeException('SendGrid error: ' . $message);
        }
    }

    private static function rawRequest(string $method, string $url, array $headers, ?string $body): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new RuntimeException('HTTP cURL error: ' . $error);
            }

            return [
                'status' => $httpCode,
                'body' => (string) $response,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 25,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('HTTP request error (fopen).');
        }

        $statusLine = '';
        foreach (($http_response_header ?? []) as $headerLine) {
            if (str_starts_with((string) $headerLine, 'HTTP/')) {
                $statusLine = (string) $headerLine;
                break;
            }
        }
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $status = isset($matches[1]) ? (int) $matches[1] : 200;

        return [
            'status' => $status,
            'body' => (string) $response,
        ];
    }

    private static function resolveProvider(array $settings): string
    {
        $provider = strtolower(trim((string) ($settings['email_delivery_method'] ?? 'smtp')));
        return in_array($provider, ['smtp', 'sendgrid'], true) ? $provider : 'smtp';
    }

    private static function logEmailSend(?PDO $db, array $payload): void
    {
        if (!$db instanceof PDO) {
            return;
        }
        self::ensureEmailSendHistorySchema($db);

        $status = trim((string) ($payload['status'] ?? 'sent'));
        if (!in_array($status, ['sent', 'failed'], true)) {
            $status = 'failed';
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $metaJson = $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        if (!is_string($metaJson)) {
            $metaJson = null;
        }

        $orderId = max(0, (int) ($payload['order_id'] ?? 0));
        $emailType = trim((string) ($payload['email_type'] ?? 'custom'));
        if ($emailType === '') {
            $emailType = 'custom';
        }
        $source = trim((string) ($payload['source'] ?? 'system'));
        if ($source === '') {
            $source = 'system';
        }
        $recipient = trim((string) ($payload['recipient'] ?? ''));
        $subject = trim((string) ($payload['subject'] ?? ''));
        $provider = trim((string) ($payload['provider'] ?? ''));
        $error = trim((string) ($payload['error_message'] ?? ''));

        try {
            $stmt = $db->prepare(
                'INSERT INTO email_send_history (order_id, email_type, source, recipient, subject, status, error_message, provider, meta_json, sent_at)
                 VALUES (:order_id, :email_type, :source, :recipient, :subject, :status, :error_message, :provider, :meta_json, :sent_at)'
            );
            $stmt->execute([
                'order_id' => $orderId > 0 ? $orderId : null,
                'email_type' => substr($emailType, 0, 80),
                'source' => substr($source, 0, 80),
                'recipient' => substr($recipient, 0, 190),
                'subject' => substr($subject, 0, 255),
                'status' => $status,
                'error_message' => $error !== '' ? substr($error, 0, 2000) : null,
                'provider' => $provider !== '' ? substr($provider, 0, 30) : null,
                'meta_json' => $metaJson,
                'sent_at' => $status === 'sent' ? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') : null,
            ]);
        } catch (Throwable) {
        }
    }

    private static function sanitizeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        return trim($value);
    }

    private static function applyTemplate(string $template, array $vars): string
    {
        return strtr($template, $vars);
    }

    /** Caseta cu datele plății prin OP, gata stilizată pentru email. */
    private static function paymentInstructionsHtml(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        return '<div style="margin:18px 0;padding:14px 16px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc;">'
            . '<p style="margin:0 0 8px;font-weight:700;color:#0f172a;">Cum plătiți prin ordin de plată</p>'
            . '<p style="margin:0;color:#1f2937;white-space:pre-line;">'
            . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p></div>';
    }

    private static function templateVars(array $context): array
    {
        $customerName = trim((string) ($context['customer_name'] ?? ''));
        if ($customerName === '') {
            $customerName = 'Client';
        }

        $orderNumber = trim((string) ($context['order_number'] ?? ''));
        $awb = trim((string) ($context['awb'] ?? ''));
        $trackingUrlRaw = trim((string) ($context['tracking_url'] ?? ''));
        if ($trackingUrlRaw === '' && $awb !== '') {
            $trackingUrlRaw = FanCourierGateway::trackingUrl($awb);
        }

        $safeCustomerName = htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeOrderNumber = htmlspecialchars($orderNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeAwb = htmlspecialchars($awb, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeTrackingUrl = htmlspecialchars(AppUrl::absolut($trackingUrlRaw), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Comanda poate pleca în mai multe colete (din depozite diferite):
        // {{tracking_link}} devine câte un buton pentru fiecare AWB.
        $trackingLinks = [];
        foreach ((array) ($context['tracking_links'] ?? []) as $link) {
            if (!is_array($link)) {
                continue;
            }
            $linkAwb = trim((string) ($link['awb'] ?? ''));
            $linkUrl = trim((string) ($link['url'] ?? ''));
            if ($linkAwb === '' || $linkUrl === '') {
                continue;
            }
            $trackingLinks[] = ['awb' => $linkAwb, 'url' => $linkUrl];
        }

        $butonStil = 'display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;padding:10px 14px;border-radius:8px;font-weight:600;';
        if (count($trackingLinks) > 1) {
            $butoane = [];
            foreach ($trackingLinks as $i => $link) {
                $butoane[] = '<a href="' . htmlspecialchars($link['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '" style="' . $butonStil . 'margin:2px 0;">Urmareste coletul ' . ($i + 1) . ' ('
                    . htmlspecialchars($link['awb'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')</a>';
            }
            $safeTrackingLink = implode('<br>', $butoane);
        } else {
            $safeTrackingLink = $safeTrackingUrl !== ''
                ? '<a href="' . $safeTrackingUrl . '" style="' . $butonStil . '">Urmareste coletul pe FAN Courier</a>'
                : '';
        }
        $cartSummary = trim((string) ($context['cart_summary'] ?? ''));
        $safeCartSummary = nl2br(htmlspecialchars($cartSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $abandonedCartSummary = self::abandonedCartSummaryData($cartSummary);
        $cartItemsHtml = trim((string) ($context['cart_items_html'] ?? ''));
        if ($cartItemsHtml === '') {
            $cartItemsHtml = (string) ($abandonedCartSummary['items_html'] ?? '');
        }
        if ($cartItemsHtml === '') {
            $cartItemsHtml = '<p style="margin:0;color:#64748b;">Nu există produse în coș momentan.</p>';
        }
        $cartTotal = trim((string) ($context['cart_total'] ?? ''));
        if ($cartTotal === '') {
            $cartTotal = (string) ($abandonedCartSummary['total'] ?? '0,00 lei');
        }
        $cartActionUrlRaw = trim((string) ($context['cart_action_url'] ?? ''));
        if ($cartActionUrlRaw === '') {
            $cartActionUrlRaw = trim((string) ($context['order_action_url'] ?? ''));
        }
        if ($cartActionUrlRaw === '') {
            $cartActionUrlRaw = '/cos';
        }
        $safeCartActionUrl = htmlspecialchars(AppUrl::absolut($cartActionUrlRaw), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $estimatedDelivery = trim((string) ($context['estimated_delivery'] ?? ''));
        if ($estimatedDelivery === '') {
            $estimatedDelivery = '2-3 zile lucratoare';
        }
        $orderStatus = trim((string) ($context['order_status'] ?? ''));
        if ($orderStatus === '') {
            $orderStatus = 'În procesare';
        }
        $storeName = trim((string) ($context['store_name'] ?? ''));
        if ($storeName === '') {
            $storeName = 'Bioscem';
        }
        $customerEmail = trim((string) ($context['customer_email'] ?? ''));
        if ($customerEmail === '') {
            $customerEmail = trim((string) ($context['to'] ?? ''));
        }
        $courierName = trim((string) ($context['courier_name'] ?? ''));
        if ($courierName === '') {
            $courierName = $awb !== '' ? 'FAN Courier' : 'Curier rapid';
        }
        $orderDate = trim((string) ($context['order_date'] ?? ''));
        if ($orderDate === '') {
            $orderDate = date('j F Y');
        }
        $orderTotal = trim((string) ($context['order_total'] ?? ''));
        if ($orderTotal === '') {
            $orderTotal = '0,00 lei';
        }
        $orderSummary = trim((string) ($context['order_summary'] ?? ''));
        if ($orderSummary === '') {
            $orderSummary = 'Produsele comenzii nu sunt disponibile momentan.';
        }
        $safeOrderSummary = nl2br(htmlspecialchars($orderSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $orderItemsHtml = trim((string) ($context['order_items_html'] ?? ''));
        if ($orderItemsHtml === '') {
            $orderItemsHtml = '<p style="margin:0;color:#64748b;">Produsele comenzii nu sunt disponibile momentan.</p>';
        }
        $orderActionUrl = trim((string) ($context['order_action_url'] ?? $trackingUrlRaw));
        $safeOrderActionUrl = htmlspecialchars(AppUrl::absolut($orderActionUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $year = trim((string) ($context['year'] ?? date('Y')));
        if ($year === '') {
            $year = date('Y');
        }

        return [
            '{{customer_name}}' => $safeCustomerName,
            '{{order_number}}' => $safeOrderNumber,
            '{{awb}}' => $safeAwb,
            '{{tracking_url}}' => $safeTrackingUrl,
            '{{tracking_link}}' => $safeTrackingLink,
            '{{cart_summary}}' => $safeCartSummary,
            '{{cart_items_html}}' => $cartItemsHtml,
            '{{cart_total}}' => htmlspecialchars($cartTotal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{cart_action_url}}' => $safeCartActionUrl,
            '{{order_status}}' => htmlspecialchars($orderStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{estimated_delivery}}' => htmlspecialchars($estimatedDelivery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{store_name}}' => htmlspecialchars($storeName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{customer_email}}' => htmlspecialchars($customerEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{courier_name}}' => htmlspecialchars($courierName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{order_date}}' => htmlspecialchars($orderDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{order_total}}' => htmlspecialchars($orderTotal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{order_summary}}' => $safeOrderSummary,
            '{{order_items_html}}' => $orderItemsHtml,
            '{{order_summary_rows}}' => $orderItemsHtml,
            '{{order_totals_html}}' => (string) ($context['order_totals_html'] ?? ''),
            '{{order_action_url}}' => $safeOrderActionUrl,
            '{{year}}' => htmlspecialchars($year, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{payment_instructions}}' => self::paymentInstructionsHtml(
                (string) ($context['payment_instructions'] ?? ''),
            ),
            // Aceleaşi date, dar goale-goluţe: şablonul de OP îşi aşază singur
            // chenarul şi titlul, ca să nu iasă două titluri unul sub altul.
            '{{payment_details}}' => nl2br(htmlspecialchars(
                trim((string) ($context['payment_details'] ?? '')),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            )),
        ];
    }

    /** Emailul de bun venit la crearea contului, în stilul template-urilor avansate. */
    private static function accountCreatedAdvancedDefaultBody(): string
    {
        return '<div style="text-align:center;padding-top:8px;">'
            . '<div style="margin:0 auto 12px;color:#2f8d5b;font-size:56px;line-height:1;">🌿</div>'
            . '<h2 style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2>'
            . '</div>'
            . '<p style="margin:28px 0 10px;font-size:34px;line-height:1.1;color:#0f172a;font-weight:700;">Bine ai venit, {{customer_name}}! 🎉</p>'
            . '<p style="margin:0 0 18px;color:#4f6b66;font-size:16px;line-height:1.55;">'
            . 'Contul tău a fost creat cu succes. De acum îți poți urmări comenzile și livrările, '
            . 'aduni puncte de fidelitate la fiecare comandă și primești recomandări potrivite nevoilor tale.'
            . '</p>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f4f7f4;border-radius:14px;padding:16px 18px;margin:0 0 22px;">'
            . '<tr><td style="padding:6px 8px;color:#536f69;font-size:15px;">Contul tău</td>'
            . '<td style="padding:6px 8px;color:#0f172a;font-size:15px;font-weight:600;text-align:right;">{{customer_email}}</td></tr>'
            . '</table>'
            . '<p style="margin:0 0 24px;text-align:center;">'
            . '<a href="{{order_action_url}}" style="display:inline-block;min-width:260px;padding:14px 22px;border-radius:14px;background:#2f8d5b;color:#ffffff;text-decoration:none;font-weight:700;font-size:18px;line-height:1.2;">Intră în contul tău →</a>'
            . '</p>'
            . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;">'
            . '<p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p>'
            . '<p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>';
    }

    private static function newOrderLegacyBodyCandidates(): array
    {
        return [
            trim('<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} a fost inregistrata cu succes.</p>'),
            trim('<p>Bună {{customer_name}},</p><p>Comanda ta {{order_number}} a fost înregistrată cu succes.</p>'),
        ];
    }

    private static function newOrderLegacySubjectCandidates(): array
    {
        return [
            trim('Comanda noua {{order_number}}'),
            trim('Comandă nouă {{order_number}}'),
        ];
    }

    private static function processingLegacyBodyCandidates(): array
    {
        return [
            trim('<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} este in procesare.</p>'),
            trim('<p>Bună {{customer_name}},</p><p>Comanda ta {{order_number}} este în procesare.</p>'),
        ];
    }

    private static function processingLegacySubjectCandidates(): array
    {
        return [
            trim('Comanda {{order_number}} este in procesare'),
            trim('Comandă {{order_number}} este în procesare'),
        ];
    }

    private static function shippedLegacyBodyCandidates(): array
    {
        return [
            trim('<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} a fost expediata.</p><p>AWB: <strong>{{awb}}</strong></p><p>{{tracking_link}}</p>'),
            trim('<p>Bună {{customer_name}},</p><p>Comanda ta {{order_number}} a fost expediată.</p><p>AWB: <strong>{{awb}}</strong></p><p>{{tracking_link}}</p>'),
        ];
    }

    private static function shippedLegacySubjectCandidates(): array
    {
        return [
            trim('Comanda {{order_number}} a fost expediata'),
            trim('Comandă {{order_number}} a fost expediată'),
        ];
    }

    private static function deliveredLegacyBodyCandidates(): array
    {
        return [
            trim('<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} este finalizata, iar coletul a fost predat catre FAN Courier.</p><p>Cod urmarire (AWB): <strong>{{awb}}</strong></p><p>{{tracking_link}}</p><p>Daca butonul nu functioneaza: {{tracking_url}}</p>'),
            trim('<p>Bună {{customer_name}},</p><p>Comanda ta {{order_number}} este finalizată, iar coletul a fost predat către FAN Courier.</p><p>Cod urmărire (AWB): <strong>{{awb}}</strong></p><p>{{tracking_link}}</p><p>Dacă butonul nu funcționează: {{tracking_url}}</p>'),
        ];
    }

    private static function deliveredLegacySubjectCandidates(): array
    {
        return [
            trim('Comanda finalizata {{order_number}}'),
            trim('Comandă finalizată {{order_number}}'),
            trim('Comandă livrată {{order_number}}'),
        ];
    }

    private static function cancelledLegacyBodyCandidates(): array
    {
        return [
            trim('<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} a fost anulata.</p>'),
            trim('<p>Bună {{customer_name}},</p><p>Comanda ta {{order_number}} a fost anulată.</p>'),
        ];
    }

    private static function cancelledLegacySubjectCandidates(): array
    {
        return [
            trim('Comanda {{order_number}} a fost anulata'),
            trim('Comandă {{order_number}} a fost anulată'),
            trim('Comandă anulată {{order_number}}'),
        ];
    }

    private static function abandonedCartLegacyBodyCandidates(): array
    {
        return [
            trim('<p>Buna {{customer_name}},</p><p>Se pare ca ai ramas cu produse in cos.</p><p>{{cart_summary}}</p>'),
            trim('<p>Buna {{customer_name}},</p><p>Se pare ca ai ramas cu produse in cos.</p><p>{{cart_summary}}</p><p>Te asteptam sa finalizezi comanda.</p>'),
            trim('<p>Bună {{customer_name}},</p><p>Se pare că ai rămas cu produse în coș.</p><p>{{cart_summary}}</p>'),
            trim('<p>Bună {{customer_name}},</p><p>Se pare că ai rămas cu produse în coș.</p><p>{{cart_summary}}</p><p>Te așteptăm să finalizezi comanda.</p>'),
        ];
    }

    private static function abandonedCartLegacySubjectCandidates(): array
    {
        return [
            trim('Ai uitat produse in cos'),
            trim('Ai uitat produse în coș'),
            trim('Ai uitat produse în coșul tău'),
        ];
    }

    private static function processingBodyNeedsUpgrade(string $bodyTemplate): bool
    {
        $legacyBodies = self::processingLegacyBodyCandidates();
        if (in_array(trim($bodyTemplate), $legacyBodies, true)) {
            return true;
        }

        $compact = self::compactTemplateText($bodyTemplate);
        $hasOrderActionButton = str_contains($compact, '{{order_action_url}}')
            || str_contains($compact, 'urmarestecomanda')
            || str_contains($compact, 'vezicomanda');
        $hasSummarySection = str_contains($compact, '{{order_items_html}}') || str_contains($compact, 'rezumatcomanda');
        if (
            str_contains($compact, '{{order_number}}')
            && str_contains($compact, '{{order_status}}')
            && $hasOrderActionButton
        ) {
            return true;
        }
        if (
            str_contains($compact, '{{order_number}}')
            && str_contains($compact, '{{order_status}}')
            && !$hasSummarySection
        ) {
            return true;
        }

        return str_contains($compact, 'nr.comanda')
            && str_contains($compact, '{{order_number}}')
            && str_contains($compact, '{{order_status}}')
            && str_contains($compact, 'livrareestimata')
            && str_contains($compact, '{{estimated_delivery}}')
            && str_contains($compact, 'urmarestecomanda');
    }

    private static function shippedBodyNeedsUpgrade(string $bodyTemplate): bool
    {
        $legacyBodies = self::shippedLegacyBodyCandidates();
        if (in_array(trim($bodyTemplate), $legacyBodies, true)) {
            return true;
        }

        $compact = self::compactTemplateText($bodyTemplate);
        $hasSummarySection = str_contains($compact, '{{order_items_html}}') || str_contains($compact, 'rezumatcomanda');
        if (
            str_contains($compact, '{{order_number}}')
            && str_contains($compact, '{{awb}}')
            && str_contains($compact, '{{tracking_url}}')
            && !$hasSummarySection
        ) {
            return true;
        }

        // Upgrade first advanced shipped variant where order number was too large on mobile.
        if (
            str_contains($compact, '{{order_number}}')
            && str_contains($compact, '{{awb}}')
            && str_contains($compact, '{{tracking_url}}')
            && str_contains($compact, 'nr.comanda')
            && str_contains($compact, 'font-size:34px')
        ) {
            return true;
        }

        return str_contains($compact, '{{order_number}}')
            && str_contains($compact, '{{awb}}')
            && str_contains($compact, '{{tracking_link}}')
            && str_contains($compact, 'afostexpediata');
    }

    private static function newOrderBodyNeedsUpgrade(string $bodyTemplate): bool
    {
        $legacyBodies = self::newOrderLegacyBodyCandidates();
        if (in_array(trim($bodyTemplate), $legacyBodies, true)) {
            return true;
        }

        $compact = self::compactTemplateText($bodyTemplate);
        $looksLikeCurrentNewOrderTemplate = str_contains($compact, '{{order_number}}')
            && str_contains($compact, '{{order_date}}')
            && str_contains($compact, '{{order_items_html}}');
        if (!$looksLikeCurrentNewOrderTemplate) {
            return false;
        }

        return str_contains($compact, 'vezicomanda')
            || str_contains($compact, '{{order_action_url}}');
    }

    private static function deliveredBodyNeedsUpgrade(string $bodyTemplate): bool
    {
        $legacyBodies = self::deliveredLegacyBodyCandidates();
        if (in_array(trim($bodyTemplate), $legacyBodies, true)) {
            return true;
        }

        $compact = self::compactTemplateText($bodyTemplate);
        return str_contains($compact, '{{order_number}}')
            && str_contains($compact, '{{tracking_url}}')
            && str_contains($compact, 'finalizata')
            && !str_contains($compact, 'lasaorecenzie');
    }

    private static function cancelledBodyNeedsUpgrade(string $bodyTemplate): bool
    {
        $legacyBodies = self::cancelledLegacyBodyCandidates();
        if (in_array(trim($bodyTemplate), $legacyBodies, true)) {
            return true;
        }

        $compact = self::compactTemplateText($bodyTemplate);
        return str_contains($compact, '{{order_number}}')
            && str_contains($compact, 'anulata')
            && !str_contains($compact, '{{order_total}}');
    }

    private static function abandonedCartBodyNeedsUpgrade(string $bodyTemplate): bool
    {
        $legacyBodies = self::abandonedCartLegacyBodyCandidates();
        if (in_array(trim($bodyTemplate), $legacyBodies, true)) {
            return true;
        }

        $compact = self::compactTemplateText($bodyTemplate);
        return str_contains($compact, '{{cart_summary}}')
            && !str_contains($compact, '{{cart_items_html}}');
    }

    private static function abandonedCartSummaryData(string $cartSummary): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $cartSummary) ?: [];
        $cards = '';
        $total = 0.0;
        $hasAtLeastOneAmount = false;

        foreach ($lines as $lineRaw) {
            $line = trim((string) $lineRaw);
            if ($line === '') {
                continue;
            }

            $name = $line;
            $quantity = 1;
            $amount = 0.0;
            $hasAmount = false;

            if (preg_match('/^(.*?)\s+x(\d+)\s*-\s*([0-9\.,\s]+)\s*(?:ron|lei)?$/iu', $line, $matches)) {
                $name = trim((string) ($matches[1] ?? 'Produs'));
                $quantity = max(1, (int) ($matches[2] ?? 1));
                $amount = self::parseAbandonedAmount((string) ($matches[3] ?? '0'));
                $hasAmount = true;
            } elseif (preg_match('/^(.*?)\s*-\s*([0-9\.,\s]+)\s*(?:ron|lei)?$/iu', $line, $matches)) {
                $name = trim((string) ($matches[1] ?? 'Produs'));
                $amount = self::parseAbandonedAmount((string) ($matches[2] ?? '0'));
                $hasAmount = true;
            }

            if ($name === '') {
                $name = 'Produs';
            }

            $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $qtyLabel = $quantity > 1
                ? '<p style="margin:4px 0 0;color:#5f7680;font-size:14px;line-height:1.35;">Cantitate: ' . $quantity . '</p>'
                : '';
            $amountLabel = $hasAmount ? self::formatLeiAmount($amount) : '—';
            $safeAmountLabel = htmlspecialchars($amountLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $cards .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f4f7f4;border-radius:12px;padding:14px 16px;margin:0 0 10px;">'
                . '<div style="display:flex;align-items:center;gap:12px;">'
                . '<div style="width:54px;height:54px;border-radius:14px;background:#e7efe8;color:#2f8d5b;font-size:28px;line-height:1;display:flex;align-items:center;justify-content:center;">📦</div>'
                . '<div>'
                . '<p style="margin:0;color:#0f2532;font-size:22px;line-height:1.25;font-weight:700;">' . $safeName . '</p>'
                . $qtyLabel
                . '</div>'
                . '</div>'
                . '<div style="color:#0f2532;font-size:18px;line-height:1.2;font-weight:700;white-space:nowrap;">' . $safeAmountLabel . '</div>'
                . '</div>';

            if ($hasAmount) {
                $total += $amount;
                $hasAtLeastOneAmount = true;
            }
        }

        return [
            'items_html' => $cards,
            'total' => $hasAtLeastOneAmount ? self::formatLeiAmount($total) : '0,00 lei',
        ];
    }

    private static function parseAbandonedAmount(string $amount): float
    {
        $normalized = trim($amount);
        if ($normalized === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^0-9\.,-]/', '', $normalized) ?? '';
        if ($normalized === '') {
            return 0.0;
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                // Example: 1.234,56
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                // Example: 1,234.56
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            if (preg_match('/,\d{1,2}$/', $normalized) === 1) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastDot !== false) {
            if (preg_match('/\.\d{1,2}$/', $normalized) !== 1) {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        return (float) $normalized;
    }

    private static function formatLeiAmount(float $amount): string
    {
        return number_format(max(0.0, $amount), 2, ',', '.') . ' lei';
    }

    private static function compactTemplateText(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = strtr($value, [
            'ă' => 'a',
            'â' => 'a',
            'î' => 'i',
            'ș' => 's',
            'ş' => 's',
            'ț' => 't',
            'ţ' => 't',
        ]);
        $value = preg_replace('/\s+/', '', $value) ?? '';
        return trim($value);
    }

    private static function newOrderAdvancedDefaultBody(): string
    {
        return <<<'HTML'
<div style="text-align:center;padding-top:8px;">
  <div style="margin:0 auto 12px;color:#2f8d5b;font-size:56px;line-height:1;font-weight:700;">📦</div>
  <h2 style="margin:0;font-family:'Playfair Display',Georgia,serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2>
</div>

<p style="margin:28px 0 10px;font-size:38px;line-height:1.08;color:#0f172a;font-weight:700;">Bună, {{customer_name}}! 🌿</p>
<p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Îți mulțumim că ai ales {{store_name}}! Comanda ta a fost înregistrată și va fi procesată în curând.</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f4f7f4;border-radius:14px;padding:18px 18px 14px;margin:0 0 22px;">
  <tr>
    <td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Nr. comandă</td>
    <td style="padding:7px 8px;color:#0f172a;font-size:24px;line-height:1.15;font-weight:700;text-align:right;">#{{order_number}}</td>
  </tr>
  <tr>
    <td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Data</td>
    <td style="padding:7px 8px;color:#0f172a;font-size:17px;line-height:1.35;font-weight:600;text-align:right;">{{order_date}}</td>
  </tr>
</table>

<p style="margin:0 0 8px;color:#0f172a;font-size:32px;line-height:1.1;font-weight:700;">Rezumat Comandă:</p>
<div style="margin:0 0 18px;">
  {{order_items_html}}
</div>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 20px;">
  <tr>
    <td style="padding:12px 0;border-top:1px solid #e5e7eb;color:#0f172a;font-size:36px;line-height:1.1;font-weight:700;">Total</td>
    <td style="padding:12px 0;border-top:1px solid #e5e7eb;color:#0f172a;font-size:36px;line-height:1.1;font-weight:700;text-align:right;">{{order_total}}</td>
  </tr>
</table>

<hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;">
<p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p>
<p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>
HTML;
    }

    /**
     * Comanda plătită prin ordin de plată. Datele băncii nu stau aici, ci în
     * Setări plăți, de unde le ia şi pagina de checkout — un singur loc pentru
     * IBAN, ca să nu ajungă vreodată două valori diferite pe site şi pe email.
     */
    private static function newOrderOpAdvancedDefaultBody(): string
    {
        return <<<'HTML'
<div style="text-align:center;padding-top:8px;">
  <div style="margin:0 auto 12px;color:#2f8d5b;font-size:56px;line-height:1;font-weight:700;">🏦</div>
  <h2 style="margin:0;font-family:'Playfair Display',Georgia,serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2>
</div>

<p style="margin:28px 0 10px;font-size:34px;line-height:1.1;color:#0f172a;font-weight:700;">Bună ziua,</p>
<p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Vă mulțumim pentru comanda <strong>#{{order_number}}</strong>.</p>
<p style="margin:0 0 14px;color:#4f6b66;font-size:16px;line-height:1.55;">Pentru plata prin Ordin de Plată (OP), vă rugăm să utilizați următoarele date:</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f4f7f4;border-radius:14px;padding:18px;margin:0 0 22px;">
  <tr>
    <td style="padding:0 0 10px;color:#0f172a;font-size:15px;font-weight:700;letter-spacing:.06em;">DATE DE PLATĂ</td>
  </tr>
  <tr>
    <!-- Fără white-space:pre-line: {{payment_details}} vine deja cu <br>,
         iar cele două împreună ar rupe fiecare rând de două ori. -->
    <td style="padding:0 0 10px;color:#1f2937;font-size:16px;line-height:1.6;">{{payment_details}}</td>
  </tr>
  <tr>
    <td style="padding:10px 0 0;border-top:1px solid #dbe6e5;color:#0f172a;font-size:20px;line-height:1.4;font-weight:700;">Sumă: {{order_total}}</td>
  </tr>
  <tr>
    <td style="padding:6px 0 0;color:#1f2937;font-size:16px;line-height:1.5;">Detalii plată: Comanda #{{order_number}}</td>
  </tr>
</table>

<p style="margin:0 0 18px;color:#4f6b66;font-size:16px;line-height:1.55;">După efectuarea plății, vă rugăm să ne trimiteți dovada plății <strong>prin răspuns la acest email</strong>.</p>

<p style="margin:0 0 8px;color:#0f172a;font-size:28px;line-height:1.1;font-weight:700;">Rezumat comandă</p>
<div style="margin:0 0 6px;">
  {{order_items_html}}
</div>
<div style="margin:0 0 18px;">
  {{order_totals_html}}
</div>

<p style="margin:0 0 4px;color:#4f6b66;font-size:16px;line-height:1.55;">Vă mulțumim și vă dorim VITALITATE ȘI PROTECȚIE!</p>
<p style="margin:0 0 18px;color:#4f6b66;font-size:16px;line-height:1.55;">Echipa {{store_name}}</p>

<hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;">
<p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p>
<p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>
HTML;
    }

    private static function processingAdvancedDefaultBody(): string
    {
        return <<<'HTML'
<div style="text-align:center;padding-top:8px;">
  <div style="margin:0 auto 14px;width:92px;height:92px;border-radius:999px;border:3px solid #eaa325;color:#eaa325;font-size:56px;line-height:86px;font-weight:700;">⏱</div>
  <h2 style="margin:0;font-family:'Playfair Display',Georgia,serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2>
</div>

<p style="margin:28px 0 10px;font-size:38px;line-height:1.08;color:#0f172a;font-weight:700;">Bună, {{customer_name}}! ✨</p>
<p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Vești bune! Comanda ta este acum în curs de procesare. Echipa noastră pregătește cu grijă produsele tale.</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f4f6f4;border-radius:14px;padding:18px 18px 14px;margin:0 0 22px;">
  <tr>
    <td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Nr. comandă</td>
    <td style="padding:7px 8px;color:#0f172a;font-size:34px;line-height:1.05;font-weight:700;text-align:right;">#{{order_number}}</td>
  </tr>
  <tr>
    <td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Status</td>
    <td style="padding:7px 8px;color:#e69d1e;font-size:34px;line-height:1.05;font-weight:700;text-align:right;">{{order_status}}</td>
  </tr>
</table>

<p style="margin:0 0 8px;color:#0f172a;font-size:32px;line-height:1.1;font-weight:700;">Rezumat Comandă:</p>
<div style="margin:0 0 18px;">
  {{order_items_html}}
</div>

<hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;">
<p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p>
<p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>
HTML;
    }

    private static function shippedAdvancedDefaultBody(): string
    {
        return <<<'HTML'
<div style="text-align:center;padding-top:8px;">
  <div style="margin:0 auto 12px;color:#2f80ed;font-size:56px;line-height:1;font-weight:700;">🚚</div>
  <h2 style="margin:0;font-family:'Playfair Display',Georgia,serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2>
</div>

<p style="margin:28px 0 10px;font-size:38px;line-height:1.08;color:#0f172a;font-weight:700;">Bună, {{customer_name}}! 🚚</p>
<p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Comanda ta a fost expediată și este pe drum spre tine! Poți urmări coletul folosind linkul de mai jos.</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f4f7f4;border-radius:14px;padding:18px 18px 14px;margin:0 0 22px;">
  <tr>
    <td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Nr. comandă</td>
    <td style="padding:7px 8px;color:#0f172a;font-size:24px;line-height:1.15;font-weight:700;text-align:right;">#{{order_number}}</td>
  </tr>
  <tr>
    <td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">AWB</td>
    <td style="padding:7px 8px;color:#0f172a;font-size:18px;line-height:1.35;font-weight:700;text-align:right;">{{awb}}</td>
  </tr>
  <tr>
    <td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Curier</td>
    <td style="padding:7px 8px;color:#0f172a;font-size:17px;line-height:1.35;font-weight:600;text-align:right;">{{courier_name}}</td>
  </tr>
  <tr>
    <td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Livrare estimată</td>
    <td style="padding:7px 8px;color:#0f172a;font-size:17px;line-height:1.35;font-weight:600;text-align:right;">{{estimated_delivery}}</td>
  </tr>
</table>

<p style="margin:0 0 8px;color:#0f172a;font-size:32px;line-height:1.1;font-weight:700;">Rezumat Comandă:</p>
<div style="margin:0 0 18px;">
  {{order_items_html}}
</div>

<p style="margin:0 0 24px;text-align:center;">
  <a href="{{tracking_url}}" style="display:inline-block;min-width:260px;padding:14px 22px;border-radius:14px;background:#2f80ed;color:#ffffff;text-decoration:none;font-weight:700;font-size:18px;line-height:1.2;">Urmărește coletul →</a>
</p>

<hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;">
<p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p>
<p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>
HTML;
    }

    private static function deliveredAdvancedDefaultBody(): string
    {
        return <<<'HTML'
<div style="text-align:center;padding-top:8px;">
  <div style="margin:0 auto 12px;color:#2f8d5b;font-size:56px;line-height:1;font-weight:700;">✅</div>
  <h2 style="margin:0;font-family:'Playfair Display',Georgia,serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2>
</div>

<p style="margin:28px 0 10px;font-size:38px;line-height:1.08;color:#0f172a;font-weight:700;">Bună, {{customer_name}}! 🎉</p>
<p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Comanda ta a fost livrată cu succes! Sperăm că produsele noastre naturale îți vor aduce bucurie.</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f4f7f4;border-radius:14px;padding:18px 18px 14px;margin:0 0 22px;">
  <tr>
    <td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Nr. comandă</td>
    <td style="padding:7px 8px;color:#0f172a;font-size:34px;line-height:1.05;font-weight:700;text-align:right;">#{{order_number}}</td>
  </tr>
  <tr>
    <td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Livrat pe</td>
    <td style="padding:7px 8px;color:#0f172a;font-size:17px;line-height:1.35;font-weight:600;text-align:right;">{{order_date}}</td>
  </tr>
</table>

<p style="margin:0 0 24px;text-align:center;">
  <a href="{{order_action_url}}" style="display:inline-block;min-width:260px;padding:14px 22px;border-radius:14px;background:#2f8d5b;color:#ffffff;text-decoration:none;font-weight:700;font-size:18px;line-height:1.2;">Lasă o recenzie →</a>
</p>

<hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;">
<p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p>
<p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>
HTML;
    }

    private static function cancelledAdvancedDefaultBody(): string
    {
        return <<<'HTML'
<div style="text-align:center;padding-top:8px;">
  <div style="margin:0 auto 14px;width:92px;height:92px;border-radius:999px;border:3px solid #ef4444;color:#ef4444;font-size:56px;line-height:86px;font-weight:700;">✕</div>
  <h2 style="margin:0;font-family:'Playfair Display',Georgia,serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2>
</div>

<p style="margin:28px 0 10px;font-size:38px;line-height:1.08;color:#0f172a;font-weight:700;">Bună, {{customer_name}},</p>
<p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Comanda ta a fost anulată conform solicitării. Dacă ai plătit online, suma va fi returnată în 3-5 zile lucrătoare.</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f4f7f4;border-radius:14px;padding:18px 18px 14px;margin:0 0 22px;">
  <tr>
    <td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Nr. comandă</td>
    <td style="padding:7px 8px;color:#0f172a;font-size:34px;line-height:1.05;font-weight:700;text-align:right;">#{{order_number}}</td>
  </tr>
  <tr>
    <td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Sumă returnată</td>
    <td style="padding:7px 8px;color:#0f172a;font-size:17px;line-height:1.35;font-weight:600;text-align:right;">{{order_total}}</td>
  </tr>
</table>

<p style="margin:0 0 24px;text-align:center;">
  <a href="{{order_action_url}}" style="display:inline-block;min-width:260px;padding:14px 22px;border-radius:14px;background:#ef4444;color:#ffffff;text-decoration:none;font-weight:700;font-size:18px;line-height:1.2;">Contactează-ne →</a>
</p>

<hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;">
<p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p>
<p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>
HTML;
    }

    private static function abandonedCartAdvancedDefaultBody(): string
    {
        return <<<'HTML'
<div style="text-align:center;padding-top:8px;">
  <div style="margin:0 auto 12px;color:#eaa325;font-size:56px;line-height:1;font-weight:700;">🛒</div>
  <h2 style="margin:0;font-family:'Playfair Display',Georgia,serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2>
</div>

<p style="margin:28px 0 10px;font-size:38px;line-height:1.08;color:#0f172a;font-weight:700;">Bună, {{customer_name}}! 👋</p>
<p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Am observat că ai lăsat câteva produse în coș. Le păstrăm pentru tine, dar nu pentru mult timp!</p>

<p style="margin:0 0 8px;color:#0f172a;font-size:32px;line-height:1.1;font-weight:700;">Produse:</p>
<div style="margin:0 0 18px;">
  {{cart_items_html}}
</div>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 20px;">
  <tr>
    <td style="padding:12px 0;border-top:1px solid #e5e7eb;color:#0f172a;font-size:36px;line-height:1.1;font-weight:700;">Total</td>
    <td style="padding:12px 0;border-top:1px solid #e5e7eb;color:#0f172a;font-size:36px;line-height:1.1;font-weight:700;text-align:right;">{{cart_total}}</td>
  </tr>
</table>

<p style="margin:0 0 24px;text-align:center;">
  <a href="{{cart_action_url}}" style="display:inline-block;min-width:260px;padding:14px 22px;border-radius:14px;background:#eaa325;color:#ffffff;text-decoration:none;font-weight:700;font-size:18px;line-height:1.2;">Finalizează comanda →</a>
</p>

<hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;">
<p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p>
<p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>
HTML;
    }
}
