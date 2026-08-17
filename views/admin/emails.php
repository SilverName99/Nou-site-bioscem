<?php
$currentSection = (string) ($section ?? 'sender');
$templateRows = [];
foreach ($templateKeys as $key => $meta) {
    $subjectKey = (string) ($meta['subject_key'] ?? '');
    $bodyKey = (string) ($meta['body_key'] ?? '');
    $activeKey = (string) ($meta['active_key'] ?? '');
    $templateRows[$key] = [
        'key' => $key,
        'label' => (string) ($meta['label'] ?? $key),
        'subject' => (string) ($settings[$subjectKey] ?? $meta['default_subject'] ?? ''),
        'body' => (string) ($settings[$bodyKey] ?? $meta['default_body'] ?? ''),
        'is_active' => $activeKey === '' ? true : ((string) ($settings[$activeKey] ?? '1') === '1'),
    ];
}

$emailDeliveryMethod = strtolower((string) ($settings['email_delivery_method'] ?? 'smtp'));
if (!in_array($emailDeliveryMethod, ['smtp', 'sendgrid'], true)) {
    $emailDeliveryMethod = 'smtp';
}

$newsletterTemplates = is_array($newsletterTemplates ?? null) ? $newsletterTemplates : [];
$selectedNewsletter = is_array($selectedNewsletter ?? null) ? $selectedNewsletter : null;
$selectedNewsletterBlocks = [];
if (is_array($selectedNewsletter)) {
    $decoded = json_decode((string) ($selectedNewsletter['blocks_json'] ?? '[]'), true);
    if (is_array($decoded)) {
        $selectedNewsletterBlocks = $decoded;
    }
}
$newsletterTab = (string) ($newsletterTab ?? 'templates');
$ecommerceTemplates = is_array($ecommerceTemplates ?? null) ? $ecommerceTemplates : [];
$selectedEcommerceTemplateType = (string) ($selectedEcommerceTemplateType ?? '');
$selectedEcommerceTemplate = is_array($selectedEcommerceTemplate ?? null) ? $selectedEcommerceTemplate : null;
$selectedEcommerceRecipientMode = strtolower((string) ($selectedEcommerceTemplate['recipient_mode'] ?? 'client'));
if (!in_array($selectedEcommerceRecipientMode, ['client', 'admin', 'client_admin'], true)) {
    $selectedEcommerceRecipientMode = 'client';
}
$selectedEcommerceAdminRecipientsRaw = trim((string) ($selectedEcommerceTemplate['admin_recipients_raw'] ?? ''));
$selectedEcommerceBlocks = is_array($selectedEcommerceTemplate['blocks'] ?? null) ? $selectedEcommerceTemplate['blocks'] : [];
$newsletterCampaigns = is_array($newsletterCampaigns ?? null) ? $newsletterCampaigns : [];
$selectedCampaign = is_array($selectedCampaign ?? null) ? $selectedCampaign : null;
$optInForms = is_array($optInForms ?? null) ? $optInForms : [];
$selectedOptInForm = is_array($selectedOptInForm ?? null) ? $selectedOptInForm : null;
$selectedOptInFields = is_array($selectedOptInFields ?? null) ? $selectedOptInFields : [];
$contactMessages = is_array($contactMessages ?? null) ? $contactMessages : [];
$emailSendHistory = is_array($emailSendHistory ?? null) ? $emailSendHistory : [];
$emailSendHistoryTotal = max(0, (int) ($emailSendHistoryTotal ?? count($emailSendHistory)));
$emailSendHistoryPage = max(1, (int) ($emailSendHistoryPage ?? 1));
$emailSendHistoryPerPage = max(1, (int) ($emailSendHistoryPerPage ?? 50));
$emailSendHistoryTotalPages = max(1, (int) ($emailSendHistoryTotalPages ?? 1));
$emailSendHistoryFilters = is_array($emailSendHistoryFilters ?? null) ? $emailSendHistoryFilters : ['q' => '', 'status' => 'all', 'type' => ''];
$emailSendHistoryFilterQ = trim((string) ($emailSendHistoryFilters['q'] ?? ''));
$emailSendHistoryFilterStatus = trim((string) ($emailSendHistoryFilters['status'] ?? 'all'));
if (!in_array($emailSendHistoryFilterStatus, ['all', 'sent', 'failed'], true)) {
    $emailSendHistoryFilterStatus = 'all';
}
$emailSendHistoryFilterType = trim((string) ($emailSendHistoryFilters['type'] ?? ''));
$galleryImages = is_array($galleryImages ?? null) ? $galleryImages : [];
$campaignHourlyOpens = is_array($campaignHourlyOpens ?? null) ? $campaignHourlyOpens : [];
?>

<?php if ($currentSection === 'sender'): ?>
    <section class="panel">
        <h2 style="margin-top:0;">Setări generale trimitere</h2>
        <form method="post" action="/admin/emails" class="form-grid">
            <input type="hidden" name="section" value="sender">
            <div class="field">
                <label>Metodă trimitere</label>
                <select name="email_delivery_method" id="email-delivery-method">
                    <option value="smtp" <?= $emailDeliveryMethod === 'smtp' ? 'selected' : '' ?>>Folosire SMTP</option>
                    <option value="sendgrid" <?= $emailDeliveryMethod === 'sendgrid' ? 'selected' : '' ?>>Folosire SendGrid</option>
                </select>
            </div>
            <div class="field">
                <label>Nume expeditor</label>
                <input
                    type="text"
                    name="order_email_from_name"
                    value="<?= htmlspecialchars((string) ($settings['order_email_from_name'] ?? 'Bioscem'), ENT_QUOTES) ?>"
                >
            </div>
            <div class="field">
                <label>Email expeditor</label>
                <input
                    type="email"
                    name="order_email_from_address"
                    value="<?= htmlspecialchars((string) ($settings['order_email_from_address'] ?? 'no-reply@localhost'), ENT_QUOTES) ?>"
                >
            </div>

            <div class="email-delivery-fields smtp-fields form-grid" style="grid-column:1/-1;">
                <div class="field">
                    <label>SMTP Host</label>
                    <input type="text" name="smtp_host" value="<?= htmlspecialchars((string) ($settings['smtp_host'] ?? ''), ENT_QUOTES) ?>">
                </div>
                <div class="field">
                    <label>SMTP Port</label>
                    <input type="number" min="1" step="1" name="smtp_port" value="<?= htmlspecialchars((string) ($settings['smtp_port'] ?? '587'), ENT_QUOTES) ?>">
                </div>
                <div class="field">
                    <label>Criptare</label>
                    <?php $smtpEncryption = (string) ($settings['smtp_encryption'] ?? 'tls'); ?>
                    <select name="smtp_encryption">
                        <option value="none" <?= $smtpEncryption === 'none' ? 'selected' : '' ?>>Fără</option>
                        <option value="tls" <?= $smtpEncryption === 'tls' ? 'selected' : '' ?>>TLS</option>
                        <option value="ssl" <?= $smtpEncryption === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    </select>
                </div>
                <div class="field">
                    <label>SMTP Username</label>
                    <input type="text" name="smtp_username" value="<?= htmlspecialchars((string) ($settings['smtp_username'] ?? ''), ENT_QUOTES) ?>">
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>SMTP Password</label>
                    <input type="password" name="smtp_password" value="<?= htmlspecialchars((string) ($settings['smtp_password'] ?? ''), ENT_QUOTES) ?>">
                </div>
            </div>

            <div class="email-delivery-fields sendgrid-fields" style="grid-column:1/-1;">
                <div class="field">
                    <label>SendGrid API Key</label>
                    <input type="password" name="sendgrid_api_key" value="<?= htmlspecialchars((string) ($settings['sendgrid_api_key'] ?? ''), ENT_QUOTES) ?>">
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <button class="btn" type="submit">Salvează setările de trimitere</button>
            </div>
        </form>
    </section>

    <script>
    (() => {
        const select = document.getElementById('email-delivery-method');
        const smtpWrap = document.querySelector('.smtp-fields');
        const sendgridWrap = document.querySelector('.sendgrid-fields');

        const toggle = () => {
            const mode = select?.value || 'smtp';
            const smtpActive = mode === 'smtp';
            smtpWrap?.classList.toggle('is-hidden', !smtpActive);
            sendgridWrap?.classList.toggle('is-hidden', smtpActive);

            smtpWrap?.querySelectorAll('input, select, textarea').forEach((el) => {
                el.disabled = !smtpActive;
            });
            sendgridWrap?.querySelectorAll('input, select, textarea').forEach((el) => {
                el.disabled = smtpActive;
            });
            const sendgridKey = sendgridWrap?.querySelector('input[name="sendgrid_api_key"]');
            if (sendgridKey) {
                sendgridKey.required = !smtpActive;
            }
        };

        select?.addEventListener('change', toggle);
        toggle();
    })();
    </script>
<?php endif; ?>

<?php if ($currentSection === 'test'): ?>
    <section class="panel email-test-wrap">
        <h2 style="margin-top:0;">Trimite email test</h2>
        <div class="email-test-card">
            <h3 style="margin-top:0;">Configurare email test</h3>
            <form method="post" action="/admin/emails/test" class="field" style="gap:14px;">
                <div class="field">
                    <label>Email destinatar</label>
                    <input type="email" name="test_email" placeholder="email@exemplu.com" required>
                </div>
                <div class="field">
                    <label>Template (opțional)</label>
                    <select name="template_type">
                        <option value="">Fără template (email simplu)</option>
                        <?php foreach ($templateRows as $row): ?>
                            <option value="<?= htmlspecialchars($row['key'], ENT_QUOTES) ?>"><?= htmlspecialchars($row['label'], ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn email-test-submit" type="submit">✈ Trimite email test</button>
            </form>
        </div>
    </section>
<?php endif; ?>

<?php if ($currentSection === 'automation'): ?>
    <section class="panel">
        <h2 style="margin-top:0;">Automatizări active</h2>
        <ul style="margin:0;padding-left:18px;line-height:1.8;">
            <li><strong>new_order</strong>: la plasarea comenzii.</li>
            <li><strong>processing</strong>: când statusul devine <code>processing</code>.</li>
            <li><strong>shipped</strong>: când se generează AWB FAN.</li>
            <li><strong>delivered/finalizat</strong>: când statusul devine <code>completed</code> și există AWB.</li>
            <li><strong>cancelled</strong>: când statusul devine <code>cancelled</code>.</li>
            <li><strong>abandoned_cart</strong>: prin cron pe sesiuni cu coș neconvertit și email disponibil.</li>
        </ul>
    </section>

    <section class="panel">
        <h2 style="margin-top:0;">Setări abandon coș</h2>
        <form method="post" action="/admin/emails" class="form-grid">
            <input type="hidden" name="section" value="automation">
            <div class="field">
                <label>Trimite după (minute)</label>
                <input
                    type="number"
                    min="5"
                    step="1"
                    name="email_abandoned_after_minutes"
                    value="<?= htmlspecialchars((string) ($settings['email_abandoned_after_minutes'] ?? '60'), ENT_QUOTES) ?>"
                >
            </div>
            <div style="grid-column:1/-1;">
                <button class="btn" type="submit">Salvează setările de automatizare</button>
            </div>
        </form>
        <p style="margin-top:12px;color:#64748b;">
            Cron recomandat:
            <code>php /home/USER/public_html/scripts/abandoned-cart-emails.php --limit=100</code>
        </p>
    </section>
<?php endif; ?>

<?php if ($currentSection === 'newsletters'): ?>
    <?php
    $newsletterInitialBlocks = [];
    if (is_array($selectedNewsletter)) {
        $newsletterInitialBlocks = $selectedNewsletterBlocks;
        if ($newsletterInitialBlocks === []) {
            $newsletterInitialBlocks = [
                ['type' => 'header', 'content' => 'Titlul Newsletter-ului', 'align' => 'center', 'background' => '#1a7a5e', 'text_color' => '#ffffff'],
                ['type' => 'text', 'content' => 'Scrie conținutul tău aici. Poți modifica textul, culoarea și alinierea din panoul din dreapta.', 'align' => 'left', 'background' => '#ffffff', 'text_color' => '#1f2937'],
                ['type' => 'button', 'label' => 'Află mai multe', 'url' => '#', 'align' => 'center', 'background' => '#1a7a5e', 'text_color' => '#ffffff', 'radius' => 6],
            ];
        }
    }

    $ecomBlocks = [];
    if (is_array($selectedEcommerceTemplate)) {
        $ecomBlocks = $selectedEcommerceBlocks;
        if ($ecomBlocks === []) {
            $ecomBlocks = [
                ['type' => 'header', 'content' => 'Status comandă', 'align' => 'center', 'background' => '#1a7a5e', 'text_color' => '#ffffff'],
                ['type' => 'text', 'content' => 'Detalii despre comandă și status vor fi afișate aici.', 'align' => 'left', 'background' => '#ffffff', 'text_color' => '#1f2937'],
                ['type' => 'button', 'label' => 'Află mai multe', 'url' => '#', 'align' => 'center', 'background' => '#1a7a5e', 'text_color' => '#ffffff', 'radius' => 6],
            ];
        }
    }

    $ecommerceOrder = [
        'account_created' => 'Bun venit (cont nou)',
        'new_order' => 'Comandă nouă',
        'processing' => 'Comandă în procesare',
        'shipped' => 'Comandă expediată',
        'delivered' => 'Comandă livrată/finalizată',
        'cancelled' => 'Comandă anulată',
        'abandoned_cart' => 'Abandon coș',
    ];
    $ecommerceEmailCodes = [
        ['code' => '{{customer_name}}', 'description' => 'Numele clientului'],
        ['code' => '{{order_number}}', 'description' => 'Numărul comenzii'],
        ['code' => '{{order_date}}', 'description' => 'Data comenzii (ex: 6 Aprilie 2026)'],
        ['code' => '{{order_total}}', 'description' => 'Total comandă formatat (ex: 303,00 lei)'],
        ['code' => '{{order_summary}}', 'description' => 'Rezumat text al produselor din comandă'],
        ['code' => '{{order_items_html}}', 'description' => 'Rezumat produse în format carduri HTML'],
        ['code' => '{{order_action_url}}', 'description' => 'Link către pagina comenzii'],
        ['code' => '{{order_status}}', 'description' => 'Statusul comenzii (ex: În procesare)'],
        ['code' => '{{awb}}', 'description' => 'Codul AWB'],
        ['code' => '{{courier_name}}', 'description' => 'Numele curierului (ex: FAN Courier)'],
        ['code' => '{{tracking_url}}', 'description' => 'URL-ul direct de urmărire'],
        ['code' => '{{tracking_link}}', 'description' => 'Buton/link HTML preformatat pentru tracking'],
        ['code' => '{{estimated_delivery}}', 'description' => 'Intervalul de livrare estimat'],
        ['code' => '{{cart_summary}}', 'description' => 'Sumar text coș (fallback pentru abandon coș)'],
        ['code' => '{{cart_items_html}}', 'description' => 'Produse coș în format carduri HTML (abandon coș)'],
        ['code' => '{{cart_total}}', 'description' => 'Total coș formatat (ex: 214,00 lei)'],
        ['code' => '{{cart_action_url}}', 'description' => 'Link către coș/finalizare comandă'],
        ['code' => '{{store_name}}', 'description' => 'Numele magazinului (din setări email)'],
        ['code' => '{{customer_email}}', 'description' => 'Email-ul clientului'],
        ['code' => '{{year}}', 'description' => 'Anul curent'],
    ];
    $orderedEcommerceRows = [];
    foreach ($ecommerceOrder as $etype => $label) {
        if (isset($ecommerceTemplates[$etype])) {
            $orderedEcommerceRows[$etype] = $ecommerceTemplates[$etype];
            $orderedEcommerceRows[$etype]['label'] = $label;
        }
    }
    foreach ($ecommerceTemplates as $etype => $tpl) {
        if (!isset($orderedEcommerceRows[$etype])) {
            $orderedEcommerceRows[$etype] = $tpl;
        }
    }

    $listNameById = [];
    foreach ($newsletterLists as $listRow) {
        $listNameById[(int) ($listRow['id'] ?? 0)] = (string) ($listRow['name'] ?? '');
    }
    $optInInitialFields = is_array($selectedOptInFields) ? $selectedOptInFields : [];
    $optInLayoutColumns = 1;
    foreach ($optInInitialFields as $optInFieldRow) {
        if (!is_array($optInFieldRow)) {
            continue;
        }
        if (($optInFieldRow['width'] ?? 'full') === 'half') {
            $optInLayoutColumns = 2;
        }
        $rowType = trim((string) ($optInFieldRow['type'] ?? ''));
        if (!in_array($rowType, ['__layout', '__meta'], true)) {
            continue;
        }
        $columnsCandidate = (int) ($optInFieldRow['columns'] ?? $optInFieldRow['layout_columns'] ?? 1);
        if (in_array($columnsCandidate, [1, 2], true)) {
            $optInLayoutColumns = $columnsCandidate;
        }
    }
    $selectedCampaignBlocks = [];
    if (is_array($selectedCampaign)) {
        $decodedCampaignBlocks = json_decode((string) ($selectedCampaign['blocks_json'] ?? '[]'), true);
        if (is_array($decodedCampaignBlocks)) {
            $selectedCampaignBlocks = $decodedCampaignBlocks;
        }
    }
    $campaignInitialBlocks = $selectedCampaignBlocks;
    if (is_array($selectedCampaign) && $campaignInitialBlocks === []) {
        $campaignTextFallback = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($selectedCampaign['html_content'] ?? ''))));
        if ($campaignTextFallback === '') {
            $campaignTextFallback = 'Scrie conținutul newsletter-ului aici.';
        }
        $campaignInitialBlocks = [
            ['type' => 'header', 'content' => 'Titlul Newsletter-ului', 'align' => 'center', 'background' => '#1a7a5e', 'text_color' => '#ffffff'],
            ['type' => 'text', 'content' => $campaignTextFallback, 'align' => 'left', 'background' => '#ffffff', 'text_color' => '#1f2937'],
            ['type' => 'button', 'label' => 'Află mai multe', 'url' => '#', 'align' => 'center', 'background' => '#1a7a5e', 'text_color' => '#ffffff', 'radius' => 6],
        ];
    }
    $templateEditing = is_array($selectedNewsletter);
    $campaignEditing = is_array($selectedCampaign);
    $ecommerceEditing = is_array($selectedEcommerceTemplate);
    $optInEditing = is_array($selectedOptInForm);
    ?>
    <section class="panel">
        <div class="newsletter-page-head">
            <h2 style="margin:0;">Email-uri</h2>
            <p style="margin:4px 0 0;color:#64748b;">Gestionează template-uri, liste de abonați și formulare opt-in.</p>
        </div>
        <div class="newsletter-tabs">
            <a class="newsletter-tab <?= $newsletterTab === 'templates' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=templates">Template-uri</a>
            <a class="newsletter-tab <?= $newsletterTab === 'campaigns' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=campaigns">Newslettere</a>
            <a class="newsletter-tab <?= $newsletterTab === 'ecommerce' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=ecommerce">Email-uri ecommerce</a>
            <a class="newsletter-tab <?= $newsletterTab === 'subscribers' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=subscribers">Liste abonați</a>
            <a class="newsletter-tab <?= $newsletterTab === 'optin' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=optin">Formulare opt-in</a>
            <a class="newsletter-tab <?= $newsletterTab === 'contact_forms' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=contact_forms">Formulare contact</a>
            <a class="newsletter-tab <?= $newsletterTab === 'stats' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=stats">Statistici</a>
            <a class="newsletter-tab <?= $newsletterTab === 'history' ? 'active' : '' ?>" href="/admin/emails/newsletters?tab=history">Istoric trimitere email-uri</a>
        </div>

        <?php if ($newsletterTab === 'campaigns'): ?>
            <div class="newsletter-template-header-row">
                <h3 class="newsletter-block-title">Newslettere</h3>
                <button type="button" class="btn" id="campaign-new-btn">+ Newsletter nou</button>
            </div>

            <?php
            $campaignFormId = (int) ($selectedCampaign['id'] ?? 0);
            $campaignStatus = (string) ($selectedCampaign['status'] ?? 'draft');
            $campaignSubject = trim((string) ($selectedCampaign['subject'] ?? 'Newsletter'));
            $campaignTemplateType = in_array((string) ($selectedCampaign['template_type'] ?? 'newsletter'), ['newsletter', 'ecommerce'], true)
                ? (string) ($selectedCampaign['template_type'] ?? 'newsletter')
                : 'newsletter';
            $campaignTemplateRef = trim((string) ($selectedCampaign['template_ref'] ?? ''));
            $campaignScheduledAt = !empty($selectedCampaign['scheduled_at'])
                ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string) $selectedCampaign['scheduled_at'])), ENT_QUOTES)
                : '';
            ?>
            <div class="nl-stage" id="newsletter-campaign-stage" data-editing="<?= $campaignEditing ? '1' : '0' ?>">
                <div class="nl-stage-list">
                    <div class="newsletter-campaign-list">
                        <?php foreach ($newsletterCampaigns as $campaign): ?>
                            <?php
                            $campaignId = (int) ($campaign['id'] ?? 0);
                            $cardStatus = (string) ($campaign['status'] ?? 'draft');
                            $statusClass = $cardStatus === 'sent' ? 'ok' : ($cardStatus === 'scheduled' ? '' : 'off');
                            ?>
                            <article class="newsletter-campaign-card <?= ((int) ($selectedCampaign['id'] ?? 0) === $campaignId) ? 'selected' : '' ?>">
                                <div>
                                    <strong><?= htmlspecialchars((string) ($campaign['name'] ?? 'Newsletter'), ENT_QUOTES) ?></strong>
                                    <p><?= htmlspecialchars((string) ($campaign['subject'] ?? ''), ENT_QUOTES) ?></p>
                                    <?php if (!empty($campaign['scheduled_at'])): ?>
                                        <p><small>Programat: <?= htmlspecialchars((string) $campaign['scheduled_at'], ENT_QUOTES) ?></small></p>
                                    <?php endif; ?>
                                </div>
                                <div class="newsletter-campaign-meta">
                                    <div class="newsletter-campaign-metrics">
                                        <strong><?= (int) ($campaign['total_failed'] ?? 0) ?></strong>
                                        <span>Eșuate</span>
                                    </div>
                                    <div class="newsletter-campaign-actions inline">
                                        <a class="icon-btn" href="/admin/emails/builder?type=campaign&id=<?= $campaignId ?>" title="Editează newsletterul">✎</a>
                                        <form method="post" action="/admin/emails">
                                            <input type="hidden" name="section" value="newsletter-campaign-duplicate">
                                            <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                                            <button type="submit" class="icon-btn" title="Duplică newsletterul">⧉</button>
                                        </form>
                                        <form method="post" action="/admin/emails" onsubmit="return confirm('Ștergi newsletterul?');">
                                            <input type="hidden" name="section" value="newsletter-campaign-delete">
                                            <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                                            <button type="submit" class="icon-btn danger" title="Șterge newsletterul">🗑</button>
                                        </form>
                                    </div>
                                    <span class="status-pill <?= $statusClass ?>"><?= htmlspecialchars($cardStatus, ENT_QUOTES) ?></span>
                                    <div class="newsletter-campaign-metrics">
                                        <strong><?= (int) ($campaign['total_sent'] ?? 0) ?></strong>
                                        <span>Trimise</span>
                                    </div>
                                    <a href="?tab=stats&campaign_id=<?= $campaignId ?>" class="nl-stats-btn" title="Vezi statistici campanie">&#128202; Statistici</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($newsletterCampaigns === []): ?>
                            <article class="newsletter-campaign-card">
                                <div>
                                    <strong>Nu există newslettere încă</strong>
                                    <p>Apasă pe „+ Newsletter nou” pentru a crea primul newsletter.</p>
                                </div>
                            </article>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($campaignEditing): ?>
                    <div class="nl-stage-builder">
                        <?php
                        $scTotalSent   = (int) ($selectedCampaign['total_sent'] ?? 0);
                        $scTotalOpens  = (int) ($selectedCampaign['total_opens'] ?? 0);
                        $scTotalClicks = (int) ($selectedCampaign['total_clicks'] ?? 0);
                        $scOpenRate    = $scTotalSent > 0 ? round($scTotalOpens / $scTotalSent * 100, 1) : 0;
                        $scClickRate   = $scTotalSent > 0 ? round($scTotalClicks / $scTotalSent * 100, 1) : 0;
                        $hourlyData    = is_array($campaignHourlyOpens ?? null) ? $campaignHourlyOpens : [];
                        $maxHourly     = $hourlyData ? max($hourlyData) : 0;
                        ?>
                        <?php if ((string) ($selectedCampaign['status'] ?? '') === 'sent'): ?>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin-bottom:14px;">
                            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px;">
                                <div><strong><?= $scTotalSent ?></strong> <span style="color:#64748b;font-size:13px;">Trimise</span></div>
                                <div><strong><?= $scTotalOpens ?> (<?= $scOpenRate ?>%)</strong> <span style="color:#64748b;font-size:13px;">Deschideri</span></div>
                                <div><strong><?= $scTotalClicks ?> (<?= $scClickRate ?>%)</strong> <span style="color:#64748b;font-size:13px;">Click-uri</span></div>
                                <div><strong><?= (int) ($selectedCampaign['total_failed'] ?? 0) ?></strong> <span style="color:#64748b;font-size:13px;">Eșuate</span></div>
                            </div>
                            <?php if (!empty($hourlyData)): ?>
                            <div style="margin-top:8px;">
                                <p style="margin:0 0 6px;font-size:12px;color:#64748b;font-weight:600;">Deschideri pe oră</p>
                                <div style="display:flex;align-items:flex-end;gap:3px;height:80px;border-bottom:1px solid #e2e8f0;padding-bottom:2px;">
                                    <?php for ($hr = 0; $hr < 24; $hr++): ?>
                                        <?php
                                        $cnt = (int) ($hourlyData[$hr] ?? 0);
                                        $barH = $maxHourly > 0 ? max(2, (int) round($cnt / $maxHourly * 72)) : 2;
                                        $barColor = $cnt > 0 ? '#1a7a5e' : '#e2e8f0';
                                        ?>
                                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;" title="<?= $hr ?>:00 — <?= $cnt ?> deschideri">
                                            <div style="width:100%;background:<?= $barColor ?>;height:<?= $barH ?>px;border-radius:2px 2px 0 0;"></div>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                                <div style="display:flex;gap:3px;margin-top:2px;">
                                    <?php for ($hr = 0; $hr < 24; $hr += 6): ?>
                                        <div style="flex:6;font-size:10px;color:#94a3b8;text-align:left;"><?= $hr ?>h</div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <form method="post" action="/admin/emails" id="campaign-builder-form" class="field">
                            <input type="hidden" name="section" value="newsletter-campaign-save">
                            <input type="hidden" name="campaign_id" value="<?= $campaignFormId ?>">
                            <input type="hidden" name="campaign_template_type" value="<?= htmlspecialchars($campaignTemplateType, ENT_QUOTES) ?>">
                            <input type="hidden" name="campaign_template_ref" value="<?= htmlspecialchars($campaignTemplateRef, ENT_QUOTES) ?>">
                            <input type="hidden" name="campaign_blocks_json" id="campaign-builder-blocks-json">
                            <input type="hidden" name="campaign_html_content" id="campaign-builder-html-content">
                            <div class="form-grid" style="margin-bottom:10px;">
                                <div class="field">
                                    <label>Nume newsletter</label>
                                    <input type="text" name="campaign_name" id="campaign-builder-name" required value="<?= htmlspecialchars((string) ($selectedCampaign['name'] ?? ''), ENT_QUOTES) ?>">
                                </div>
                                <div class="field">
                                    <label>Subiect</label>
                                    <input type="text" name="campaign_subject" id="campaign-builder-subject" required value="<?= htmlspecialchars($campaignSubject, ENT_QUOTES) ?>">
                                </div>
                                <div class="field">
                                    <label>Listă abonați</label>
                                    <select name="campaign_list_id" id="campaign-builder-list-id">
                                        <?php foreach ($newsletterLists as $list): ?>
                                            <option value="<?= (int) ($list['id'] ?? 0) ?>" <?= ((int) ($selectedCampaign['subscriber_list_id'] ?? 0) === (int) ($list['id'] ?? 0)) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string) ($list['name'] ?? 'Listă'), ENT_QUOTES) ?> (<?= (int) ($list['subscribers_count'] ?? 0) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Status</label>
                                    <select name="campaign_status" id="campaign-builder-status">
                                        <option value="draft" <?= $campaignStatus === 'draft' ? 'selected' : '' ?>>draft</option>
                                        <option value="scheduled" <?= $campaignStatus === 'scheduled' ? 'selected' : '' ?>>scheduled</option>
                                    </select>
                                </div>
                                <div class="field" style="grid-column:1/-1;">
                                    <label>Programare (opțional)</label>
                                    <input class="newsletter-input-polish" type="datetime-local" name="campaign_scheduled_at" value="<?= $campaignScheduledAt ?>">
                                </div>
                            </div>
                            <div class="nl-builder" id="campaign-builder-root"></div>
                            <div style="margin-top:10px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                                <button type="button" class="btn btn-secondary" id="campaign-builder-back-btn" data-back-url="/admin/emails/newsletters?tab=campaigns">&larr; Înapoi</button>
                                <div style="display:flex;gap:8px;">
                                    <button type="button" class="btn btn-secondary" id="campaign-builder-preview-btn">Preview</button>
                                    <button class="btn" type="submit">Salvează newsletter</button>
                                </div>
                            </div>
                        </form>

                        <div class="newsletter-campaign-actions newsletter-newsletter-actions" style="margin-top:10px;">
                            <form method="post" action="/admin/emails" class="newsletter-inline-form">
                                <input type="hidden" name="section" value="newsletter-campaign-send-test">
                                <input type="hidden" name="campaign_id" value="<?= $campaignFormId ?>">
                                <input class="newsletter-input-polish" type="email" name="test_email" placeholder="email test" required>
                                <button class="btn btn-secondary" type="submit">Trimite test</button>
                            </form>
                            <form method="post" action="/admin/emails" class="newsletter-inline-form">
                                <input type="hidden" name="section" value="newsletter-campaign-send-now">
                                <input type="hidden" name="campaign_id" value="<?= $campaignFormId ?>">
                                <button class="btn" type="submit">Trimite acum</button>
                            </form>
                            <form method="post" action="/admin/emails" class="newsletter-inline-form">
                                <input type="hidden" name="section" value="newsletter-campaign-schedule">
                                <input type="hidden" name="campaign_id" value="<?= $campaignFormId ?>">
                                <input class="newsletter-input-polish" type="datetime-local" name="scheduled_at" required>
                                <button class="btn btn-secondary" type="submit">Programează</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="modal-overlay" id="campaign-create-modal">
                <div class="modal-card" style="max-width:560px;">
                    <header style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                        <h3 style="margin:0;">Newsletter nou</h3>
                        <button type="button" class="icon-btn" data-modal-close="campaign-create-modal">✕</button>
                    </header>
                    <form method="post" action="/admin/emails" class="form-grid" style="margin-top:10px;">
                        <input type="hidden" name="section" value="newsletter-campaign-create-quick">
                        <div class="field">
                            <label>Nume newsletter</label>
                            <input type="text" name="campaign_name" required>
                        </div>
                        <div class="field">
                            <label>Subiect</label>
                            <input type="text" name="campaign_subject" required>
                        </div>
                        <div class="field" style="grid-column:1/-1;">
                            <label>Listă abonați</label>
                            <select name="campaign_list_id" required>
                                <?php foreach ($newsletterLists as $list): ?>
                                    <option value="<?= (int) ($list['id'] ?? 0) ?>">
                                        <?= htmlspecialchars((string) ($list['name'] ?? 'Listă'), ENT_QUOTES) ?> (<?= (int) ($list['subscribers_count'] ?? 0) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                            <button type="button" class="btn btn-secondary" data-modal-close="campaign-create-modal">Anulează</button>
                            <button type="submit" class="btn">Creează newsletter</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($newsletterTab === 'templates'): ?>
            <div class="newsletter-template-header-row">
                <h3 class="newsletter-block-title">Template-urile mele (<?= (int) count($newsletterTemplates) ?>)</h3>
                <a href="/admin/emails/builder?type=template&id=0" class="btn">+ Template nou (Builder)</a>
            </div>

            <div class="nl-stage" id="newsletter-template-stage" data-editing="<?= $templateEditing ? '1' : '0' ?>">
                <div class="nl-stage-list">
                    <div class="newsletter-template-grid">
                        <?php foreach ($newsletterTemplates as $tpl): ?>
                            <?php $tplId = (int) ($tpl['id'] ?? 0); ?>
                            <article class="newsletter-template-card <?= ((int) ($selectedNewsletter['id'] ?? 0) === $tplId) ? 'selected' : '' ?>">
                                <div class="newsletter-template-preview">
                                    <div class="top"><?= htmlspecialchars((string) ($tpl['subject'] ?? ''), ENT_QUOTES) ?></div>
                                    <div class="body"><?= htmlspecialchars((string) substr(strip_tags((string) ($tpl['html_content'] ?? '')), 0, 80), ENT_QUOTES) ?></div>
                                </div>
                                <div class="newsletter-template-foot">
                                    <strong><?= htmlspecialchars((string) ($tpl['name'] ?? 'Template'), ENT_QUOTES) ?></strong>
                                    <div class="template-actions">
                                        <a class="icon-btn" href="/admin/emails/builder?type=template&id=<?= $tplId ?>" title="Editează template în builder nou">✎</a>
                                        <form method="post" action="/admin/emails" onsubmit="return confirm('Ștergi template-ul?');">
                                            <input type="hidden" name="section" value="newsletter-delete">
                                            <input type="hidden" name="newsletter_id" value="<?= $tplId ?>">
                                            <button type="submit" class="icon-btn danger">🗑</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($newsletterTemplates === []): ?>
                            <article class="newsletter-template-card">
                                <div class="newsletter-template-preview">
                                    <div class="top">Nu există template-uri</div>
                                    <div class="body">Apasă pe „+ Template nou” pentru a crea primul template.</div>
                                </div>
                            </article>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($templateEditing): ?>
                    <div class="nl-stage-builder">
                        <form method="post" action="/admin/emails" id="newsletter-template-builder-form" class="field">
                            <input type="hidden" name="section" value="newsletter-save">
                            <input type="hidden" name="newsletter_id" value="<?= (int) ($selectedNewsletter['id'] ?? 0) ?>">
                            <input type="hidden" name="newsletter_blocks_json" id="newsletter-template-blocks-json">
                            <input type="hidden" name="newsletter_html" id="newsletter-template-html-content">
                            <div class="form-grid" style="margin-bottom:10px;">
                                <div class="field">
                                    <label>Nume template</label>
                                    <input type="text" name="newsletter_name" id="newsletter-template-name" required value="<?= htmlspecialchars((string) ($selectedNewsletter['name'] ?? ''), ENT_QUOTES) ?>">
                                </div>
                                <div class="field">
                                    <label>Subiect</label>
                                    <input type="text" name="newsletter_subject" id="newsletter-template-subject" required value="<?= htmlspecialchars((string) ($selectedNewsletter['subject'] ?? ''), ENT_QUOTES) ?>">
                                </div>
                                <div class="field" style="grid-column:1/-1;">
                                    <label style="display:flex;align-items:center;gap:8px;">
                                        <input type="checkbox" name="newsletter_is_active" value="1" <?= ((int) ($selectedNewsletter['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                                        Template activ
                                    </label>
                                </div>
                            </div>
                            <div class="nl-builder" id="newsletter-template-builder-root"></div>
                            <div style="margin-top:10px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                                <button type="button" class="btn btn-secondary" id="template-builder-back-btn" data-back-url="/admin/emails/newsletters?tab=templates">&larr; Înapoi</button>
                                <div style="display:flex;gap:8px;">
                                    <button type="button" class="btn btn-secondary" id="template-builder-preview-btn">Preview</button>
                                    <button class="btn" type="submit">Salvează template</button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <div class="modal-overlay" id="template-create-modal">
                <div class="modal-card" style="max-width:560px;">
                    <header style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                        <h3 style="margin:0;">Template nou</h3>
                        <button type="button" class="icon-btn" data-modal-close="template-create-modal">✕</button>
                    </header>
                    <form method="post" action="/admin/emails" class="form-grid" style="margin-top:10px;">
                        <input type="hidden" name="section" value="newsletter-template-create-quick">
                        <div class="field">
                            <label>Nume template</label>
                            <input type="text" name="newsletter_name" required>
                        </div>
                        <div class="field">
                            <label>Subiect</label>
                            <input type="text" name="newsletter_subject" required>
                        </div>
                        <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                            <button type="button" class="btn btn-secondary" data-modal-close="template-create-modal">Anulează</button>
                            <button type="submit" class="btn">Creează template</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($newsletterTab === 'ecommerce'): ?>
            <div class="newsletter-template-header-row">
                <h3 class="newsletter-block-title">Email-uri ecommerce</h3>
            </div>

            <div class="nl-stage" id="newsletter-ecommerce-stage" data-editing="<?= $ecommerceEditing ? '1' : '0' ?>">
                <div class="nl-stage-list">
                    <div class="newsletter-campaign-list">
                        <?php foreach ($orderedEcommerceRows as $type => $tpl): ?>
                            <article class="newsletter-campaign-card compact <?= $selectedEcommerceTemplateType === (string) $type ? 'selected' : '' ?>">
                                <div>
                                    <strong><?= htmlspecialchars((string) ($tpl['label'] ?? $type), ENT_QUOTES) ?></strong>
                                </div>
                                <div><span class="status-pill <?= !empty($tpl['is_active']) ? 'ok' : 'off' ?>"><?= !empty($tpl['is_active']) ? 'activ' : 'inactiv' ?></span></div>
                                <div class="newsletter-campaign-actions">
                                    <a class="icon-btn" href="/admin/emails/builder?type=ecommerce&ref=<?= urlencode((string) $type) ?>" title="Editează template în builder nou">✎</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($ecommerceEditing): ?>
                    <div class="nl-stage-builder">
                        <form method="post" action="/admin/emails" id="ecommerce-template-builder-form" class="field">
                            <input type="hidden" name="section" value="newsletter-ecommerce-save">
                            <input type="hidden" name="ecommerce_template_type" value="<?= htmlspecialchars($selectedEcommerceTemplateType, ENT_QUOTES) ?>">
                            <input type="hidden" name="ecommerce_template_blocks_json" id="ecommerce-template-blocks-json">
                            <input type="hidden" name="ecommerce_template_html" id="ecommerce-template-html-content">
                            <div class="form-grid" style="margin-bottom:10px;">
                                <div class="field">
                                    <label>Subiect email ecommerce</label>
                                    <input
                                        type="text"
                                        name="ecommerce_template_subject"
                                        id="ecommerce-template-subject"
                                        required
                                        value="<?= htmlspecialchars((string) ($selectedEcommerceTemplate['subject'] ?? ''), ENT_QUOTES) ?>"
                                    >
                                </div>
                                <div class="field">
                                    <label style="display:flex;align-items:center;gap:8px;margin-top:30px;">
                                        <input
                                            type="checkbox"
                                            name="ecommerce_template_is_active"
                                            value="1"
                                            <?= !empty($selectedEcommerceTemplate['is_active']) ? 'checked' : '' ?>
                                        >
                                        Template activ
                                    </label>
                                </div>
                            </div>
                            <div class="nl-builder" id="ecommerce-template-builder-root"></div>
                            <div style="margin-top:10px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                                <button type="button" class="btn btn-secondary" id="ecommerce-builder-back-btn" data-back-url="/admin/emails/newsletters?tab=ecommerce">&larr; Înapoi</button>
                                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary" id="ecommerce-builder-preview-btn">Preview</button>
                                    <button type="button" class="btn btn-secondary" id="ecommerce-builder-codes-btn">Coduri Email</button>
                                    <button type="button" class="btn btn-secondary" id="ecommerce-builder-recipients-btn">Trimitere acest email la</button>
                                    <input
                                        class="newsletter-input-polish"
                                        type="email"
                                        name="test_email"
                                        form="ecommerce-template-send-test-form"
                                        placeholder="email test"
                                        style="min-width:180px;"
                                        required
                                    >
                                    <button class="btn btn-secondary" type="submit" form="ecommerce-template-send-test-form">Trimite test</button>
                                    <button class="btn" type="submit">Salvează template</button>
                                </div>
                            </div>
                        </form>
                        <form method="post" action="/admin/emails" id="ecommerce-template-send-test-form" style="display:none;">
                            <input type="hidden" name="section" value="newsletter-ecommerce-send-test">
                            <input type="hidden" name="ecommerce_template_type" value="<?= htmlspecialchars($selectedEcommerceTemplateType, ENT_QUOTES) ?>">
                            <input type="hidden" name="ecommerce_template_subject" id="ecommerce-template-test-subject">
                            <input type="hidden" name="ecommerce_template_html" id="ecommerce-template-test-html">
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($newsletterTab === 'subscribers'): ?>
            <div class="newsletter-subscribers-grid">
                <aside class="newsletter-lists-col">
                    <div class="newsletter-lists-head">
                        <strong>Liste (<?= (int) count($newsletterLists) ?>)</strong>
                        <form method="post" action="/admin/emails" class="newsletter-inline-form">
                            <input type="hidden" name="section" value="newsletter-list-save">
                            <input type="text" class="newsletter-input-polish" name="list_name" placeholder="Listă nouă" required>
                            <button class="btn btn-secondary" type="submit">+ Listă</button>
                        </form>
                    </div>
                    <div class="newsletter-lists">
                        <?php foreach ($newsletterLists as $list): ?>
                            <?php $listId = (int) ($list['id'] ?? 0); ?>
                            <div class="newsletter-list-item <?= $selectedListId === $listId ? 'active' : '' ?>">
                                <a href="/admin/emails/newsletters?tab=subscribers&list=<?= $listId ?>">
                                    <strong><?= htmlspecialchars((string) ($list['name'] ?? 'Listă'), ENT_QUOTES) ?></strong>
                                    <small><?= (int) ($list['subscribers_count'] ?? 0) ?> abonați</small>
                                </a>
                                <?php if ((int) ($list['is_default'] ?? 0) !== 1): ?>
                                    <form method="post" action="/admin/emails" onsubmit="return confirm('Ștergi lista?');">
                                        <input type="hidden" name="section" value="newsletter-list-delete">
                                        <input type="hidden" name="list_id" value="<?= $listId ?>">
                                        <button type="submit" class="icon-btn danger">🗑</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </aside>

                <div class="newsletter-subs-col">
                    <div class="newsletter-subs-head">
                        <strong>Abonați</strong>
                        <form method="post" action="/admin/emails" class="newsletter-inline-form">
                            <input type="hidden" name="section" value="newsletter-subscriber-save">
                            <input type="hidden" name="subscriber_list_id" value="<?= $selectedListId ?>">
                            <input type="email" class="newsletter-input-polish" name="subscriber_email" placeholder="Email" required>
                            <input type="text" class="newsletter-input-polish" name="subscriber_name" placeholder="Nume">
                            <button class="btn btn-secondary" type="submit">Adaugă abonat</button>
                        </form>
                    </div>
                    <form method="post" action="/admin/emails" enctype="multipart/form-data" class="newsletter-inline-form" style="margin:10px 0 12px;">
                        <input type="hidden" name="section" value="newsletter-subscribers-import">
                        <input type="file" class="newsletter-input-polish" name="newsletter_subscribers_file" accept=".csv,.xlsx" required>
                        <button class="btn btn-secondary" type="submit">Importă CSV/XLSX</button>
                    </form>
                    <p style="margin:0 0 10px;color:#64748b;font-size:12px;">
                        Format suportat: perechi repetate de coloane <strong>Email</strong> + <strong>Listă</strong>. Listele inexistente se creează automat.
                    </p>

                    <table class="table">
                        <thead>
                        <tr>
                            <th>Email</th>
                            <th>Nume</th>
                            <th>Status</th>
                            <th>Acțiuni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($listSubscribers as $subscriber): ?>
                            <?php
                            $subscriberId = (int) ($subscriber['id'] ?? 0);
                            $subStatus = (string) ($subscriber['status'] ?? 'active');
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($subscriber['email'] ?? ''), ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars((string) ($subscriber['name'] ?? ''), ENT_QUOTES) ?></td>
                                <td><span class="status-pill <?= $subStatus === 'active' ? 'ok' : 'off' ?>"><?= htmlspecialchars($subStatus, ENT_QUOTES) ?></span></td>
                                <td class="template-actions">
                                    <form method="post" action="/admin/emails" style="display:inline;">
                                        <input type="hidden" name="section" value="newsletter-subscriber-toggle">
                                        <input type="hidden" name="subscriber_id" value="<?= $subscriberId ?>">
                                        <input type="hidden" name="list_id" value="<?= $selectedListId ?>">
                                        <button type="submit" class="icon-btn" title="Activează/dezabonează">⟲</button>
                                    </form>
                                    <form method="post" action="/admin/emails" style="display:inline;" onsubmit="return confirm('Ștergi abonatul?');">
                                        <input type="hidden" name="section" value="newsletter-subscriber-delete">
                                        <input type="hidden" name="subscriber_id" value="<?= $subscriberId ?>">
                                        <input type="hidden" name="list_id" value="<?= $selectedListId ?>">
                                        <button type="submit" class="icon-btn danger">🗑</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($newsletterTab === 'optin'): ?>
            <div class="newsletter-template-header-row">
                <h3 class="newsletter-block-title">Formulare opt-in (<?= (int) count($optInForms) ?>)</h3>
                <button type="button" class="btn" id="optin-new-btn">+ Formular nou</button>
            </div>

            <div class="nl-stage" id="newsletter-optin-stage" data-editing="<?= $optInEditing ? '1' : '0' ?>">
                <div class="nl-stage-list">
                    <div class="newsletter-campaign-list">
                        <?php foreach ($optInForms as $form): ?>
                            <?php $formId = (int) ($form['id'] ?? 0); ?>
                            <article class="newsletter-campaign-card <?= ((int) ($selectedOptInForm['id'] ?? 0) === $formId) ? 'selected' : '' ?>">
                                <div>
                                    <strong><?= htmlspecialchars((string) ($form['name'] ?? 'Formular'), ENT_QUOTES) ?></strong>
                                    <p>slug: /newsletter/optin/<?= htmlspecialchars((string) ($form['slug'] ?? ''), ENT_QUOTES) ?></p>
                                    <p><small>Listă: <?= htmlspecialchars((string) ($listNameById[(int) ($form['list_id'] ?? 0)] ?? '-'), ENT_QUOTES) ?></small></p>
                                </div>
                                <div><span class="status-pill <?= (int) ($form['is_active'] ?? 0) === 1 ? 'ok' : 'off' ?>"><?= (int) ($form['is_active'] ?? 0) === 1 ? 'activ' : 'inactiv' ?></span></div>
                                <div class="newsletter-campaign-actions">
                                    <a class="icon-btn" href="/admin/emails/newsletters?tab=optin&form=<?= $formId ?>" title="Editează formular">✎</a>
                                    <form method="post" action="/admin/emails" onsubmit="return confirm('Ștergi formularul opt-in?');">
                                        <input type="hidden" name="section" value="newsletter-optin-delete">
                                        <input type="hidden" name="optin_form_id" value="<?= $formId ?>">
                                        <button type="submit" class="icon-btn danger">🗑</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($optInForms === []): ?>
                            <article class="newsletter-campaign-card">
                                <div>
                                    <strong>Nu există formulare opt-in</strong>
                                    <p>Apasă pe „+ Formular nou” pentru a crea primul formular.</p>
                                </div>
                            </article>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($optInEditing): ?>
                    <div class="nl-stage-builder">
                        <form method="post" action="/admin/emails" id="optin-form-builder-form" class="field">
                            <input type="hidden" name="section" value="newsletter-optin-save">
                            <input type="hidden" name="optin_form_id" value="<?= (int) ($selectedOptInForm['id'] ?? 0) ?>">
                            <input type="hidden" name="optin_fields_json" id="optin-fields-json">
                            <input type="hidden" name="optin_canvas_columns" id="optin-canvas-columns" value="<?= (int) $optInLayoutColumns ?>">

                            <div class="form-grid" style="margin-bottom:10px;">
                                <div class="field">
                                    <label>Nume formular</label>
                                    <input type="text" name="optin_name" id="optin-form-name" required value="<?= htmlspecialchars((string) ($selectedOptInForm['name'] ?? ''), ENT_QUOTES) ?>">
                                </div>
                                <div class="field">
                                    <label>Slug formular</label>
                                    <input type="text" name="optin_slug" id="optin-form-slug" required value="<?= htmlspecialchars((string) ($selectedOptInForm['slug'] ?? ''), ENT_QUOTES) ?>">
                                </div>
                                <div class="field">
                                    <label>Listă conectată</label>
                                    <select name="optin_list_id" id="optin-form-list-id" required>
                                        <?php foreach ($newsletterLists as $list): ?>
                                            <option value="<?= (int) ($list['id'] ?? 0) ?>" <?= (int) ($selectedOptInForm['list_id'] ?? 0) === (int) ($list['id'] ?? 0) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string) ($list['name'] ?? ''), ENT_QUOTES) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Text buton</label>
                                    <input type="text" name="optin_button_label" id="optin-form-button-label" value="<?= htmlspecialchars((string) ($selectedOptInForm['button_label'] ?? 'Ma abonez'), ENT_QUOTES) ?>">
                                </div>
                                <div class="field" style="grid-column:1/-1;">
                                    <label>Mesaj succes</label>
                                    <input type="text" name="optin_success_message" id="optin-form-success-message" value="<?= htmlspecialchars((string) ($selectedOptInForm['success_message'] ?? 'Te-ai abonat cu succes.'), ENT_QUOTES) ?>">
                                </div>
                                <div class="field" style="grid-column:1/-1;">
                                    <label style="display:flex;align-items:center;gap:8px;">
                                        <input type="checkbox" name="optin_is_active" id="optin-form-is-active" value="1" <?= ((int) ($selectedOptInForm['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                                        Formular activ
                                    </label>
                                </div>
                            </div>

                            <div class="optin-builder-wrap">
                                <div class="optin-builder-meta-row">
                                    <strong>Endpoint trimitere:</strong>
                                    <code id="optin-endpoint-preview">/newsletter/optin/</code>
                                </div>
                                <div class="optin-builder" id="optin-builder-root"></div>
                            </div>

                            <div class="newsletter-placeholder-box" style="margin-top:10px;">
                                <strong>Cod embed (poți copia în pagini custom)</strong>
                                <div class="optin-embed-sections" style="margin-top:8px;">
                                    <div class="code-type-tabs optin-embed-tabs">
                                        <button type="button" class="code-type-tab optin-embed-tab active" data-embed-type="html">HTML</button>
                                        <button type="button" class="code-type-tab optin-embed-tab" data-embed-type="css">CSS</button>
                                        <button type="button" class="code-type-tab optin-embed-tab" data-embed-type="js">JavaScript</button>
                                    </div>
                                    <div class="optin-embed-pane" data-embed-pane="html">
                                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;">
                                            <label style="display:block;font-weight:600;margin:0;">HTML</label>
                                            <button type="button" class="btn btn-secondary" data-copy-target="optin-embed-html">Copy HTML</button>
                                        </div>
                                        <pre id="optin-embed-html" style="margin:0;white-space:pre-wrap;"></pre>
                                    </div>
                                    <div class="optin-embed-pane is-hidden" data-embed-pane="css">
                                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;">
                                            <label style="display:block;font-weight:600;margin:0;">CSS</label>
                                            <button type="button" class="btn btn-secondary" data-copy-target="optin-embed-css">Copy CSS</button>
                                        </div>
                                        <pre id="optin-embed-css" style="margin:0;white-space:pre-wrap;"></pre>
                                    </div>
                                    <div class="optin-embed-pane is-hidden" data-embed-pane="js">
                                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;">
                                            <label style="display:block;font-weight:600;margin:0;">JavaScript</label>
                                            <button type="button" class="btn btn-secondary" data-copy-target="optin-embed-js">Copy JS</button>
                                        </div>
                                        <pre id="optin-embed-js" style="margin:0;white-space:pre-wrap;"></pre>
                                    </div>
                                </div>
                            </div>

                            <div style="margin-top:10px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                                <button type="button" class="btn btn-secondary" id="optin-builder-back-btn" data-back-url="/admin/emails/newsletters?tab=optin">&larr; Înapoi</button>
                                <div style="display:flex;gap:8px;">
                                    <button type="button" class="btn btn-secondary" id="optin-builder-preview-btn">Preview</button>
                                    <button class="btn" type="submit">Salvează formular</button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <div class="modal-overlay" id="optin-create-modal">
                <div class="modal-card" style="max-width:560px;">
                    <header style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                        <h3 style="margin:0;">Formular opt-in nou</h3>
                        <button type="button" class="icon-btn" data-modal-close="optin-create-modal">✕</button>
                    </header>
                    <form method="post" action="/admin/emails" class="form-grid" style="margin-top:10px;">
                        <input type="hidden" name="section" value="newsletter-optin-create">
                        <div class="field">
                            <label>Nume formular</label>
                            <input type="text" name="optin_name" required>
                        </div>
                        <div class="field">
                            <label>Listă conectată</label>
                            <select name="optin_list_id">
                                <?php foreach ($newsletterLists as $list): ?>
                                    <option value="<?= (int) ($list['id'] ?? 0) ?>">
                                        <?= htmlspecialchars((string) ($list['name'] ?? ''), ENT_QUOTES) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                            <button type="button" class="btn btn-secondary" data-modal-close="optin-create-modal">Anulează</button>
                            <button type="submit" class="btn">Creează formular</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($newsletterTab === 'contact_forms'): ?>
            <article class="panel" style="margin-top:12px;">
                <h3 style="margin:0 0 8px;">Cine primește mesajele</h3>
                <p style="margin:0 0 10px;color:#64748b;">
                    Una sau mai multe adrese, separate prin virgulă. Fiecare primește
                    câte o copie a mesajului. Mesajele se salvează oricum în lista de mai jos,
                    chiar dacă un email nu ajunge.
                </p>
                <form method="post" action="/admin/emails/contact-recipients" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;">
                    <input
                        type="text"
                        name="contact_form_recipients"
                        value="<?= htmlspecialchars(trim((string) ($settings['contact_form_recipients'] ?? 'contact@bioscem.ro')), ENT_QUOTES) ?>"
                        placeholder="contact@bioscem.ro, comenzi@bioscem.ro"
                        style="flex:1 1 420px;min-width:280px;"
                    >
                    <button class="btn" type="submit">Salvează destinatarii</button>
                </form>
            </article>

            <article class="panel" style="margin-top:12px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
                    <h3 style="margin:0;">Instrucțiuni pentru formularul de contact</h3>
                    <button
                        type="button"
                        class="btn btn-secondary"
                        id="contact-instructions-toggle"
                        aria-expanded="true"
                        style="display:inline-flex;align-items:center;gap:8px;"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" style="width:16px;height:16px;">
                            <path d="M19.4 13a7.8 7.8 0 0 0 .05-1 7.8 7.8 0 0 0-.05-1l2.02-1.58a.6.6 0 0 0 .14-.76l-1.92-3.32a.6.6 0 0 0-.72-.26l-2.38.96a7.31 7.31 0 0 0-1.73-1l-.36-2.53a.6.6 0 0 0-.6-.51h-3.84a.6.6 0 0 0-.6.5l-.36 2.54c-.61.24-1.19.57-1.73 1l-2.38-.96a.6.6 0 0 0-.72.26L2.44 8.66a.6.6 0 0 0 .14.76L4.6 11a7.8 7.8 0 0 0-.05 1c0 .34.02.67.05 1l-2.02 1.58a.6.6 0 0 0-.14.76l1.92 3.32c.15.26.46.37.72.26l2.38-.96c.54.43 1.12.76 1.73 1l.36 2.53c.05.29.3.5.6.5h3.84a.6.6 0 0 0 .6-.5l.36-2.53c.61-.24 1.19-.57 1.73-1l2.38.96c.26.11.57 0 .72-.26l1.92-3.32a.6.6 0 0 0-.14-.76L19.4 13Zm-7.4 2.2A3.2 3.2 0 1 1 12 8.8a3.2 3.2 0 0 1 0 6.4Z" fill="currentColor"/>
                        </svg>
                        <span>Instrucțiuni</span>
                        <span id="contact-instructions-caret">↑</span>
                    </button>
                </div>
                <div id="contact-instructions-body">
                    <?php
                    $destinatariContact = trim((string) ($settings['contact_form_recipients'] ?? 'contact@bioscem.ro'));
                    if ($destinatariContact === '') {
                        $destinatariContact = 'contact@bioscem.ro';
                    }
                    ?>
                    <p style="margin:0 0 10px;color:#64748b;">
                        Ca formularul să trimită corect către <strong><?= htmlspecialchars($destinatariContact, ENT_QUOTES) ?></strong>, trebuie să respecte pașii de mai jos.
                    </p>
                    <ol style="margin:0 0 10px 18px;line-height:1.7;color:#334155;">
                        <li>Formularul din front-end trebuie să trimită <code>POST</code> JSON la endpoint-ul <code>/contact/send</code>.</li>
                        <li>Payload-ul trebuie să includă câmpurile: <code>name</code>, <code>email</code>, <code>phone</code>, <code>subject</code>, <code>message</code>.</li>
                        <li>Câmpurile obligatorii validate în backend sunt: <code>name</code>, <code>email</code>, <code>subject</code>, <code>message</code>.</li>
                        <li>Transportul de email folosește setările deja configurate în <strong>Setări trimitere</strong> (SMTP / SendGrid).</li>
                        <li>Fiecare mesaj trimis este salvat automat în lista de mai jos, în această secțiune.</li>
                    </ol>
                    <pre style="margin:0;white-space:pre-wrap;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:10px;overflow:auto;"><code>fetch('/contact/send', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    name: 'Nume Prenume',
    email: 'email@exemplu.ro',
    phone: '07xx xxx xxx',
    subject: 'Subiect',
    message: 'Mesajul tău'
  })
});</code></pre>
                </div>
            </article>

            <article class="panel" style="margin-top:12px;">
                <h3 style="margin:0 0 10px;">Mesaje primite din formularele de contact</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nume</th>
                            <th>Email</th>
                            <th>Telefon</th>
                            <th>Subiect</th>
                            <th>Mesaj</th>
                            <th>Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contactMessages as $message): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($message['name'] ?? ''), ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars((string) ($message['email'] ?? ''), ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars((string) ($message['phone'] ?? '-'), ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars((string) ($message['subject'] ?? ''), ENT_QUOTES) ?></td>
                                <td><?= nl2br(htmlspecialchars((string) ($message['message'] ?? ''), ENT_QUOTES)) ?></td>
                                <td><?= htmlspecialchars((string) ($message['created_at'] ?? ''), ENT_QUOTES) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($contactMessages === []): ?>
                            <tr>
                                <td colspan="6" style="color:#64748b;">Nu există încă mesaje primite din formularul de contact.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </article>
            <script>
            (() => {
                const button = document.getElementById('contact-instructions-toggle');
                const body = document.getElementById('contact-instructions-body');
                const caret = document.getElementById('contact-instructions-caret');
                if (!(button instanceof HTMLElement) || !(body instanceof HTMLElement) || !(caret instanceof HTMLElement)) {
                    return;
                }
                button.addEventListener('click', () => {
                    const expanded = button.getAttribute('aria-expanded') === 'true';
                    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    body.style.display = expanded ? 'none' : '';
                    caret.textContent = expanded ? '↓' : '↑';
                });
            })();
            </script>
        <?php endif; ?>

        <div class="modal-overlay" id="optin-preview-modal">
            <div class="modal-card" style="max-width:900px;">
                <div class="modal-head">
                    <h3>Preview formular opt-in</h3>
                    <button type="button" class="icon-btn" data-modal-close="optin-preview-modal">✕</button>
                </div>
                <div class="page-toolbar" style="margin-top:10px;">
                    <div style="display:flex;gap:8px;align-items:center;">
                        <button type="button" class="btn btn-secondary device-switch optin-preview-device-switch active" data-device="desktop" title="Desktop">
                            <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8"/><path d="M12 16v4"/></svg>
                        </button>
                        <button type="button" class="btn btn-secondary device-switch optin-preview-device-switch" data-device="mobile" title="Telefon">
                            <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="2" width="8" height="20" rx="2"/><circle cx="12" cy="18.5" r="0.8"/></svg>
                        </button>
                    </div>
                    <strong style="margin-left:auto;">Mod: <span id="optin-preview-mode-label">Desktop</span></strong>
                </div>
                <div class="preview-shell desktop" id="optin-preview-shell" style="margin-top:12px;">
                    <iframe id="optin-preview-frame" title="Preview formular opt-in"></iframe>
                </div>
            </div>
        </div>

        <?php if ($newsletterTab === 'stats'): ?>
        <style>
        .nl-stats-wrap { font-family: inherit; }
        .nl-stats-kpi-row { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; }
        .nl-stats-kpi { flex: 1 1 160px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px 20px; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
        .nl-stats-kpi .nl-kpi-icon { font-size: 1.5rem; margin-bottom: 6px; }
        .nl-stats-kpi .nl-kpi-val { font-size: 1.7rem; font-weight: 700; color: #0f172a; line-height: 1.1; }
        .nl-stats-kpi .nl-kpi-sub { font-size: 12px; color: #64748b; margin-top: 4px; }
        .nl-stats-kpi .nl-kpi-label { font-size: 13px; color: #64748b; margin-top: 2px; }
        .nl-stats-section-title { font-size: 15px; font-weight: 700; color: #0f172a; margin: 0 0 12px; }
        .nl-stats-panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
        .nl-stats-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .nl-stats-table th { background: #f0fdf4; color: #1a7a5e; font-weight: 600; padding: 8px 10px; text-align: left; border-bottom: 2px solid #e5e7eb; }
        .nl-stats-table td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .nl-stats-table tr:last-child td { border-bottom: none; }
        .nl-stats-table tr:hover td { background: #f8fafc; }
        .nl-stats-prog { background: #e5e7eb; border-radius: 99px; height: 6px; min-width: 60px; display: inline-block; vertical-align: middle; }
        .nl-stats-prog-fill { background: #1a7a5e; border-radius: 99px; height: 6px; display: block; }
        .nl-stats-badge { display: inline-block; background: #e5e7eb; color: #0f172a; border-radius: 99px; padding: 1px 7px; font-size: 11px; font-weight: 600; }
        .nl-stats-badge.green { background: #dcfce7; color: #166534; }
        .nl-stats-badge.blue { background: #dbeafe; color: #1e40af; }
        .nl-stats-breadcrumb { margin-bottom: 16px; font-size: 13px; }
        .nl-stats-breadcrumb a { color: #1a7a5e; text-decoration: none; }
        .nl-stats-breadcrumb a:hover { text-decoration: underline; }
        .nl-stats-campaign-header { margin-bottom: 20px; }
        .nl-stats-campaign-header h2 { margin: 0 0 4px; font-size: 1.2rem; color: #0f172a; }
        .nl-stats-campaign-header p { margin: 0; color: #64748b; font-size: 13px; }
        .nl-stats-chart-wrap { overflow-x: auto; }
        .nl-stats-empty { text-align: center; padding: 32px 16px; color: #64748b; font-size: 14px; }
        .nl-stats-search { width: 100%; max-width: 340px; padding: 7px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; margin-bottom: 10px; }
        .nl-stats-detalii-btn { display: inline-block; padding: 4px 12px; background: #1a7a5e; color: #fff; border-radius: 6px; font-size: 12px; text-decoration: none; font-weight: 600; }
        .nl-stats-detalii-btn:hover { background: #15654e; }
        </style>
        <div class="nl-stats-wrap">
        <?php
        $statsCampaignId     = (int) ($statsCampaignId ?? 0);
        $statsCampaignDetail = $statsCampaignDetail ?? null;
        $statsCampaigns      = is_array($statsCampaigns ?? null) ? $statsCampaigns : [];
        $statsHourlyOpens    = is_array($statsHourlyOpens ?? null) ? $statsHourlyOpens : [];
        $statsTopLinks       = is_array($statsTopLinks ?? null) ? $statsTopLinks : [];
        $statsRecipients     = is_array($statsRecipients ?? null) ? $statsRecipients : [];
        $statsRevenue        = is_array($statsRevenue ?? null) ? $statsRevenue : ['orders_count'=>0,'revenue'=>0];
        ?>

        <?php if ($statsCampaignId === 0 || !$statsCampaignDetail): ?>
            <!-- OVERVIEW MODE -->
            <div class="nl-stats-kpi-row">
                <div class="nl-stats-kpi">
                    <div class="nl-kpi-icon">📋</div>
                    <div class="nl-kpi-val"><?= (int) ($newsletterStats['forms_total'] ?? 0) ?></div>
                    <div class="nl-kpi-label">Formulare opt-in</div>
                </div>
                <div class="nl-stats-kpi">
                    <div class="nl-kpi-icon">📂</div>
                    <div class="nl-kpi-val"><?= (int) count($newsletterLists) ?></div>
                    <div class="nl-kpi-label">Liste abonați</div>
                </div>
                <div class="nl-stats-kpi">
                    <div class="nl-kpi-icon">✅</div>
                    <div class="nl-kpi-val"><?= (int) ($newsletterStats['subscribers_active'] ?? 0) ?></div>
                    <div class="nl-kpi-label">Abonați activi</div>
                </div>
                <div class="nl-stats-kpi">
                    <div class="nl-kpi-icon">🚫</div>
                    <div class="nl-kpi-val"><?= (int) ($newsletterStats['subscribers_unsubscribed'] ?? 0) ?></div>
                    <div class="nl-kpi-label">Dezabonați</div>
                </div>
            </div>

            <div class="nl-stats-panel">
                <p class="nl-stats-section-title">Performance Campanii</p>
                <?php if (empty($statsCampaigns)): ?>
                    <div class="nl-stats-empty">Nu există campanii trimise încă.</div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="nl-stats-table">
                    <thead>
                        <tr>
                            <th>Campanie</th>
                            <th>Data trimiterii</th>
                            <th>Listă</th>
                            <th>Trimise</th>
                            <th>Deschideri</th>
                            <th>Click-uri</th>
                            <th>Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($statsCampaigns as $sc): ?>
                        <?php
                        $scSent   = (int) ($sc['total_sent'] ?? 0);
                        $scOpens  = (int) ($sc['total_opens'] ?? 0);
                        $scClicks = (int) ($sc['total_clicks'] ?? 0);
                        $openPct  = $scSent > 0 ? round($scOpens / $scSent * 100, 1) : 0;
                        $clickPct = $scSent > 0 ? round($scClicks / $scSent * 100, 1) : 0;
                        $openBarW = min(100, (int) $openPct);
                        $clickBarW = min(100, (int) $clickPct);
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string) ($sc['name'] ?? ''), ENT_QUOTES) ?></strong>
                                <br><span style="color:#64748b;font-size:11px;"><?= htmlspecialchars((string) ($sc['subject'] ?? ''), ENT_QUOTES) ?></span>
                            </td>
                            <td style="white-space:nowrap;"><?= htmlspecialchars((string) ($sc['sent_at'] ?? '—'), ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars((string) ($sc['list_name'] ?? '—'), ENT_QUOTES) ?></td>
                            <td><?= number_format($scSent) ?></td>
                            <td>
                                <span style="font-weight:600;"><?= $openPct ?>%</span>
                                <span style="color:#64748b;font-size:11px;">(<?= number_format($scOpens) ?>)</span>
                                <div class="nl-stats-prog" style="width:80px;"><div class="nl-stats-prog-fill" style="width:<?= $openBarW ?>%;"></div></div>
                            </td>
                            <td>
                                <span style="font-weight:600;"><?= $clickPct ?>%</span>
                                <span style="color:#64748b;font-size:11px;">(<?= number_format($scClicks) ?>)</span>
                                <div class="nl-stats-prog" style="width:80px;"><div class="nl-stats-prog-fill" style="background:#0ea5e9;width:<?= $clickBarW ?>%;"></div></div>
                            </td>
                            <td>
                                <a href="?tab=stats&campaign_id=<?= (int) $sc['id'] ?>" class="nl-stats-detalii-btn">Detalii</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- CAMPAIGN DETAIL MODE -->
            <?php
            $dcSent    = (int) ($statsCampaignDetail['total_sent'] ?? 0);
            $dcOpens   = (int) ($statsCampaignDetail['total_opens'] ?? 0);
            $dcClicks  = (int) ($statsCampaignDetail['total_clicks'] ?? 0);
            $dcOpenPct = $dcSent > 0 ? round($dcOpens / $dcSent * 100, 1) : 0;
            $dcClickPct= $dcSent > 0 ? round($dcClicks / $dcSent * 100, 1) : 0;
            $dcOrders  = (int) ($statsRevenue['orders_count'] ?? 0);
            $dcRevenue = (float) ($statsRevenue['revenue'] ?? 0);
            ?>
            <div class="nl-stats-breadcrumb">
                <a href="?tab=stats">&#8592; Toate campaniile</a>
            </div>
            <div class="nl-stats-campaign-header">
                <h2><?= htmlspecialchars((string) ($statsCampaignDetail['name'] ?? ''), ENT_QUOTES) ?></h2>
                <p>
                    <strong>Subiect:</strong> <?= htmlspecialchars((string) ($statsCampaignDetail['subject'] ?? '—'), ENT_QUOTES) ?>
                    &nbsp;&nbsp;|&nbsp;&nbsp;
                    <strong>Trimisă:</strong> <?= htmlspecialchars((string) ($statsCampaignDetail['sent_at'] ?? '—'), ENT_QUOTES) ?>
                    &nbsp;&nbsp;|&nbsp;&nbsp;
                    <strong>Listă:</strong> <?= htmlspecialchars((string) ($statsCampaignDetail['list_name'] ?? '—'), ENT_QUOTES) ?>
                </p>
            </div>

            <!-- KPI cards -->
            <div class="nl-stats-kpi-row">
                <div class="nl-stats-kpi">
                    <div class="nl-kpi-icon">📤</div>
                    <div class="nl-kpi-val"><?= number_format($dcSent) ?></div>
                    <div class="nl-kpi-label">Trimise</div>
                </div>
                <div class="nl-stats-kpi">
                    <div class="nl-kpi-icon">👁</div>
                    <div class="nl-kpi-val"><?= number_format($dcOpens) ?></div>
                    <div class="nl-kpi-sub"><?= $dcOpenPct ?>% rată deschidere</div>
                    <div class="nl-kpi-label">Deschideri</div>
                </div>
                <div class="nl-stats-kpi">
                    <div class="nl-kpi-icon">🖱</div>
                    <div class="nl-kpi-val"><?= number_format($dcClicks) ?></div>
                    <div class="nl-kpi-sub"><?= $dcClickPct ?>% rată click</div>
                    <div class="nl-kpi-label">Click-uri</div>
                </div>
                <div class="nl-stats-kpi">
                    <div class="nl-kpi-icon">💰</div>
                    <div class="nl-kpi-val"><?= $dcOrders > 0 ? number_format($dcRevenue, 2) . ' RON' : '—' ?></div>
                    <div class="nl-kpi-sub"><?= $dcOrders > 0 ? $dcOrders . ' comenzi' : '' ?></div>
                    <div class="nl-kpi-label">Venituri atribuite</div>
                </div>
            </div>

            <!-- Hourly opens chart -->
            <?php if (!empty($statsHourlyOpens)): ?>
            <div class="nl-stats-panel">
                <p class="nl-stats-section-title">Deschideri pe ore</p>
                <div class="nl-stats-chart-wrap">
                <?php
                $maxH = $statsHourlyOpens ? max($statsHourlyOpens) : 1;
                $svgW = 600; $svgH = 110; $barW = 18; $barGap = 7; $paddingLeft = 30; $paddingBottom = 24;
                $chartH = $svgH - $paddingBottom - 8;
                ?>
                <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" width="100%" style="max-width:640px;display:block;" xmlns="http://www.w3.org/2000/svg">
                    <?php for ($hr = 0; $hr < 24; $hr++):
                        $cnt = (int) ($statsHourlyOpens[$hr] ?? 0);
                        $bh  = $maxH > 0 ? max(2, (int) round($cnt / $maxH * $chartH)) : 2;
                        $x   = $paddingLeft + $hr * ($barW + $barGap);
                        $y   = $svgH - $paddingBottom - $bh;
                        $col = $cnt > 0 ? '#1a7a5e' : '#e5e7eb';
                    ?>
                    <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $barW ?>" height="<?= $bh ?>" rx="3" fill="<?= $col ?>">
                        <title><?= $hr ?>:00 — <?= $cnt ?> deschideri</title>
                    </rect>
                    <?php if ($hr % 4 === 0): ?>
                    <text x="<?= $x + $barW / 2 ?>" y="<?= $svgH - 6 ?>" text-anchor="middle" font-size="10" fill="#64748b"><?= $hr ?></text>
                    <?php endif; ?>
                    <?php endfor; ?>
                    <line x1="<?= $paddingLeft - 2 ?>" y1="<?= $svgH - $paddingBottom ?>" x2="<?= $svgW ?>" y2="<?= $svgH - $paddingBottom ?>" stroke="#e5e7eb" stroke-width="1"/>
                </svg>
                </div>
            </div>
            <?php endif; ?>

            <!-- Top links -->
            <?php if (!empty($statsTopLinks)): ?>
            <div class="nl-stats-panel">
                <p class="nl-stats-section-title">Cele mai accesate link-uri</p>
                <?php $maxLinkCnt = max(array_column($statsTopLinks, 'cnt') ?: [1]); ?>
                <table class="nl-stats-table">
                    <thead><tr><th>URL</th><th>Click-uri</th><th style="width:120px;"></th></tr></thead>
                    <tbody>
                    <?php foreach ($statsTopLinks as $lnk): ?>
                        <?php $lUrl = (string) ($lnk['url'] ?? ''); $lCnt = (int) ($lnk['cnt'] ?? 0); $lPct = $maxLinkCnt > 0 ? (int) round($lCnt / $maxLinkCnt * 100) : 0; ?>
                        <tr>
                            <td><span title="<?= htmlspecialchars($lUrl, ENT_QUOTES) ?>"><?= htmlspecialchars(strlen($lUrl) > 60 ? substr($lUrl, 0, 60) . '…' : $lUrl, ENT_QUOTES) ?></span></td>
                            <td><strong><?= number_format($lCnt) ?></strong></td>
                            <td><div class="nl-stats-prog" style="width:100%;"><div class="nl-stats-prog-fill" style="background:#0ea5e9;width:<?= $lPct ?>%;"></div></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Recipients table -->
            <div class="nl-stats-panel">
                <p class="nl-stats-section-title">Destinatari (ultimii 100)</p>
                <input type="text" class="nl-stats-search" id="nl-recip-search" placeholder="Caută după email…" oninput="nlFilterRecip(this.value)">
                <div style="overflow-x:auto;">
                <table class="nl-stats-table" id="nl-recip-table">
                    <thead><tr><th>Email</th><th>Status</th><th>Deschis</th><th>Click-uri</th></tr></thead>
                    <tbody>
                    <?php foreach ($statsRecipients as $rcp): ?>
                        <?php
                        $rEmail   = (string) ($rcp['email'] ?? '');
                        $rStatus  = (string) ($rcp['status'] ?? '');
                        $rOpened  = !empty($rcp['opened_at']);
                        $rOpenCnt = (int) ($rcp['open_count'] ?? 0);
                        $rClicked = !empty($rcp['clicked_at']);
                        $rClickCnt= (int) ($rcp['click_count'] ?? 0);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($rEmail, ENT_QUOTES) ?></td>
                            <td><span class="nl-stats-badge <?= $rStatus === 'sent' ? 'green' : '' ?>"><?= htmlspecialchars($rStatus, ENT_QUOTES) ?></span></td>
                            <td>
                                <?= $rOpened ? '<span style="color:#1a7a5e;font-weight:700;">&#10003;</span>' : '<span style="color:#cbd5e1;">—</span>' ?>
                                <?php if ($rOpenCnt > 0): ?><span class="nl-stats-badge"><?= $rOpenCnt ?>x</span><?php endif; ?>
                            </td>
                            <td>
                                <?= $rClicked ? '<span style="color:#0ea5e9;font-weight:700;">&#10003;</span>' : '<span style="color:#cbd5e1;">—</span>' ?>
                                <?php if ($rClickCnt > 0): ?><span class="nl-stats-badge blue"><?= $rClickCnt ?>x</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($statsRecipients)): ?><tr><td colspan="4" style="text-align:center;color:#64748b;">Nu există date de destinatari.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
                <script>
                function nlFilterRecip(q) {
                    q = q.toLowerCase();
                    const rows = document.querySelectorAll('#nl-recip-table tbody tr');
                    rows.forEach(function(row) {
                        const email = (row.cells[0] ? row.cells[0].textContent : '').toLowerCase();
                        row.style.display = email.indexOf(q) !== -1 ? '' : 'none';
                    });
                }
                </script>
            </div>

        <?php endif; ?>
        </div><!-- /.nl-stats-wrap -->
        <?php endif; ?>
        <?php if ($newsletterTab === 'history'): ?>
            <article class="panel" style="margin-top:12px;">
                <h3 style="margin:0 0 8px;">Istoric trimitere email-uri</h3>
                <p style="margin:0 0 10px;color:#64748b;">
                    Aici vezi fiecare email trimis/respins de sistem (inclusiv emailurile automate după AWB/completed).
                </p>
                <?php
                $buildHistoryUrl = static function (array $params = []) use ($emailSendHistoryFilterQ, $emailSendHistoryFilterStatus, $emailSendHistoryFilterType, $emailSendHistoryPerPage): string {
                    $query = [
                        'tab' => 'history',
                        'history_status' => $emailSendHistoryFilterStatus,
                        'history_per_page' => $emailSendHistoryPerPage,
                    ];
                    if ($emailSendHistoryFilterQ !== '') {
                        $query['history_q'] = $emailSendHistoryFilterQ;
                    }
                    if ($emailSendHistoryFilterType !== '') {
                        $query['history_type'] = $emailSendHistoryFilterType;
                    }
                    foreach ($params as $key => $value) {
                        if ($value === null || $value === '') {
                            unset($query[$key]);
                            continue;
                        }
                        $query[$key] = $value;
                    }
                    return '/admin/emails/newsletters?' . http_build_query($query);
                };
                ?>
                <form method="get" action="/admin/emails/newsletters" class="admin-filters-inline" style="margin-bottom:10px;">
                    <input type="hidden" name="tab" value="history">
                    <div class="admin-filter-field admin-filter-field--search">
                        <span>Căutare</span>
                        <input type="text" name="history_q" value="<?= htmlspecialchars($emailSendHistoryFilterQ, ENT_QUOTES) ?>" placeholder="Caută după destinatar, subiect, order number...">
                    </div>
                    <div class="admin-filter-field">
                        <span>Status</span>
                        <select name="history_status">
                            <option value="all" <?= $emailSendHistoryFilterStatus === 'all' ? 'selected' : '' ?>>Toate statusurile</option>
                            <option value="sent" <?= $emailSendHistoryFilterStatus === 'sent' ? 'selected' : '' ?>>Trimise</option>
                            <option value="failed" <?= $emailSendHistoryFilterStatus === 'failed' ? 'selected' : '' ?>>Eșuate</option>
                        </select>
                    </div>
                    <div class="admin-filter-field">
                        <span>Tip email</span>
                        <input type="text" name="history_type" value="<?= htmlspecialchars($emailSendHistoryFilterType, ENT_QUOTES) ?>" placeholder="Ex: shipped">
                    </div>
                    <div class="admin-filter-field">
                        <span>Pe pagină</span>
                        <select name="history_per_page">
                            <?php foreach ([25, 50, 100, 200] as $historyPerPageOption): ?>
                                <option value="<?= $historyPerPageOption ?>" <?= $historyPerPageOption === $emailSendHistoryPerPage ? 'selected' : '' ?>>
                                    <?= $historyPerPageOption ?>/pagină
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="admin-filter-actions">
                        <button class="btn" type="submit">Aplică</button>
                        <a class="btn btn-secondary" href="/admin/emails/newsletters?tab=history">Reset</a>
                    </div>
                </form>
                <p style="margin:0 0 10px;color:#64748b;">Total email-uri în istoric: <strong><?= (int) $emailSendHistoryTotal ?></strong></p>
                <div class="users-table-wrap">
                    <table class="table users-table">
                        <thead>
                        <tr>
                            <th>Data</th>
                            <th>Tip email</th>
                            <th>Destinatar</th>
                            <th>Subiect</th>
                            <th>Status</th>
                            <th>Sursă</th>
                            <th>Comandă</th>
                            <th>Eroare</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($emailSendHistory === []): ?>
                            <tr><td colspan="8">Nu există încă înregistrări în istoric.</td></tr>
                        <?php else: ?>
                            <?php foreach ($emailSendHistory as $row): ?>
                                <?php if (!is_array($row)) continue; ?>
                                <?php
                                $statusRaw = strtolower(trim((string) ($row['status'] ?? '')));
                                $statusOk = in_array($statusRaw, ['sent', 'ok', 'success'], true);
                                $orderNumber = trim((string) ($row['order_number'] ?? ''));
                                $orderId = (int) ($row['order_id'] ?? 0);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($row['created_at'] ?? ''), ENT_QUOTES) ?></td>
                                    <td><span class="status-pill"><?= htmlspecialchars((string) ($row['email_type'] ?? ''), ENT_QUOTES) ?></span></td>
                                    <td><?= htmlspecialchars((string) ($row['recipient'] ?? ''), ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars((string) ($row['subject'] ?? ''), ENT_QUOTES) ?></td>
                                    <td>
                                        <span class="status-pill <?= $statusOk ? 'ok' : 'off' ?>">
                                            <?= htmlspecialchars((string) ($row['status'] ?? ''), ENT_QUOTES) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($row['source'] ?? ''), ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($orderNumber !== '' ? $orderNumber : ($orderId > 0 ? ('#' . $orderId) : '-'), ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars((string) (($row['error_message'] ?? '') !== '' ? $row['error_message'] : '-'), ENT_QUOTES) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($emailSendHistoryTotalPages > 1): ?>
                    <?php
                    $historyStart = max(1, $emailSendHistoryPage - 2);
                    $historyEnd = min($emailSendHistoryTotalPages, $emailSendHistoryPage + 2);
                    ?>
                    <div class="users-search-row" style="margin-top:10px;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                        <small style="color:#64748b;">Pagina <?= $emailSendHistoryPage ?> din <?= $emailSendHistoryTotalPages ?></small>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                            <a class="btn btn-secondary" href="<?= htmlspecialchars($buildHistoryUrl(['history_page' => max(1, $emailSendHistoryPage - 1)]), ENT_QUOTES) ?>" <?= $emailSendHistoryPage <= 1 ? 'style="pointer-events:none;opacity:.6;"' : '' ?>>‹ Anterior</a>
                            <?php for ($h = $historyStart; $h <= $historyEnd; $h++): ?>
                                <a class="btn <?= $h === $emailSendHistoryPage ? '' : 'btn-secondary' ?>" href="<?= htmlspecialchars($buildHistoryUrl(['history_page' => $h]), ENT_QUOTES) ?>"><?= $h ?></a>
                            <?php endfor; ?>
                            <a class="btn btn-secondary" href="<?= htmlspecialchars($buildHistoryUrl(['history_page' => min($emailSendHistoryTotalPages, $emailSendHistoryPage + 1)]), ENT_QUOTES) ?>" <?= $emailSendHistoryPage >= $emailSendHistoryTotalPages ? 'style="pointer-events:none;opacity:.6;"' : '' ?>>Următor ›</a>
                        </div>
                    </div>
                <?php endif; ?>
            </article>
        <?php endif; ?>

        <div class="modal-overlay" id="newsletter-image-modal">
            <div class="modal-card" style="max-width:900px;">
                <div class="modal-head">
                    <h3>Selectează imagine din Galerie</h3>
                    <button type="button" class="icon-btn" data-modal-close="newsletter-image-modal">✕</button>
                </div>
                <div class="field" style="margin-bottom:10px;">
                    <input type="text" id="newsletter-image-search" placeholder="Caută imagine după titlu...">
                </div>
                <div class="product-picker-grid" id="newsletter-image-picker-grid">
                    <?php foreach ($galleryImages as $image): ?>
                        <button
                            type="button"
                            class="product-picker-item newsletter-image-picker-item"
                            data-image-url="<?= htmlspecialchars((string) ($image['image_url'] ?? ''), ENT_QUOTES) ?>"
                            data-search="<?= htmlspecialchars(strtolower((string) (($image['title'] ?? '') . ' ' . ($image['alt_text'] ?? '') . ' ' . ($image['image_url'] ?? ''))), ENT_QUOTES) ?>"
                        >
                            <img src="<?= htmlspecialchars((string) ($image['image_url'] ?? ''), ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) ($image['alt_text'] ?? $image['title'] ?? ''), ENT_QUOTES) ?>" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                            <strong><?= htmlspecialchars((string) (($image['title'] ?? '') !== '' ? $image['title'] : 'Imagine fără titlu'), ENT_QUOTES) ?></strong>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="modal-overlay" id="newsletter-preview-modal">
            <div class="modal-card" style="max-width:1100px;">
                <div class="modal-head">
                    <h3>Preview email</h3>
                    <button type="button" class="icon-btn" data-modal-close="newsletter-preview-modal">✕</button>
                </div>
                <div class="page-toolbar" style="margin-top:10px;">
                    <div style="display:flex;gap:8px;align-items:center;">
                        <button type="button" class="btn btn-secondary device-switch preview-device-switch active" data-device="desktop" title="Desktop">
                            <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8"/><path d="M12 16v4"/></svg>
                        </button>
                        <button type="button" class="btn btn-secondary device-switch preview-device-switch" data-device="tablet" title="Tabletă">
                            <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="3" width="12" height="18" rx="2"/><circle cx="12" cy="17.5" r="0.8"/></svg>
                        </button>
                        <button type="button" class="btn btn-secondary device-switch preview-device-switch" data-device="mobile" title="Telefon">
                            <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="2" width="8" height="20" rx="2"/><circle cx="12" cy="18.5" r="0.8"/></svg>
                        </button>
                    </div>
                    <strong style="margin-left:auto;">Mod: <span id="newsletter-preview-mode-label">Desktop</span></strong>
                </div>
                <div class="preview-shell desktop" id="newsletter-preview-shell" style="margin-top:12px;">
                    <iframe id="newsletter-preview-frame" title="Preview email"></iframe>
                </div>
            </div>
        </div>

        <div class="modal-overlay" id="ecommerce-codes-modal">
            <div class="modal-card" style="max-width:760px;">
                <div class="modal-head">
                    <h3>Coduri Email Ecommerce</h3>
                    <button type="button" class="icon-btn" data-modal-close="ecommerce-codes-modal">✕</button>
                </div>
                <p style="margin:10px 0 14px;color:#64748b;">
                    Poți folosi aceste coduri în Subiect sau în blocurile de tip text/buton. Se înlocuiesc automat la trimitere.
                </p>
                <div style="display:grid;gap:8px;max-height:52vh;overflow:auto;padding-right:4px;">
                    <?php foreach ($ecommerceEmailCodes as $token): ?>
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;">
                            <div>
                                <code style="font-weight:700;color:#0f172a;"><?= htmlspecialchars((string) ($token['code'] ?? ''), ENT_QUOTES) ?></code>
                                <div style="margin-top:4px;color:#64748b;font-size:13px;line-height:1.45;">
                                    <?= htmlspecialchars((string) ($token['description'] ?? ''), ENT_QUOTES) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="modal-overlay" id="ecommerce-recipients-modal">
            <div class="modal-card" style="max-width:760px;">
                <div class="modal-head">
                    <h3>Trimitere acest email la</h3>
                    <button type="button" class="icon-btn" data-modal-close="ecommerce-recipients-modal">✕</button>
                </div>
                <p style="margin:10px 0 14px;color:#64748b;">
                    Alege unde se trimite acest template ecommerce.
                </p>
                <div style="display:grid;gap:10px;">
                    <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;">
                        <input
                            type="radio"
                            name="ecommerce_template_recipient_mode"
                            value="client"
                            form="ecommerce-template-builder-form"
                            data-ecommerce-recipient-mode
                            <?= $selectedEcommerceRecipientMode === 'client' ? 'checked' : '' ?>
                        >
                        <span>
                            <strong style="display:block;color:#0f172a;">Client</strong>
                            <small style="color:#64748b;">Emailul se trimite către clientul comenzii.</small>
                        </span>
                    </label>
                    <div style="padding:10px 12px;border:1px dashed #cbd5e1;border-radius:10px;background:#ffffff;color:#334155;">
                        Destinatar: <strong>Client</strong>
                    </div>

                    <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;">
                        <input
                            type="radio"
                            name="ecommerce_template_recipient_mode"
                            value="admin"
                            form="ecommerce-template-builder-form"
                            data-ecommerce-recipient-mode
                            <?= $selectedEcommerceRecipientMode === 'admin' ? 'checked' : '' ?>
                        >
                        <span>
                            <strong style="display:block;color:#0f172a;">Admin</strong>
                            <small style="color:#64748b;">Emailul se trimite către adresele de mai jos.</small>
                        </span>
                    </label>
                    <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;">
                        <input
                            type="radio"
                            name="ecommerce_template_recipient_mode"
                            value="client_admin"
                            form="ecommerce-template-builder-form"
                            data-ecommerce-recipient-mode
                            <?= $selectedEcommerceRecipientMode === 'client_admin' ? 'checked' : '' ?>
                        >
                        <span>
                            <strong style="display:block;color:#0f172a;">Client & Admin</strong>
                            <small style="color:#64748b;">Emailul se trimite și către client, și către adresele de admin.</small>
                        </span>
                    </label>
                    <div data-ecommerce-admin-wrap style="display:grid;gap:8px;padding:10px 12px;border:1px dashed #cbd5e1;border-radius:10px;background:#ffffff;">
                        <label style="font-weight:600;color:#0f172a;">Email-uri admin</label>
                        <textarea
                            name="ecommerce_template_admin_recipients"
                            form="ecommerce-template-builder-form"
                            data-ecommerce-admin-list
                            rows="4"
                            placeholder="admin@site.ro, owner@site.ro"
                        ><?= htmlspecialchars($selectedEcommerceAdminRecipientsRaw, ENT_QUOTES) ?></textarea>
                        <small style="color:#64748b;">Separă adresele prin virgulă, spațiu sau Enter.</small>
                    </div>
                </div>
                <div style="margin-top:14px;display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" class="btn btn-secondary" data-modal-close="ecommerce-recipients-modal">Închide</button>
                    <button type="submit" class="btn" form="ecommerce-template-builder-form">Salvează</button>
                </div>
            </div>
        </div>
    </section>

    <?php
    $campaignBuilderBlocksForJs = $campaignInitialBlocks;
    $newsletterBuilderBlocksForJs = $newsletterInitialBlocks;
    $ecommerceBuilderBlocksForJs = $ecomBlocks;
    ?>
    <script>
    (() => {
        let imagePickerApply = null;

        const openModal = (id) => {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.add('open');
        };
        const closeModal = (id) => {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.remove('open');
            if (id === 'newsletter-image-modal') {
                imagePickerApply = null;
            }
        };

        document.querySelectorAll('[data-modal-close]').forEach((button) => {
            button.addEventListener('click', () => closeModal(button.getAttribute('data-modal-close') || ''));
        });
        document.querySelectorAll('.modal-overlay').forEach((overlay) => {
            overlay.addEventListener('click', (event) => {
                if (event.target === overlay) overlay.classList.remove('open');
            });
        });

        const animateStage = (stageId) => {
            const stage = document.getElementById(stageId);
            if (!stage) return;
            if ((stage.getAttribute('data-editing') || '0') !== '1') return;
            window.requestAnimationFrame(() => {
                stage.classList.add('is-editing');
            });
        };
        animateStage('newsletter-campaign-stage');
        animateStage('newsletter-template-stage');
        animateStage('newsletter-ecommerce-stage');
        animateStage('newsletter-optin-stage');
        document.getElementById('campaign-new-btn')?.addEventListener('click', () => openModal('campaign-create-modal'));
        document.getElementById('template-new-btn')?.addEventListener('click', () => openModal('template-create-modal'));
        document.getElementById('optin-new-btn')?.addEventListener('click', () => openModal('optin-create-modal'));
        document.getElementById('ecommerce-builder-codes-btn')?.addEventListener('click', () => openModal('ecommerce-codes-modal'));
        document.getElementById('ecommerce-builder-recipients-btn')?.addEventListener('click', () => openModal('ecommerce-recipients-modal'));

        const ecommerceRecipientModeInputs = Array.from(document.querySelectorAll('[data-ecommerce-recipient-mode]'));
        const ecommerceAdminWrap = document.querySelector('[data-ecommerce-admin-wrap]');
        const ecommerceAdminList = document.querySelector('[data-ecommerce-admin-list]');
        const syncEcommerceRecipientsUi = () => {
            const selectedModeInput = ecommerceRecipientModeInputs.find((input) => input instanceof HTMLInputElement && input.checked);
            const mode = selectedModeInput instanceof HTMLInputElement ? selectedModeInput.value : 'client';
            const adminMode = mode === 'admin' || mode === 'client_admin';
            if (ecommerceAdminWrap instanceof HTMLElement) {
                ecommerceAdminWrap.style.display = adminMode ? 'grid' : 'none';
            }
            if (ecommerceAdminList instanceof HTMLTextAreaElement) {
                ecommerceAdminList.readOnly = !adminMode;
                ecommerceAdminList.required = adminMode;
            }
        };
        ecommerceRecipientModeInputs.forEach((input) => {
            if (input instanceof HTMLInputElement) {
                input.addEventListener('change', syncEcommerceRecipientsUi);
            }
        });
        syncEcommerceRecipientsUi();

        const imagePickerModal = document.getElementById('newsletter-image-modal');
        const imagePickerSearch = document.getElementById('newsletter-image-search');
        const imagePickerItems = document.querySelectorAll('.newsletter-image-picker-item');

        imagePickerSearch?.addEventListener('input', () => {
            const query = (imagePickerSearch.value || '').trim().toLowerCase();
            imagePickerItems.forEach((item) => {
                const haystack = item.getAttribute('data-search') || '';
                item.style.display = haystack.includes(query) ? '' : 'none';
            });
        });

        imagePickerItems.forEach((item) => {
            item.addEventListener('click', () => {
                const imageUrl = item.getAttribute('data-image-url') || '';
                if (typeof imagePickerApply === 'function') {
                    imagePickerApply(imageUrl);
                }
                closeModal('newsletter-image-modal');
            });
        });

        imagePickerModal?.addEventListener('click', (event) => {
            if (event.target === imagePickerModal) {
                closeModal('newsletter-image-modal');
            }
        });

        const previewModal = document.getElementById('newsletter-preview-modal');
        const previewFrame = document.getElementById('newsletter-preview-frame');
        const previewShell = document.getElementById('newsletter-preview-shell');
        const previewModeLabel = document.getElementById('newsletter-preview-mode-label');
        const previewSwitches = document.querySelectorAll('.preview-device-switch');
        const setPreviewDevice = (device) => {
            previewSwitches.forEach((btn) => {
                btn.classList.toggle('active', (btn.getAttribute('data-device') || 'desktop') === device);
            });
            previewShell?.classList.remove('desktop', 'tablet', 'mobile');
            previewShell?.classList.add(device);
            const labels = { desktop: 'Desktop', tablet: 'Tabletă', mobile: 'Telefon' };
            if (previewModeLabel) previewModeLabel.textContent = labels[device] || 'Desktop';
        };
        previewSwitches.forEach((btn) => {
            btn.addEventListener('click', () => {
                setPreviewDevice(btn.getAttribute('data-device') || 'desktop');
            });
        });
        setPreviewDevice('desktop');

        const optinPreviewModal = document.getElementById('optin-preview-modal');
        const optinPreviewFrame = document.getElementById('optin-preview-frame');
        const optinPreviewShell = document.getElementById('optin-preview-shell');
        const optinPreviewModeLabel = document.getElementById('optin-preview-mode-label');
        const optinPreviewSwitches = document.querySelectorAll('.optin-preview-device-switch');
        const setOptinPreviewDevice = (device) => {
            optinPreviewSwitches.forEach((btn) => {
                btn.classList.toggle('active', (btn.getAttribute('data-device') || 'desktop') === device);
            });
            optinPreviewShell?.classList.remove('desktop', 'mobile');
            optinPreviewShell?.classList.add(device);
            if (optinPreviewModeLabel) {
                optinPreviewModeLabel.textContent = device === 'mobile' ? 'Telefon' : 'Desktop';
            }
        };
        optinPreviewSwitches.forEach((btn) => {
            btn.addEventListener('click', () => {
                setOptinPreviewDevice(btn.getAttribute('data-device') || 'desktop');
            });
        });
        setOptinPreviewDevice('desktop');

        const makeBuilder = (config) => {
            const root = document.getElementById(config.rootId);
            if (!root) return null;
            const includeUnsubscribeFooter = Boolean(config.includeUnsubscribeFooter);

            const addButtonItem = (icon, label, type) =>
                `<button type="button" class="nl-builder-tool" draggable="true" data-role="add" data-type="${type}"><span>${icon}</span>${label}</button>`;

            root.innerHTML = `
                <aside class="nl-builder-left">
                    <h4>BLOCURI</h4>
                    ${addButtonItem('H', 'Header', 'header')}
                    ${addButtonItem('T', 'Text', 'text')}
                    ${addButtonItem('🖼', 'Imagine', 'image')}
                    ${addButtonItem('▣', 'Buton', 'button')}
                    ${addButtonItem('—', 'Separator', 'divider')}
                    ${addButtonItem('↕', 'Spațiu', 'spacer')}
                    <h4 style="margin-top:14px;">SECȚIUNI</h4>
                    ${addButtonItem('2', '50% / 50%', 'columns_2')}
                    ${addButtonItem('3', '33% / 33% / 33%', 'columns_3')}
                </aside>
                <div class="nl-builder-center">
                    <div class="nl-builder-canvas" data-role="canvas"></div>
                </div>
                <aside class="nl-builder-right">
                    <div class="nl-props-head">
                        <strong>Proprietăți bloc</strong>
                        <button type="button" class="icon-btn danger" data-role="delete-selected" title="Șterge bloc">🗑</button>
                    </div>
                    <div data-role="props"></div>
                </aside>
            `;

            const blocksInput = document.getElementById(config.blocksInputId);
            const htmlInput = document.getElementById(config.htmlInputId);
            const subjectInput = document.getElementById(config.subjectInputId);
            const form = document.getElementById(config.formId);
            if (!blocksInput || !htmlInput) return null;

            const canvas = root.querySelector('[data-role="canvas"]');
            const propsBox = root.querySelector('[data-role="props"]');
            const deleteSelectedBtn = root.querySelector('[data-role="delete-selected"]');
            const previewBtn = config.previewButtonId ? document.getElementById(config.previewButtonId) : null;

            const escapeHtml = (value) => String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');

            const normalizeLinkUrl = (value) => {
                const raw = String(value || '').trim();
                if (raw === '') return '';
                if (/^javascript:/i.test(raw)) return '';
                if (/^https?:\/\//i.test(raw) || /^mailto:/i.test(raw) || /^tel:/i.test(raw)) {
                    return raw;
                }
                if (/^www\./i.test(raw)) {
                    return `https://${raw}`;
                }
                if (raw.startsWith('/') || raw.startsWith('./') || raw.startsWith('../')) {
                    return toAbsoluteUrlForEmail(raw);
                }
                return '';
            };
            const backendBaseUrl = (() => {
                const configured = <?= json_encode(rtrim((string) ((require __DIR__ . '/../../config/app.php')['url'] ?? ''), '/'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
                const raw = String(configured || '').trim();
                if (raw !== '') return raw;
                return String(window.location.origin || '').trim();
            })();
            const toAbsoluteUrlForEmail = (value) => {
                const raw = String(value || '').trim();
                if (raw === '' || raw === '#') return '#';
                if (/^javascript:/i.test(raw)) return '#';
                if (/^(https?:\/\/|mailto:|tel:|data:)/i.test(raw)) return raw;
                if (/^www\./i.test(raw)) return `https://${raw}`;
                if (/^\/\//.test(raw)) return `${window.location.protocol}${raw}`;
                if (raw.startsWith('#')) return raw;
                const origin = backendBaseUrl;
                if (origin === '') return raw;
                if (raw.startsWith('/')) return `${origin}${raw}`;
                if (raw.startsWith('./')) return `${origin}/${raw.slice(2)}`;
                if (raw.startsWith('../')) return `${origin}/${raw.replace(/^(\.\.\/)+/, '')}`;
                return `${origin}/${raw.replace(/^\/+/, '')}`;
            };
            const responsiveMailStyles = `<style>
                .nl-mail-body{margin:0;padding:0;background:#ffffff;font-family:Arial,sans-serif;color:#1f2937;}
                .nl-mail-container{width:100%;max-width:100%;margin:0;background:#ffffff;border:none;border-radius:0;padding:0;}
                .nl-text-desktop{display:block;}
                .nl-text-mobile{display:none;}
                @media only screen and (max-width:620px){
                    .nl-mail-body{padding:0 !important;}
                    .nl-col-row,.nl-col-cell{display:block !important;width:100% !important;max-width:100% !important;}
                    .nl-col-cell{padding:0 0 10px 0 !important;}
                    .nl-col-cell:last-child{padding-bottom:0 !important;}
                    .nl-text-desktop{display:none !important;}
                    .nl-text-mobile{display:block !important;}
                }
            </style>`;
            const textWithLinksHtml = (value) => {
                const source = String(value ?? '');
                const pattern = /\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+|tel:[^\s)]+)\)|((https?:\/\/|www\.)[^\s<]+)/gi;
                let result = '';
                let cursor = 0;
                let match = pattern.exec(source);
                while (match) {
                    const full = String(match[0] || '');
                    const index = Number(match.index || 0);
                    if (index > cursor) {
                        result += escapeHtml(source.slice(cursor, index));
                    }
                    const markdownLabel = String(match[1] || '').trim();
                    const markdownUrl = String(match[2] || '').trim();
                    const autoUrl = String(match[3] || '').trim();
                    const url = markdownUrl !== '' ? markdownUrl : autoUrl;
                    const label = markdownLabel !== '' ? markdownLabel : autoUrl;
                    const href = normalizeLinkUrl(url);
                    if (href !== '') {
                        result += `<a href="${escapeHtml(href)}" style="color:inherit;text-decoration:underline;">${escapeHtml(label || href)}</a>`;
                    } else {
                        result += escapeHtml(full);
                    }
                    cursor = index + full.length;
                    match = pattern.exec(source);
                }
                if (cursor < source.length) {
                    result += escapeHtml(source.slice(cursor));
                }
                return result.replaceAll('\n', '<br>');
            };

            const normalizeBlock = (block, options = {}) => {
                const allowColumns = options.allowColumns !== false;
                const fallbackText = String(options.fallbackText || 'Text');
                let type = String(block?.type || 'text');
                if (!allowColumns && (type === 'columns_2' || type === 'columns_3')) {
                    type = 'text';
                }
                const base = {
                    type,
                    align: String(block?.align || 'left'),
                    background: String(block?.background || '#ffffff'),
                    text_color: String(block?.text_color || '#1f2937'),
                    block_background: String(block?.block_background || '#ffffff'),
                };
                if (type === 'header') {
                    const desktopFontSize = Number.isFinite(Number(block?.font_size)) ? Number(block.font_size) : 34;
                    return {
                        ...base,
                        content: String(block?.content || 'Titlu'),
                        align: String(block?.align || 'center'),
                        font_size: desktopFontSize,
                        font_size_mobile: Number.isFinite(Number(block?.font_size_mobile))
                            ? Number(block.font_size_mobile)
                            : desktopFontSize,
                    };
                }
                if (type === 'text') {
                    const desktopFontSize = Number.isFinite(Number(block?.font_size)) ? Number(block.font_size) : 15;
                    return {
                        ...base,
                        content: String(block?.content || fallbackText),
                        font_size: desktopFontSize,
                        font_size_mobile: Number.isFinite(Number(block?.font_size_mobile))
                            ? Number(block.font_size_mobile)
                            : desktopFontSize,
                    };
                }
                if (type === 'image') {
                    return { ...base, image_url: String(block?.image_url || ''), alt: String(block?.alt || '') };
                }
                if (type === 'button') {
                    return {
                        ...base,
                        label: String(block?.label || 'Află mai multe'),
                        url: String(block?.url || '#'),
                        radius: Number.isFinite(Number(block?.radius)) ? Number(block.radius) : 6,
                        font_size: Number.isFinite(Number(block?.font_size)) ? Number(block.font_size) : 16,
                        align: String(block?.align || 'center'),
                        background: String(block?.background || '#1a7a5e'),
                        text_color: String(block?.text_color || '#ffffff'),
                        block_background: String(block?.block_background || '#ffffff'),
                    };
                }
                if (type === 'divider') {
                    return { ...base, line_color: String(block?.line_color || '#dbe2ea') };
                }
                if (type === 'spacer') {
                    return { ...base, height: Number.isFinite(Number(block?.height)) ? Number(block.height) : 24 };
                }
                if (allowColumns && (type === 'columns_2' || type === 'columns_3')) {
                    const columns = type === 'columns_3' ? 3 : 2;
                    const rawColumns = Array.isArray(block?.columns_content)
                        ? block.columns_content
                        : (Array.isArray(block?.columns)
                            ? block.columns
                            : (Array.isArray(block?.content_columns) ? block.content_columns : []));
                    const normalizedColumns = [];
                    const normalizeColumnBlocks = (entry, defaultText) => {
                        const items = [];
                        if (Array.isArray(entry)) {
                            if (entry.length > 0 && entry.every((value) => typeof value === 'string' || typeof value === 'number')) {
                                const text = entry.map((value) => String(value || '').trim()).filter((value) => value !== '').join('\n');
                                if (text !== '') {
                                    items.push({ type: 'text', content: text });
                                }
                            } else {
                                entry.forEach((value) => {
                                    if (value && typeof value === 'object' && !Array.isArray(value)) {
                                        items.push(value);
                                    }
                                });
                            }
                        } else if (entry && typeof entry === 'object') {
                            items.push(entry);
                        } else if (String(entry || '').trim() !== '') {
                            items.push({ type: 'text', content: String(entry || '') });
                        }
                        if (items.length === 0) {
                            items.push({ type: 'text', content: defaultText });
                        }
                        return items.map((item) => normalizeBlock(item, { allowColumns: false, fallbackText: defaultText }));
                    };
                    for (let index = 0; index < columns; index++) {
                        const defaultText = `Coloana ${index + 1}`;
                        normalizedColumns.push(normalizeColumnBlocks(rawColumns[index], defaultText));
                    }
                    return {
                        ...base,
                        columns,
                        columns_content: normalizedColumns,
                        background: String(block?.background || '#ffffff'),
                        text_color: String(block?.text_color || '#1f2937'),
                        block_background: String(block?.block_background || '#ffffff'),
                        font_size: Number.isFinite(Number(block?.font_size)) ? Number(block.font_size) : 15,
                    };
                }
                return {
                    ...base,
                    type: 'text',
                    content: String(block?.content || fallbackText),
                    font_size: Number.isFinite(Number(block?.font_size)) ? Number(block.font_size) : 15,
                };
            };
            const normalize = (block) => normalizeBlock(block, { allowColumns: true, fallbackText: 'Text' });

            const createBlock = (type) => normalize({ type });

            const blockContainerBg = (block, fallback = '#ffffff') => {
                const value = String(block?.block_background || '').trim();
                return /^#[0-9a-fA-F]{6}$/.test(value) ? value.toLowerCase() : fallback;
            };
            const blockFontSize = (block, fallback = 15) => {
                const value = Number(block?.font_size);
                if (!Number.isFinite(value) || value <= 0) {
                    return fallback;
                }
                return Math.max(10, Math.min(96, Math.round(value)));
            };

            let blocks = Array.isArray(config.initialBlocks) && config.initialBlocks.length > 0
                ? config.initialBlocks.map((item) => normalize(item))
                : [createBlock('text')];
            let selectedIndex = 0;
            let selectedColumnBlock = { columnIndex: 0, blockIndex: 0 };
            let dragState = { mode: '', type: '', index: -1 };
            let isAnimating = false;

            const ensureSelectedColumnBlock = (block) => {
                if (!(block?.type === 'columns_2' || block?.type === 'columns_3')) {
                    return;
                }
                const columns = block.type === 'columns_3' ? 3 : 2;
                let columnIndex = Number(selectedColumnBlock.columnIndex || 0);
                if (!Number.isInteger(columnIndex) || columnIndex < 0) {
                    columnIndex = 0;
                }
                if (columnIndex >= columns) {
                    columnIndex = columns - 1;
                }
                const allColumns = Array.isArray(block.columns_content) ? block.columns_content : [];
                const columnBlocks = Array.isArray(allColumns[columnIndex]) ? allColumns[columnIndex] : [];
                if (columnBlocks.length === 0) {
                    allColumns[columnIndex] = [normalizeBlock({ type: 'text', content: `Coloana ${columnIndex + 1}` }, { allowColumns: false, fallbackText: `Coloana ${columnIndex + 1}` })];
                }
                const fixedColumnBlocks = Array.isArray(allColumns[columnIndex]) ? allColumns[columnIndex] : [];
                let blockIndex = Number(selectedColumnBlock.blockIndex || 0);
                if (!Number.isInteger(blockIndex) || blockIndex < 0) {
                    blockIndex = 0;
                }
                if (blockIndex >= fixedColumnBlocks.length) {
                    blockIndex = Math.max(0, fixedColumnBlocks.length - 1);
                }
                block.columns_content = allColumns;
                selectedColumnBlock = { columnIndex, blockIndex };
            };
            const isColumnsSection = (block) => block?.type === 'columns_2' || block?.type === 'columns_3';
            const columnBlockPalette = [
                { type: 'header', icon: 'H', label: 'Header' },
                { type: 'text', icon: 'T', label: 'Text' },
                { type: 'image', icon: '🖼', label: 'Imagine' },
                { type: 'button', icon: '▣', label: 'Buton' },
                { type: 'divider', icon: '—', label: 'Separator' },
                { type: 'spacer', icon: '↕', label: 'Spațiu' },
            ];
            const editableContext = () => {
                const block = blocks[selectedIndex];
                if (!block) {
                    return null;
                }
                if (!isColumnsSection(block)) {
                    return { block, section: null, columnIndex: -1, blockIndex: -1 };
                }
                ensureSelectedColumnBlock(block);
                const columns = Array.isArray(block.columns_content) ? block.columns_content : [];
                const columnIndex = Number(selectedColumnBlock.columnIndex || 0);
                const columnBlocks = Array.isArray(columns[columnIndex]) ? columns[columnIndex] : [];
                if (columnBlocks.length === 0) {
                    const fallbackText = `Coloana ${columnIndex + 1}`;
                    columnBlocks.push(normalizeBlock({ type: 'text', content: fallbackText }, { allowColumns: false, fallbackText }));
                    columns[columnIndex] = columnBlocks;
                    block.columns_content = columns;
                    selectedColumnBlock.blockIndex = 0;
                }
                const blockIndex = Math.max(0, Math.min(columnBlocks.length - 1, Number(selectedColumnBlock.blockIndex || 0)));
                selectedColumnBlock = { columnIndex, blockIndex };
                return {
                    block: columnBlocks[blockIndex],
                    section: block,
                    columnIndex,
                    blockIndex,
                };
            };

            const blockCanvasHtml = (block) => {
                if (block.type === 'header') {
                    return `<div class="nl-block-inner" style="text-align:${block.align};background:${escapeHtml(block.background)};color:${escapeHtml(block.text_color)};font-size:${blockFontSize(block, 34)}px;font-weight:700;padding:28px 22px;">${escapeHtml(block.content)}</div>`;
                }
                if (block.type === 'text') {
                    return `<div class="nl-block-inner" style="text-align:${block.align};background:${escapeHtml(block.background)};color:${escapeHtml(block.text_color)};font-size:${blockFontSize(block, 15)}px;line-height:1.6;padding:18px 22px;">${textWithLinksHtml(block.content)}</div>`;
                }
                if (block.type === 'image') {
                    if (String(block.image_url || '') === '') {
                        return '<div class="nl-block-inner nl-image-placeholder">Imagine (URL lipsă)</div>';
                    }
                    const containerBg = blockContainerBg(block, '#ffffff');
                    return `<div class="nl-block-inner" style="padding:14px;background:${escapeHtml(containerBg)};"><img src="${escapeHtml(block.image_url)}" alt="${escapeHtml(block.alt || '')}" style="max-width:100%;height:auto;border-radius:8px;"></div>`;
                }
                if (block.type === 'button') {
                    const containerBg = blockContainerBg(block, '#ffffff');
                    return `<div class="nl-block-inner" style="text-align:${block.align};background:${escapeHtml(containerBg)};padding:20px;"><a href="${escapeHtml(block.url || '#')}" style="display:inline-block;background:${escapeHtml(block.background)};color:${escapeHtml(block.text_color)};padding:12px 26px;border-radius:${Math.max(0, Number(block.radius || 0))}px;text-decoration:none;font-weight:700;font-size:${blockFontSize(block, 16)}px;">${escapeHtml(block.label || 'Buton')}</a></div>`;
                }
                if (block.type === 'divider') {
                    const containerBg = blockContainerBg(block, '#ffffff');
                    return `<div class="nl-block-inner" style="background:${escapeHtml(containerBg)};padding:14px 20px;"><hr style="border:none;border-top:1px solid ${escapeHtml(block.line_color || '#dbe2ea')};margin:0;"></div>`;
                }
                if (block.type === 'columns_2' || block.type === 'columns_3') {
                    const columns = block.type === 'columns_3' ? 3 : 2;
                    const contents = Array.isArray(block.columns_content) ? block.columns_content : [];
                    const containerBg = blockContainerBg(block, '#ffffff');
                    const columnItems = [];
                    for (let index = 0; index < columns; index++) {
                        const defaultText = `Coloana ${index + 1}`;
                        const columnBlocks = Array.isArray(contents[index]) ? contents[index] : [];
                        const childHtml = columnBlocks
                            .map((item) => blockCanvasHtml(normalizeBlock(item, { allowColumns: false, fallbackText: defaultText })))
                            .join('');
                        columnItems.push(
                            `<div style="background:${escapeHtml(block.background || '#ffffff')};color:${escapeHtml(block.text_color || '#1f2937')};border-radius:10px;padding:10px;line-height:1.55;text-align:left;">${childHtml || `<div class="nl-block-inner" style="background:#f8fafc;color:#64748b;font-size:13px;border:1px dashed #cbd5e1;">${escapeHtml(defaultText)}</div>`}</div>`
                        );
                    }
                    return `<div class="nl-block-inner" style="background:${escapeHtml(containerBg)};padding:14px;"><div style="display:grid;grid-template-columns:repeat(${columns},minmax(0,1fr));gap:10px;">${columnItems.join('')}</div></div>`;
                }
                const containerBg = blockContainerBg(block, '#ffffff');
                return `<div class="nl-block-inner" style="background:${escapeHtml(containerBg)};height:${Math.max(4, Number(block.height || 24))}px;"></div>`;
            };

            const blockMailHtml = (block) => {
                if (block.type === 'header') {
                    const desktopSize = blockFontSize(block, 34);
                    const mobileSize = Number.isFinite(Number(block.font_size_mobile))
                        ? Math.max(10, Math.min(96, Math.round(Number(block.font_size_mobile))))
                        : desktopSize;
                    return `<div class="nl-text-desktop" style="text-align:${block.align};background:${escapeHtml(block.background)};color:${escapeHtml(block.text_color)};font-size:${desktopSize}px;font-weight:700;padding:24px 0;border-radius:10px;">${escapeHtml(block.content)}</div><div class="nl-text-mobile" style="display:none;text-align:${block.align};background:${escapeHtml(block.background)};color:${escapeHtml(block.text_color)};font-size:${mobileSize}px;font-weight:700;padding:24px 0;border-radius:10px;">${escapeHtml(block.content)}</div>`;
                }
                if (block.type === 'text') {
                    const desktopSize = blockFontSize(block, 15);
                    const mobileSize = Number.isFinite(Number(block.font_size_mobile))
                        ? Math.max(10, Math.min(96, Math.round(Number(block.font_size_mobile))))
                        : desktopSize;
                    const textHtml = textWithLinksHtml(block.content);
                    return `<div class="nl-text-desktop" style="text-align:${block.align};background:${escapeHtml(block.background)};color:${escapeHtml(block.text_color)};font-size:${desktopSize}px;line-height:1.6;padding:18px 0;border-radius:10px;">${textHtml}</div><div class="nl-text-mobile" style="display:none;text-align:${block.align};background:${escapeHtml(block.background)};color:${escapeHtml(block.text_color)};font-size:${mobileSize}px;line-height:1.6;padding:18px 0;border-radius:10px;">${textHtml}</div>`;
                }
                if (block.type === 'image') {
                    if (String(block.image_url || '') === '') return '';
                    const containerBg = blockContainerBg(block, '#ffffff');
                    return `<div style="padding:8px 0;background:${escapeHtml(containerBg)};"><img src="${escapeHtml(toAbsoluteUrlForEmail(block.image_url || ''))}" alt="${escapeHtml(block.alt || '')}" style="max-width:100%;height:auto;border-radius:8px;"></div>`;
                }
                if (block.type === 'button') {
                    const containerBg = blockContainerBg(block, '#ffffff');
                    return `<div style="text-align:${block.align};padding:16px 0;background:${escapeHtml(containerBg)};"><a href="${escapeHtml(toAbsoluteUrlForEmail(block.url || '#'))}" style="display:inline-block;background:${escapeHtml(block.background)};color:${escapeHtml(block.text_color)};padding:11px 22px;border-radius:${Math.max(0, Number(block.radius || 0))}px;text-decoration:none;font-weight:700;font-size:${blockFontSize(block, 16)}px;">${escapeHtml(block.label || 'Buton')}</a></div>`;
                }
                if (block.type === 'divider') {
                    const containerBg = blockContainerBg(block, '#ffffff');
                    return `<div style="background:${escapeHtml(containerBg)};"><hr style="border:none;border-top:1px solid ${escapeHtml(block.line_color || '#dbe2ea')};margin:16px 0;"></div>`;
                }
                if (block.type === 'columns_2' || block.type === 'columns_3') {
                    const columns = block.type === 'columns_3' ? 3 : 2;
                    const contents = Array.isArray(block.columns_content) ? block.columns_content : [];
                    const columnCells = [];
                    const widthPercent = Math.floor(100 / columns);
                    for (let index = 0; index < columns; index++) {
                        const defaultText = `Coloana ${index + 1}`;
                        const columnBlocks = Array.isArray(contents[index]) ? contents[index] : [];
                        const childHtml = columnBlocks
                            .map((item) => blockMailHtml(normalizeBlock(item, { allowColumns: false, fallbackText: defaultText })))
                            .join('');
                        const cellPadding = index === columns - 1 ? '0' : '0 6px 0 0';
                        columnCells.push(
                            `<td class="nl-col-cell" valign="top" width="${widthPercent}%" style="width:${widthPercent}%;padding:${cellPadding};"><div style="background:${escapeHtml(block.background || '#ffffff')};color:${escapeHtml(block.text_color || '#1f2937')};border-radius:10px;padding:10px;line-height:1.55;">${childHtml || `<div style="padding:8px 10px;color:#64748b;font-size:13px;border:1px dashed #cbd5e1;border-radius:8px;">${escapeHtml(defaultText)}</div>`}</div></td>`
                        );
                    }
                    return `<div style="background:${escapeHtml(blockContainerBg(block, '#ffffff'))};padding:8px 0;"><table class="nl-col-table" role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;table-layout:fixed;"><tr class="nl-col-row">${columnCells.join('')}</tr></table></div>`;
                }
                const containerBg = blockContainerBg(block, '#ffffff');
                return `<div style="height:${Math.max(4, Number(block.height || 24))}px;background:${escapeHtml(containerBg)};"></div>`;
            };

            const sanitizeBlocksForSave = () => blocks.map((block) => {
                if (!block || (block.type !== 'columns_2' && block.type !== 'columns_3')) {
                    return block;
                }
                const columns = block.type === 'columns_3' ? 3 : 2;
                const contents = Array.isArray(block.columns_content) ? block.columns_content : [];
                const normalizedColumns = [];
                for (let index = 0; index < columns; index++) {
                    const defaultText = `Coloana ${index + 1}`;
                    const rawBlocks = Array.isArray(contents[index]) ? contents[index] : [];
                    const sanitizedChildren = rawBlocks
                        .map((item) => normalizeBlock(item, { allowColumns: false, fallbackText: defaultText }))
                        .filter((item) => item && typeof item === 'object');
                    normalizedColumns.push(sanitizedChildren.length > 0
                        ? sanitizedChildren
                        : [normalizeBlock({ type: 'text', content: defaultText }, { allowColumns: false, fallbackText: defaultText })]);
                }
                return {
                    ...block,
                    columns,
                    columns_content: normalizedColumns,
                };
            });
            const unsubscribeFooterCanvasHtml = () => {
                if (!includeUnsubscribeFooter) return '';
                return `<div class="nl-canvas-footer-preview" style="margin-top:12px;border:1px dashed #d7dee7;border-radius:10px;background:#f8fafc;padding:14px;text-align:center;color:#64748b;font-size:12px;line-height:1.55;">
                    <p style="margin:0 0 8px;">Dacă nu mai vrei să primești email-uri de la noi, te poți dezabona aici ↓</p>
                    <span style="display:inline-block;padding:8px 14px;border:1px solid #cbd5e1;border-radius:999px;background:#ffffff;color:#334155;font-weight:600;">Dezabonează-mă</span>
                </div>`;
            };
            const unsubscribeFooterMailHtml = () => {
                if (!includeUnsubscribeFooter) return '';
                return '<!-- newsletter-unsubscribe-footer:start --><div style="max-width:680px;margin:16px auto 0;padding:14px 16px;text-align:center;color:#64748b;font-size:12px;line-height:1.55;"><p style="margin:0 0 8px;">Dacă nu mai vrei să primești email-uri de la noi, te poți dezabona aici ↓</p><a href="#" style="display:inline-block;padding:8px 14px;border:1px solid #cbd5e1;border-radius:999px;background:#ffffff;color:#334155;font-weight:600;text-decoration:none;">Dezabonează-mă</a></div><!-- newsletter-unsubscribe-footer:end -->';
            };

            const blockTitle = (type) => ({
                header: 'Header',
                text: 'Text',
                image: 'Imagine',
                button: 'Buton',
                divider: 'Separator',
                spacer: 'Spațiu',
                columns_2: 'Secțiune 50% / 50%',
                columns_3: 'Secțiune 33% / 33% / 33%',
            }[type] || 'Bloc');
            const blockFieldsHtml = (block) => {
                const alignButtons = (align) => `
                    <div class="nl-align-group">
                        <button type="button" data-prop="align" data-value="left" class="${align === 'left' ? 'active' : ''}">≡</button>
                        <button type="button" data-prop="align" data-value="center" class="${align === 'center' ? 'active' : ''}">≣</button>
                        <button type="button" data-prop="align" data-value="right" class="${align === 'right' ? 'active' : ''}">≡</button>
                    </div>
                `;
                if (block.type === 'header') {
                    const mobileSize = Number.isFinite(Number(block.font_size_mobile))
                        ? Math.max(10, Math.min(96, Math.round(Number(block.font_size_mobile))))
                        : blockFontSize(block, 34);
                    return `
                        <div class="field">
                            <label>Conținut</label>
                            <textarea rows="5" data-prop="content">${escapeHtml(block.content || '')}</textarea>
                        </div>
                        <div class="field"><label>Dimensiune text desktop / tabletă (px)</label><input type="number" min="10" max="96" step="1" data-prop="font_size" value="${blockFontSize(block, 34)}"></div>
                        <div class="field"><label>Dimensiune text mobil (px)</label><input type="number" min="10" max="96" step="1" data-prop="font_size_mobile" value="${mobileSize}"></div>
                        <div class="field"><label>Aliniere</label>${alignButtons(block.align)}</div>
                        <div class="field"><label>Fundal</label><input type="color" data-prop="background" value="${escapeHtml(block.background || '#ffffff')}"></div>
                        <div class="field"><label>Text</label><input type="color" data-prop="text_color" value="${escapeHtml(block.text_color || '#1f2937')}"></div>
                    `;
                }
                if (block.type === 'text') {
                    const mobileSize = Number.isFinite(Number(block.font_size_mobile))
                        ? Math.max(10, Math.min(96, Math.round(Number(block.font_size_mobile))))
                        : blockFontSize(block, 15);
                    return `
                        <div class="field">
                            <label>Conținut</label>
                            <textarea rows="5" data-prop="content">${escapeHtml(block.content || '')}</textarea>
                            <small style="display:block;color:#64748b;margin-top:4px;">Link-uri suportate: URL direct (https://...), www..., sau format [text](https://url).</small>
                        </div>
                        <div class="field"><label>Dimensiune text desktop / tabletă (px)</label><input type="number" min="10" max="96" step="1" data-prop="font_size" value="${blockFontSize(block, 15)}"></div>
                        <div class="field"><label>Dimensiune text mobil (px)</label><input type="number" min="10" max="96" step="1" data-prop="font_size_mobile" value="${mobileSize}"></div>
                        <div class="field"><label>Aliniere</label>${alignButtons(block.align)}</div>
                        <div class="field"><label>Fundal</label><input type="color" data-prop="background" value="${escapeHtml(block.background || '#ffffff')}"></div>
                        <div class="field"><label>Text</label><input type="color" data-prop="text_color" value="${escapeHtml(block.text_color || '#1f2937')}"></div>
                    `;
                }
                if (block.type === 'image') {
                    return `
                        <div class="field"><label>URL imagine</label><input type="text" data-prop="image_url" value="${escapeHtml(block.image_url || '')}"></div>
                        <div class="field"><button type="button" class="btn btn-secondary" data-role="pick-image">Selectează din Galerie</button></div>
                        <div class="field"><label>Alt text</label><input type="text" data-prop="alt" value="${escapeHtml(block.alt || '')}"></div>
                        <div class="field"><label>Fundal bloc</label><input type="color" data-prop="block_background" value="${escapeHtml(blockContainerBg(block))}"></div>
                    `;
                }
                if (block.type === 'button') {
                    return `
                        <div class="field"><label>Text buton</label><input type="text" data-prop="label" value="${escapeHtml(block.label || '')}"></div>
                        <div class="field"><label>Link</label><input type="text" data-prop="url" value="${escapeHtml(block.url || '')}"></div>
                        <div class="field"><label>Aliniere</label>${alignButtons(block.align)}</div>
                        <div class="field"><label>Fundal bloc</label><input type="color" data-prop="block_background" value="${escapeHtml(blockContainerBg(block))}"></div>
                        <div class="field"><label>Fundal</label><input type="color" data-prop="background" value="${escapeHtml(block.background || '#1a7a5e')}"></div>
                        <div class="field"><label>Text</label><input type="color" data-prop="text_color" value="${escapeHtml(block.text_color || '#ffffff')}"></div>
                        <div class="field"><label>Dimensiune text (px)</label><input type="number" min="10" max="96" step="1" data-prop="font_size" value="${blockFontSize(block, 16)}"></div>
                        <div class="field"><label>Rotunjire (px)</label><input type="number" min="0" max="30" step="1" data-prop="radius" value="${Math.max(0, Number(block.radius || 0))}"></div>
                    `;
                }
                if (block.type === 'divider') {
                    return `
                        <div class="field"><label>Culoare separator</label><input type="color" data-prop="line_color" value="${escapeHtml(block.line_color || '#dbe2ea')}"></div>
                        <div class="field"><label>Fundal bloc</label><input type="color" data-prop="block_background" value="${escapeHtml(blockContainerBg(block))}"></div>
                    `;
                }
                if (block.type === 'spacer') {
                    return `
                        <div class="field"><label>Înălțime (px)</label><input type="number" min="4" max="200" step="1" data-prop="height" value="${Math.max(4, Number(block.height || 24))}"></div>
                        <div class="field"><label>Fundal bloc</label><input type="color" data-prop="block_background" value="${escapeHtml(blockContainerBg(block))}"></div>
                    `;
                }
                return '';
            };

            const renderProps = () => {
                if (blocks.length === 0) {
                    propsBox.innerHTML = '<p style="margin:0;color:#64748b;">Nu există blocuri.</p>';
                    return;
                }
                const block = blocks[selectedIndex];
                let html = `<p class="nl-prop-title">${blockTitle(block.type)}</p>`;
                if (isColumnsSection(block)) {
                    ensureSelectedColumnBlock(block);
                    const columns = block.type === 'columns_3' ? 3 : 2;
                    const currentColumn = Math.max(0, Math.min(columns - 1, Number(selectedColumnBlock.columnIndex || 0)));
                    const allColumns = Array.isArray(block.columns_content) ? block.columns_content : [];
                    const activeColumnBlocks = Array.isArray(allColumns[currentColumn]) ? allColumns[currentColumn] : [];
                    const activeBlockIndex = Math.max(0, Math.min(activeColumnBlocks.length - 1, Number(selectedColumnBlock.blockIndex || 0)));
                    html += `
                        <div class="field"><label>Fundal secțiune</label><input type="color" data-prop-section="block_background" value="${escapeHtml(blockContainerBg(block))}"></div>
                        <div class="field"><label>Fundal coloane</label><input type="color" data-prop-section="background" value="${escapeHtml(block.background || '#ffffff')}"></div>
                        <div class="field"><label>Culoare text implicită</label><input type="color" data-prop-section="text_color" value="${escapeHtml(block.text_color || '#1f2937')}"></div>
                    `;
                    html += '<div class="field"><label>Coloană activă</label><div class="nl-align-group">';
                    for (let index = 0; index < columns; index++) {
                        html += `<button type="button" data-role="column-select" data-column-index="${index}" class="${index === currentColumn ? 'active' : ''}">Col ${index + 1}</button>`;
                    }
                    html += '</div></div>';
                    html += '<div class="field"><label>Adaugă bloc în coloana activă</label><div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;">';
                    columnBlockPalette.forEach((item) => {
                        html += `<button type="button" class="btn btn-secondary" data-role="column-add-block" data-type="${item.type}">${item.icon} ${item.label}</button>`;
                    });
                    html += '</div></div>';
                    html += '<div class="field"><label>Blocuri în coloana activă</label><div style="display:flex;flex-direction:column;gap:6px;">';
                    activeColumnBlocks.forEach((item, index) => {
                        html += `<div style="display:flex;gap:6px;align-items:center;">
                            <button type="button" class="btn btn-secondary ${index === activeBlockIndex ? 'active' : ''}" style="flex:1;text-align:left;" data-role="column-select-block" data-column-index="${currentColumn}" data-block-index="${index}">${index + 1}. ${blockTitle(String(item?.type || 'text'))}</button>
                            <button type="button" class="icon-btn" data-role="column-move-block" data-column-index="${currentColumn}" data-block-index="${index}" data-direction="-1" title="Mută sus">↑</button>
                            <button type="button" class="icon-btn" data-role="column-move-block" data-column-index="${currentColumn}" data-block-index="${index}" data-direction="1" title="Mută jos">↓</button>
                            <button type="button" class="icon-btn danger" data-role="column-remove-block" data-column-index="${currentColumn}" data-block-index="${index}" title="Șterge">🗑</button>
                        </div>`;
                    });
                    html += '</div></div>';
                    const context = editableContext();
                    if (context?.block) {
                        html += `<hr style="border:none;border-top:1px solid #e2e8f0;margin:12px 0;"><p class="nl-prop-title" style="font-size:12px;">${blockTitle(context.block.type)} (col ${context.columnIndex + 1})</p>`;
                        html += blockFieldsHtml(context.block);
                    }
                } else {
                    html += `
                        ${blockFieldsHtml(block)}
                    `;
                }
                propsBox.innerHTML = html;
            };

            const syncHidden = () => {
                const sanitizedBlocks = sanitizeBlocksForSave();
                const serialized = JSON.stringify(sanitizedBlocks);
                blocksInput.value = serialized;
                const subject = escapeHtml(subjectInput?.value || '');
                const bodyHtml = sanitizedBlocks.map((block) => blockMailHtml(block)).join('');
                const footerHtml = unsubscribeFooterMailHtml();
                htmlInput.value = `<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${subject}</title>${responsiveMailStyles}</head><body class="nl-mail-body"><div class="nl-mail-container">${bodyHtml}</div></body></html>`;
                if (footerHtml !== '') {
                    htmlInput.value = htmlInput.value.replace('</body>', `${footerHtml}</body>`);
                }
            };

            const clearDropHints = () => {
                root.querySelectorAll('.nl-canvas-block').forEach((node) => {
                    node.classList.remove('drop-before');
                    node.classList.remove('drop-after');
                });
                canvas.classList.remove('drag-over');
            };

            const getCanvasDropIndex = (clientY) => {
                const nodes = Array.from(canvas.querySelectorAll('.nl-canvas-block'));
                for (const node of nodes) {
                    const rect = node.getBoundingClientRect();
                    const midpoint = rect.top + rect.height / 2;
                    if (clientY < midpoint) {
                        const idx = Number.parseInt(node.getAttribute('data-index') || '0', 10);
                        return Number.isInteger(idx) ? idx : 0;
                    }
                }
                return blocks.length;
            };

            const removeBlockAnimated = (index) => {
                if (!Number.isInteger(index) || index < 0 || index >= blocks.length || isAnimating) return;
                const node = canvas?.querySelector(`.nl-canvas-block[data-index="${index}"]`);
                isAnimating = true;
                const commit = () => {
                    blocks.splice(index, 1);
                    if (blocks.length === 0) {
                        blocks = [createBlock('text')];
                    }
                    selectedIndex = Math.min(selectedIndex, blocks.length - 1);
                    isAnimating = false;
                    render();
                };
                if (node instanceof HTMLElement) {
                    node.classList.add('is-removing');
                    window.setTimeout(commit, 180);
                } else {
                    commit();
                }
            };

            const moveBlockAnimated = (index, direction) => {
                if (isAnimating) return;
                const target = index + direction;
                if (target < 0 || target >= blocks.length) return;
                const nodeCurrent = canvas?.querySelector(`.nl-canvas-block[data-index="${index}"]`);
                const nodeTarget = canvas?.querySelector(`.nl-canvas-block[data-index="${target}"]`);
                isAnimating = true;
                if (nodeCurrent instanceof HTMLElement) nodeCurrent.classList.add('is-moving');
                if (nodeTarget instanceof HTMLElement) nodeTarget.classList.add('is-moving');
                window.setTimeout(() => {
                    const tmp = blocks[target];
                    blocks[target] = blocks[index];
                    blocks[index] = tmp;
                    selectedIndex = target;
                    isAnimating = false;
                    render();
                }, 180);
            };

            const insertToolAt = (type, index) => {
                const block = createBlock(type);
                if (!Number.isInteger(index) || index < 0 || index > blocks.length) {
                    blocks.push(block);
                    selectedIndex = blocks.length - 1;
                } else {
                    blocks.splice(index, 0, block);
                    selectedIndex = index;
                }
                render();
            };

            const reorderBlock = (fromIndex, toIndex) => {
                if (!Number.isInteger(fromIndex) || !Number.isInteger(toIndex)) return;
                if (fromIndex < 0 || fromIndex >= blocks.length) return;
                if (toIndex < 0) toIndex = 0;
                if (toIndex > blocks.length) toIndex = blocks.length;
                const [moved] = blocks.splice(fromIndex, 1);
                const nextIndex = toIndex > fromIndex ? toIndex - 1 : toIndex;
                blocks.splice(nextIndex, 0, moved);
                selectedIndex = nextIndex;
                render();
            };

            const render = () => {
                if (selectedIndex >= blocks.length) {
                    selectedIndex = Math.max(0, blocks.length - 1);
                }
                const blocksHtml = blocks.map((block, index) => `
                    <div class="nl-canvas-block ${index === selectedIndex ? 'selected' : ''}" draggable="true" data-role="select" data-index="${index}">
                        <div class="nl-canvas-tools">
                            <span>${blockTitle(block.type)}</span>
                            <button type="button" data-role="move-up" data-index="${index}">↑</button>
                            <button type="button" data-role="move-down" data-index="${index}">↓</button>
                            <button type="button" data-role="remove" data-index="${index}">🗑</button>
                        </div>
                        ${blockCanvasHtml(block)}
                    </div>
                `).join('');
                canvas.innerHTML = blocksHtml + unsubscribeFooterCanvasHtml();
                renderProps();
                syncHidden();
            };

            root.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;
                const node = target.closest('[data-role]');
                if (!(node instanceof HTMLElement)) return;
                const role = node.dataset.role || '';
                const index = Number.parseInt(node.dataset.index || '-1', 10);
                if (isAnimating && role !== 'select') return;

                if (role === 'add') {
                    const type = node.dataset.type || 'text';
                    blocks.push(createBlock(type));
                    selectedIndex = blocks.length - 1;
                    selectedColumnBlock = { columnIndex: 0, blockIndex: 0 };
                    render();
                    return;
                }
                if (role === 'select' && Number.isInteger(index) && index >= 0 && index < blocks.length) {
                    event.preventDefault();
                    selectedIndex = index;
                    selectedColumnBlock = { columnIndex: 0, blockIndex: 0 };
                    render();
                    return;
                }
                if (role === 'remove' && Number.isInteger(index) && index >= 0 && index < blocks.length) {
                    removeBlockAnimated(index);
                    return;
                }
                if (role === 'move-up' && Number.isInteger(index)) {
                    moveBlockAnimated(index, -1);
                    return;
                }
                if (role === 'move-down' && Number.isInteger(index)) {
                    moveBlockAnimated(index, 1);
                    return;
                }
                if (role === 'column-select') {
                    const columnIndex = Number.parseInt(node.dataset.columnIndex || '-1', 10);
                    const section = blocks[selectedIndex];
                    if (!isColumnsSection(section) || !Number.isInteger(columnIndex) || columnIndex < 0) {
                        return;
                    }
                    selectedColumnBlock = { columnIndex, blockIndex: 0 };
                    render();
                    return;
                }
                if (role === 'column-add-block') {
                    const section = blocks[selectedIndex];
                    if (!isColumnsSection(section)) {
                        return;
                    }
                    ensureSelectedColumnBlock(section);
                    const columns = Array.isArray(section.columns_content) ? [...section.columns_content] : [];
                    const columnIndex = Number(selectedColumnBlock.columnIndex || 0);
                    const columnBlocks = Array.isArray(columns[columnIndex]) ? [...columns[columnIndex]] : [];
                    const fallbackText = `Coloana ${columnIndex + 1}`;
                    const blockType = node.dataset.type || 'text';
                    columnBlocks.push(normalizeBlock({ type: blockType }, { allowColumns: false, fallbackText }));
                    columns[columnIndex] = columnBlocks;
                    section.columns_content = columns;
                    selectedColumnBlock = { columnIndex, blockIndex: columnBlocks.length - 1 };
                    render();
                    return;
                }
                if (role === 'column-select-block') {
                    const columnIndex = Number.parseInt(node.dataset.columnIndex || '-1', 10);
                    const blockIndex = Number.parseInt(node.dataset.blockIndex || '-1', 10);
                    if (!Number.isInteger(columnIndex) || columnIndex < 0 || !Number.isInteger(blockIndex) || blockIndex < 0) {
                        return;
                    }
                    selectedColumnBlock = { columnIndex, blockIndex };
                    render();
                    return;
                }
                if (role === 'column-move-block') {
                    const section = blocks[selectedIndex];
                    if (!isColumnsSection(section)) {
                        return;
                    }
                    const columnIndex = Number.parseInt(node.dataset.columnIndex || '-1', 10);
                    const blockIndex = Number.parseInt(node.dataset.blockIndex || '-1', 10);
                    const direction = Number.parseInt(node.dataset.direction || '0', 10);
                    if (!Number.isInteger(columnIndex) || columnIndex < 0 || !Number.isInteger(blockIndex) || blockIndex < 0 || !Number.isInteger(direction) || direction === 0) {
                        return;
                    }
                    const columns = Array.isArray(section.columns_content) ? [...section.columns_content] : [];
                    const columnBlocks = Array.isArray(columns[columnIndex]) ? [...columns[columnIndex]] : [];
                    const targetIndex = blockIndex + (direction > 0 ? 1 : -1);
                    if (targetIndex < 0 || targetIndex >= columnBlocks.length) {
                        return;
                    }
                    const temp = columnBlocks[targetIndex];
                    columnBlocks[targetIndex] = columnBlocks[blockIndex];
                    columnBlocks[blockIndex] = temp;
                    columns[columnIndex] = columnBlocks;
                    section.columns_content = columns;
                    selectedColumnBlock = { columnIndex, blockIndex: targetIndex };
                    render();
                    return;
                }
                if (role === 'column-remove-block') {
                    const section = blocks[selectedIndex];
                    if (!isColumnsSection(section)) {
                        return;
                    }
                    const columnIndex = Number.parseInt(node.dataset.columnIndex || '-1', 10);
                    const blockIndex = Number.parseInt(node.dataset.blockIndex || '-1', 10);
                    if (!Number.isInteger(columnIndex) || columnIndex < 0 || !Number.isInteger(blockIndex) || blockIndex < 0) {
                        return;
                    }
                    const columns = Array.isArray(section.columns_content) ? [...section.columns_content] : [];
                    const columnBlocks = Array.isArray(columns[columnIndex]) ? [...columns[columnIndex]] : [];
                    if (columnBlocks.length <= 1) {
                        const fallbackText = `Coloana ${columnIndex + 1}`;
                        columns[columnIndex] = [normalizeBlock({ type: 'text', content: fallbackText }, { allowColumns: false, fallbackText })];
                        section.columns_content = columns;
                        selectedColumnBlock = { columnIndex, blockIndex: 0 };
                        render();
                        return;
                    }
                    columnBlocks.splice(blockIndex, 1);
                    columns[columnIndex] = columnBlocks;
                    section.columns_content = columns;
                    selectedColumnBlock = { columnIndex, blockIndex: Math.max(0, Math.min(blockIndex, columnBlocks.length - 1)) };
                    render();
                    return;
                }
                if (role === 'pick-image') {
                    const context = editableContext();
                    if (!context?.block || context.block.type !== 'image') return;
                    imagePickerApply = (url) => {
                        const current = editableContext();
                        if (!current?.block || current.block.type !== 'image') return;
                        current.block.image_url = String(url || '');
                        render();
                    };
                    openModal('newsletter-image-modal');
                }
            });

            deleteSelectedBtn?.addEventListener('click', () => {
                const context = editableContext();
                if (!context) return;
                if (context.section) {
                    const section = context.section;
                    const columns = Array.isArray(section.columns_content) ? [...section.columns_content] : [];
                    const columnBlocks = Array.isArray(columns[context.columnIndex]) ? [...columns[context.columnIndex]] : [];
                    if (columnBlocks.length <= 1) {
                        const fallbackText = `Coloana ${context.columnIndex + 1}`;
                        columns[context.columnIndex] = [normalizeBlock({ type: 'text', content: fallbackText }, { allowColumns: false, fallbackText })];
                        section.columns_content = columns;
                        selectedColumnBlock = { columnIndex: context.columnIndex, blockIndex: 0 };
                    } else {
                        columnBlocks.splice(context.blockIndex, 1);
                        columns[context.columnIndex] = columnBlocks;
                        section.columns_content = columns;
                        selectedColumnBlock = {
                            columnIndex: context.columnIndex,
                            blockIndex: Math.max(0, Math.min(context.blockIndex, columnBlocks.length - 1)),
                        };
                    }
                    render();
                    return;
                }
                removeBlockAnimated(selectedIndex);
            });

            propsBox?.addEventListener('input', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)) return;
                const prop = target.dataset.prop || '';
                const sectionProp = target.dataset.propSection || '';
                if (prop === '' && sectionProp === '') return;
                const isColorInput = target instanceof HTMLInputElement && target.type === 'color';
                const isNumberInput = target instanceof HTMLInputElement && target.type === 'number';
                const caretStart = (target instanceof HTMLTextAreaElement || target.type === 'text' || target.type === 'url')
                    ? (target.selectionStart ?? null)
                    : null;
                const caretEnd = (target instanceof HTMLTextAreaElement || target.type === 'text' || target.type === 'url')
                    ? (target.selectionEnd ?? null)
                    : null;
                if (sectionProp !== '' && isColumnsSection(blocks[selectedIndex])) {
                    blocks[selectedIndex][sectionProp] = target.value;
                } else {
                    const context = editableContext();
                    if (!context?.block || prop === '') {
                        return;
                    }
                    context.block[prop] = (prop === 'radius' || prop === 'height' || prop === 'font_size' || prop === 'font_size_mobile')
                        ? Number(target.value || 0)
                        : target.value;
                }
                syncHidden();
                if (isColorInput || isNumberInput) {
                    return;
                }
                render();
                const selector = sectionProp !== ''
                    ? `[data-prop-section="${sectionProp}"]`
                    : `[data-prop="${prop}"]`;
                const focused = propsBox.querySelector(selector);
                if (focused instanceof HTMLInputElement || focused instanceof HTMLTextAreaElement) {
                    focused.focus();
                    if (caretStart !== null && caretEnd !== null && typeof focused.setSelectionRange === 'function') {
                        focused.setSelectionRange(caretStart, caretEnd);
                    }
                }
            });
            propsBox?.addEventListener('change', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)) return;
                const prop = target.dataset.prop || '';
                const sectionProp = target.dataset.propSection || '';
                if (prop === '' && sectionProp === '') return;
                if (target.type !== 'color' && target.type !== 'number') return;
                if (sectionProp !== '' && isColumnsSection(blocks[selectedIndex])) {
                    blocks[selectedIndex][sectionProp] = target.value;
                } else {
                    const context = editableContext();
                    if (!context?.block || prop === '') {
                        return;
                    }
                    context.block[prop] = target.value;
                    if (prop === 'radius' || prop === 'height' || prop === 'font_size' || prop === 'font_size_mobile') {
                        context.block[prop] = Number(target.value || 0);
                    }
                }
                render();
            });
            propsBox?.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;
                const prop = target.dataset.prop || '';
                if (prop !== 'align') return;
                const context = editableContext();
                if (!context?.block) return;
                context.block.align = target.dataset.value || 'left';
                render();
            });

            subjectInput?.addEventListener('input', () => syncHidden());
            form?.addEventListener('submit', () => syncHidden());
            previewBtn?.addEventListener('click', () => {
                syncHidden();
                if (!previewModal) return;
                setPreviewDevice('desktop');
                if (previewFrame) {
                    previewFrame.srcdoc = htmlInput?.value || '<p style="padding:20px;">Nu există conținut pentru preview.</p>';
                }
                openModal('newsletter-preview-modal');
            });

            root.addEventListener('dragstart', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;
                const toolNode = target.closest('.nl-builder-tool');
                const blockNode = target.closest('.nl-canvas-block');
                if (toolNode instanceof HTMLElement) {
                    dragState = { mode: 'tool', type: toolNode.dataset.type || 'text', index: -1 };
                    toolNode.classList.add('is-dragging');
                    return;
                }
                if (blockNode instanceof HTMLElement) {
                    const index = Number.parseInt(blockNode.dataset.index || '-1', 10);
                    if (Number.isInteger(index) && index >= 0) {
                        dragState = { mode: 'block', type: '', index };
                        blockNode.classList.add('is-dragging');
                    }
                }
            });

            root.addEventListener('dragend', () => {
                dragState = { mode: '', type: '', index: -1 };
                root.querySelectorAll('.is-dragging').forEach((node) => node.classList.remove('is-dragging'));
                clearDropHints();
            });

            canvas?.addEventListener('dragover', (event) => {
                event.preventDefault();
                canvas.classList.add('drag-over');
                clearDropHints();
                if (blocks.length === 0) return;
                const dropIndex = getCanvasDropIndex(event.clientY);
                if (dropIndex <= 0) {
                    const first = canvas.querySelector('.nl-canvas-block[data-index="0"]');
                    first?.classList.add('drop-before');
                } else if (dropIndex >= blocks.length) {
                    const last = canvas.querySelector(`.nl-canvas-block[data-index="${blocks.length - 1}"]`);
                    last?.classList.add('drop-after');
                } else {
                    const node = canvas.querySelector(`.nl-canvas-block[data-index="${dropIndex}"]`);
                    node?.classList.add('drop-before');
                }
            });

            canvas?.addEventListener('drop', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const dropIndex = getCanvasDropIndex(event.clientY);
                if (dragState.mode === 'tool') {
                    insertToolAt(dragState.type, dropIndex);
                } else if (dragState.mode === 'block') {
                    reorderBlock(dragState.index, dropIndex);
                }
                clearDropHints();
            });

            root.addEventListener('dragover', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;
                const blockNode = target.closest('.nl-canvas-block');
                if (!(blockNode instanceof HTMLElement)) return;
                event.preventDefault();
                clearDropHints();
                const rect = blockNode.getBoundingClientRect();
                const before = event.clientY < rect.top + rect.height / 2;
                blockNode.classList.add(before ? 'drop-before' : 'drop-after');
            });

            root.addEventListener('drop', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;
                const blockNode = target.closest('.nl-canvas-block');
                if (!(blockNode instanceof HTMLElement)) return;
                event.preventDefault();
                const index = Number.parseInt(blockNode.dataset.index || '-1', 10);
                if (!Number.isInteger(index) || index < 0) return;
                const rect = blockNode.getBoundingClientRect();
                const before = event.clientY < rect.top + rect.height / 2;
                const dropIndex = before ? index : index + 1;
                if (dragState.mode === 'tool') {
                    insertToolAt(dragState.type, dropIndex);
                } else if (dragState.mode === 'block') {
                    reorderBlock(dragState.index, dropIndex);
                }
                clearDropHints();
            });

            const trackedInputs = form
                ? Array.from(form.querySelectorAll('input:not([type="hidden"]), textarea, select'))
                : [];
            const computeState = () => JSON.stringify({
                blocks: blocksInput.value || '',
                subject: subjectInput?.value || '',
                tracked: trackedInputs.map((element) => {
                    if (element instanceof HTMLInputElement && element.type === 'checkbox') {
                        return element.checked ? '1' : '0';
                    }
                    return (element.value || '').trim();
                }),
            });
            let initialState = '';
            const markAsSaved = () => {
                initialState = computeState();
            };
            const hasUnsavedChanges = () => computeState() !== initialState;

            trackedInputs.forEach((element) => {
                element.addEventListener('input', () => syncHidden());
                element.addEventListener('change', () => syncHidden());
            });
            form?.addEventListener('submit', () => {
                syncHidden();
                markAsSaved();
            });

            render();
            markAsSaved();

            return {
                hasUnsavedChanges,
            };
        };

        const campaignBuilderApi = makeBuilder({
            rootId: 'campaign-builder-root',
            formId: 'campaign-builder-form',
            subjectInputId: 'campaign-builder-subject',
            blocksInputId: 'campaign-builder-blocks-json',
            htmlInputId: 'campaign-builder-html-content',
            previewButtonId: 'campaign-builder-preview-btn',
            initialBlocks: <?= json_encode($campaignBuilderBlocksForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            includeUnsubscribeFooter: true,
        });
        const templateBuilderApi = makeBuilder({
            rootId: 'newsletter-template-builder-root',
            formId: 'newsletter-template-builder-form',
            subjectInputId: 'newsletter-template-subject',
            blocksInputId: 'newsletter-template-blocks-json',
            htmlInputId: 'newsletter-template-html-content',
            previewButtonId: 'template-builder-preview-btn',
            initialBlocks: <?= json_encode($newsletterBuilderBlocksForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            includeUnsubscribeFooter: true,
        });
        const ecommerceBuilderApi = makeBuilder({
            rootId: 'ecommerce-template-builder-root',
            formId: 'ecommerce-template-builder-form',
            subjectInputId: 'ecommerce-template-subject',
            blocksInputId: 'ecommerce-template-blocks-json',
            htmlInputId: 'ecommerce-template-html-content',
            previewButtonId: 'ecommerce-builder-preview-btn',
            initialBlocks: <?= json_encode($ecommerceBuilderBlocksForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        });

        const ecommerceSendTestForm = document.getElementById('ecommerce-template-send-test-form');
        ecommerceSendTestForm?.addEventListener('submit', () => {
            const subjectSource = document.getElementById('ecommerce-template-subject');
            const htmlSource = document.getElementById('ecommerce-template-html-content');
            const subjectTarget = document.getElementById('ecommerce-template-test-subject');
            const htmlTarget = document.getElementById('ecommerce-template-test-html');
            if (subjectSource instanceof HTMLInputElement && subjectTarget instanceof HTMLInputElement) {
                subjectTarget.value = subjectSource.value;
            }
            if (htmlSource instanceof HTMLInputElement && htmlTarget instanceof HTMLInputElement) {
                htmlTarget.value = htmlSource.value;
            }
        });

        document.getElementById('template-builder-back-btn')?.addEventListener('click', (event) => {
            const target = event.currentTarget;
            if (!(target instanceof HTMLElement)) return;
            if (templateBuilderApi && templateBuilderApi.hasUnsavedChanges() && !confirm('Ai modificări nesalvate. Sigur vrei să te întorci?')) {
                return;
            }
            window.location.href = target.getAttribute('data-back-url') || '/admin/emails/newsletters?tab=templates';
        });
        document.getElementById('ecommerce-builder-back-btn')?.addEventListener('click', (event) => {
            const target = event.currentTarget;
            if (!(target instanceof HTMLElement)) return;
            if (ecommerceBuilderApi && ecommerceBuilderApi.hasUnsavedChanges() && !confirm('Ai modificări nesalvate. Sigur vrei să te întorci?')) {
                return;
            }
            window.location.href = target.getAttribute('data-back-url') || '/admin/emails/newsletters?tab=ecommerce';
        });
        document.getElementById('campaign-builder-back-btn')?.addEventListener('click', (event) => {
            const target = event.currentTarget;
            if (!(target instanceof HTMLElement)) return;
            if (campaignBuilderApi && campaignBuilderApi.hasUnsavedChanges() && !confirm('Ai modificări nesalvate. Sigur vrei să te întorci?')) {
                return;
            }
            window.location.href = target.getAttribute('data-back-url') || '/admin/emails/newsletters?tab=campaigns';
        });

        const makeOptInBuilder = () => {
            const root = document.getElementById('optin-builder-root');
            const form = document.getElementById('optin-form-builder-form');
            const fieldsInput = document.getElementById('optin-fields-json');
            if (!root || !form || !fieldsInput) return null;

            const nameInput = document.getElementById('optin-form-name');
            const slugInput = document.getElementById('optin-form-slug');
            const listInput = document.getElementById('optin-form-list-id');
            const buttonInput = document.getElementById('optin-form-button-label');
            const successInput = document.getElementById('optin-form-success-message');
            const activeInput = document.getElementById('optin-form-is-active');
            const canvasColumnsInput = document.getElementById('optin-canvas-columns');
            const endpointPreview = document.getElementById('optin-endpoint-preview');
            const embedHtmlCode = document.getElementById('optin-embed-html');
            const embedCssCode = document.getElementById('optin-embed-css');
            const embedJsCode = document.getElementById('optin-embed-js');
            const previewBtn = document.getElementById('optin-builder-preview-btn');

            const slugify = (value) => String(value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            const esc = (value) => String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');

            const normalizeColumns = (value) => (Number(value) === 2 ? 2 : 1);
            const normalizeOffset = (value) => {
                const parsed = Number.parseInt(String(value ?? '0'), 10);
                if (!Number.isFinite(parsed)) {
                    return 0;
                }
                return Math.max(-40, Math.min(40, parsed));
            };
            const normalizeColor = (value, fallback) => {
                const input = String(value ?? '').trim();
                if (/^#[0-9a-fA-F]{6}$/.test(input)) {
                    return input.toLowerCase();
                }
                return fallback;
            };
            const normalizeField = (field, fallbackType = 'text') => {
                const type = ['email', 'text', 'textarea', 'tel', 'checkbox'].includes(String(field?.type || ''))
                    ? String(field?.type || '')
                    : fallbackType;
                const name = slugify(String(field?.name || ''));
                return {
                    type,
                    name: name !== '' ? name : slugify(type + '-' + Math.floor(Math.random() * 10000)),
                    label: String(field?.label || ''),
                    placeholder: String(field?.placeholder || ''),
                    required: Number(field?.required || 0) === 1 ? 1 : 0,
                    width: String(field?.width || 'full') === 'half' ? 'half' : 'full',
                    offset_y: normalizeOffset(field?.offset_y),
                    label_color: normalizeColor(field?.label_color, '#334155'),
                    input_text_color: normalizeColor(field?.input_text_color, '#0f172a'),
                    input_bg_color: normalizeColor(field?.input_bg_color, '#f8fafc'),
                    input_border_color: normalizeColor(field?.input_border_color, '#cbd5e1'),
                };
            };
            const defaultFieldByType = (type, columns = 1) => {
                const safeType = ['email', 'text', 'textarea', 'tel', 'checkbox'].includes(type) ? type : 'text';
                const baseName = safeType === 'email' ? 'email' : `${safeType}_${Date.now().toString().slice(-5)}`;
                return normalizeField({
                    type: safeType,
                    name: baseName,
                    label: safeType === 'email' ? 'Email' : 'Câmp nou',
                    placeholder: safeType === 'email' ? 'email@exemplu.ro' : '',
                    required: safeType === 'email' ? 1 : 0,
                    width: safeType === 'checkbox' ? 'full' : (columns === 2 ? 'half' : 'full'),
                    offset_y: 0,
                    label_color: '#334155',
                    input_text_color: '#0f172a',
                    input_bg_color: '#f8fafc',
                    input_border_color: '#cbd5e1',
                }, safeType);
            };

            const initialRows = <?= json_encode($optInInitialFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const initialConfig = {
                fields: [],
                columns: normalizeColumns(<?= (int) $optInLayoutColumns ?>),
                submit: {
                    label: (buttonInput?.value || 'Ma abonez').trim() || 'Ma abonez',
                    style: 'primary',
                    align: 'left',
                    width: 'full',
                    position: null,
                    offset_y: 0,
                    bg_color: '#0f8f7a',
                    text_color: '#ffffff',
                    border_color: '#0f8f7a',
                },
            };
            if (Array.isArray(initialRows)) {
                initialRows.forEach((row) => {
                    if (!row || typeof row !== 'object') return;
                    const type = String(row?.type || '');
                    if (type === '__layout') {
                        initialConfig.columns = normalizeColumns(row?.columns ?? row?.layout_columns ?? 1);
                        return;
                    }
                    if (type === '__submit') {
                        const label = (String(row?.label ?? '') || '').trim();
                        if (label !== '') {
                            initialConfig.submit.label = label;
                        }
                        initialConfig.submit.style = ['primary', 'outline'].includes(String(row?.style || ''))
                            ? String(row?.style)
                            : 'primary';
                        initialConfig.submit.align = ['left', 'center', 'right'].includes(String(row?.align || ''))
                            ? String(row?.align)
                            : 'left';
                        initialConfig.submit.width = String(row?.width || 'full') === 'half' ? 'half' : 'full';
                        const submitPosition = Number.parseInt(String(row?.position ?? ''), 10);
                        if (Number.isInteger(submitPosition) && submitPosition >= 0) {
                            initialConfig.submit.position = submitPosition;
                        }
                        initialConfig.submit.offset_y = normalizeOffset(row?.offset_y);
                        const submitDefaults = initialConfig.submit.style === 'outline'
                            ? { bg: '#ffffff', text: '#0f8f7a', border: '#0f8f7a' }
                            : { bg: '#0f8f7a', text: '#ffffff', border: '#0f8f7a' };
                        initialConfig.submit.bg_color = normalizeColor(row?.bg_color, submitDefaults.bg);
                        initialConfig.submit.text_color = normalizeColor(row?.text_color, submitDefaults.text);
                        initialConfig.submit.border_color = normalizeColor(row?.border_color, submitDefaults.border);
                        return;
                    }
                    if (type === '__meta') {
                        initialConfig.columns = normalizeColumns(row?.columns ?? row?.layout_columns ?? 1);
                        return;
                    }
                    initialConfig.fields.push(normalizeField(row));
                });
            }

            let fields = initialConfig.fields;
            let canvasColumns = initialConfig.columns;
            let submitConfig = { ...initialConfig.submit };
            if (fields.length === 0) {
                fields = [defaultFieldByType('email', canvasColumns)];
            }
            let submitPosition = Number.isInteger(initialConfig.submit.position)
                ? Math.max(0, Math.min(fields.length, Number(initialConfig.submit.position)))
                : fields.length;

            let selectedIndex = 0;
            let selectedSubmit = false;
            let movedIndex = -1;
            let dragIndex = -1;
            let dropPosition = 'after';
            let dropSlot = null;
            let dragSource = '';
            let dragToolType = '';
            let dropTargetKind = 'none';
            let dropTargetIndex = -1;

            const ensureEmailField = () => {
                if (!fields.some((field) => String(field?.name || '') === 'email')) {
                    fields.unshift(defaultFieldByType('email', canvasColumns));
                    submitPosition = Math.min(fields.length, submitPosition + 1);
                }
                fields = fields.map((field) => {
                    if (String(field?.name || '') === 'email') {
                        return {
                            ...field,
                            type: 'email',
                            required: 1,
                            label: field.label || 'Email',
                            width: field.width === 'half' ? 'half' : 'full',
                        };
                    }
                    return field;
                });
            };
            const alignToText = (align) => (align === 'center' ? 'center' : (align === 'right' ? 'right' : 'left'));
            const fieldColors = (field) => ({
                label: normalizeColor(field?.label_color, '#334155'),
                inputText: normalizeColor(field?.input_text_color, '#0f172a'),
                inputBg: normalizeColor(field?.input_bg_color, '#f8fafc'),
                inputBorder: normalizeColor(field?.input_border_color, '#cbd5e1'),
            });
            const submitColors = (config) => {
                const defaults = config?.style === 'outline'
                    ? { bg: '#ffffff', text: '#0f8f7a', border: '#0f8f7a' }
                    : { bg: '#0f8f7a', text: '#ffffff', border: '#0f8f7a' };
                return {
                    bg: normalizeColor(config?.bg_color, defaults.bg),
                    text: normalizeColor(config?.text_color, defaults.text),
                    border: normalizeColor(config?.border_color, defaults.border),
                };
            };
            const buttonInlineStyle = (config) => {
                const colors = submitColors(config || submitConfig);
                return `background:${colors.bg};color:${colors.text};border:1px solid ${colors.border};`;
            };
            const submitRowInlineStyle = (columns, config) => {
                const fullSpanStyle = columns === 2 ? 'grid-column:1 / -1;' : '';
                const submitSpanStyle = (columns === 2 && config.width === 'half') ? '' : fullSpanStyle;
                const offset = normalizeOffset(config.offset_y);
                const offsetStyle = offset !== 0 ? `margin-top:${offset}px;` : '';
                return `${submitSpanStyle}text-align:${alignToText(config.align)};${offsetStyle}`;
            };
            const fieldInlineStyles = (field, columns) => {
                const colors = fieldColors(field || {});
                const offset = normalizeOffset(field?.offset_y);
                const isHalf = columns === 2 && String(field?.width || 'full') === 'half';
                const wrapperParts = [];
                if (!isHalf) {
                    wrapperParts.push('grid-column:1 / -1;');
                }
                if (offset !== 0) {
                    wrapperParts.push(`margin-top:${offset}px;`);
                }
                return {
                    wrapper: wrapperParts.join(''),
                    label: `color:${colors.label};`,
                    input: `color:${colors.inputText};background:${colors.inputBg};border-color:${colors.inputBorder};`,
                    checkboxLabel: `color:${colors.label};`,
                    checkboxInput: `accent-color:${colors.inputBorder};`,
                };
            };
            const serializedFields = () => {
                const submitLabel = (submitConfig.label || '').trim() || 'Ma abonez';
                const submitPalette = submitColors(submitConfig);
                return [
                    ...fields,
                    { type: '__layout', columns: canvasColumns, required: 0 },
                    {
                        type: '__submit',
                        label: submitLabel,
                        style: submitConfig.style === 'outline' ? 'outline' : 'primary',
                        align: ['left', 'center', 'right'].includes(submitConfig.align) ? submitConfig.align : 'left',
                        width: submitConfig.width === 'half' ? 'half' : 'full',
                        position: submitPosition,
                        offset_y: normalizeOffset(submitConfig.offset_y),
                        bg_color: submitPalette.bg,
                        text_color: submitPalette.text,
                        border_color: submitPalette.border,
                        required: 0,
                    },
                ];
            };

            root.innerHTML = `
                <aside class="optin-editor-left">
                    <h4>CÂMPURI</h4>
                    <button type="button" class="optin-tool-btn" data-role="add" data-drag-role="tool-drag" data-type="email" draggable="true">✉ Email</button>
                    <button type="button" class="optin-tool-btn" data-role="add" data-drag-role="tool-drag" data-type="text" draggable="true">T Text</button>
                    <button type="button" class="optin-tool-btn" data-role="add" data-drag-role="tool-drag" data-type="textarea" draggable="true">¶ Text lung</button>
                    <button type="button" class="optin-tool-btn" data-role="add" data-drag-role="tool-drag" data-type="tel" draggable="true">☎ Telefon</button>
                    <button type="button" class="optin-tool-btn" data-role="add" data-drag-role="tool-drag" data-type="checkbox" draggable="true">☑ Checkbox</button>
                </aside>
                <div class="optin-editor-center">
                    <div class="optin-editor-head">
                        <strong>Canvas formular</strong>
                    </div>
                    <div class="optin-canvas-grid" data-role="canvas"></div>
                </div>
                <aside class="optin-editor-right">
                    <div class="optin-props-head">
                        <strong data-role="props-title">Proprietăți câmp</strong>
                        <button type="button" class="icon-btn danger" data-role="delete-selected" title="Șterge câmp">🗑</button>
                    </div>
                    <div data-role="props"></div>
                </aside>
            `;

            const canvas = root.querySelector('[data-role="canvas"]');
            const propsBox = root.querySelector('[data-role="props"]');
            const propsTitle = root.querySelector('[data-role="props-title"]');
            const deleteSelectedBtn = root.querySelector('[data-role="delete-selected"]');

            const fieldTitle = (field) => {
                const map = { email: 'Email', text: 'Text', textarea: 'Text lung', tel: 'Telefon', checkbox: 'Checkbox' };
                return map[String(field?.type || 'text')] || 'Câmp';
            };
            const fieldCanvasInner = (field) => {
                const label = esc(field.label || field.name || 'Câmp');
                const placeholder = esc(field.placeholder || '');
                const styles = fieldInlineStyles(field || {}, canvasColumns);
                if (field.type === 'textarea') {
                    return `<label style="${styles.label}">${label}${field.required ? ' *' : ''}</label><textarea style="${styles.input}" placeholder="${placeholder}" rows="3" disabled></textarea>`;
                }
                if (field.type === 'checkbox') {
                    return `<label style="${styles.checkboxLabel}"><input style="${styles.checkboxInput}" type="checkbox" disabled> ${label}${field.required ? ' *' : ''}</label>`;
                }
                return `<label style="${styles.label}">${label}${field.required ? ' *' : ''}</label><input style="${styles.input}" type="${esc(field.type || 'text')}" placeholder="${placeholder}" disabled>`;
            };
            const clearDropDecor = () => {
                canvas?.querySelectorAll('.optin-canvas-field, .optin-canvas-submit').forEach((node) => {
                    node.classList.remove('drop-before');
                    node.classList.remove('drop-after');
                    node.classList.remove('drop-slot-left');
                    node.classList.remove('drop-slot-right');
                    node.classList.remove('is-dragging');
                });
                root.querySelectorAll('.optin-tool-btn.is-dragging').forEach((node) => node.classList.remove('is-dragging'));
                if (dropSlot && dropSlot.parentNode) {
                    dropSlot.parentNode.removeChild(dropSlot);
                }
                dropSlot = null;
                canvas?.classList.remove('is-dragging');
            };
            const draggedFieldSpanClass = () => {
                if (canvasColumns !== 2) {
                    return 'is-full';
                }
                if (dragSource === 'submit') {
                    return submitConfig.width === 'half' ? 'is-half' : 'is-full';
                }
                if (dragSource === 'tool') {
                    const draftField = defaultFieldByType(dragToolType || 'text', canvasColumns);
                    return String(draftField?.width || 'full') === 'half' ? 'is-half' : 'is-full';
                }
                if (dragSource === 'field') {
                    const field = fields[dragIndex];
                    if (!field) {
                        return 'is-full';
                    }
                    return String(field.width || 'full') === 'half' ? 'is-half' : 'is-full';
                }
                return 'is-full';
            };
            const ensureDropSlot = () => {
                if (!canvas) return null;
                if (!(dropSlot instanceof HTMLElement)) {
                    dropSlot = document.createElement('div');
                    dropSlot.className = 'optin-drop-slot';
                }
                dropSlot.classList.remove('is-full', 'is-half');
                dropSlot.classList.add(draggedFieldSpanClass());
                dropSlot.classList.add('active');
                return dropSlot;
            };
            const placeDropSlot = (targetKind, index, position) => {
                if (!canvas || dragSource === '') return;
                const slot = ensureDropSlot();
                if (!(slot instanceof HTMLElement)) return;
                const submitNode = canvas.querySelector('.optin-canvas-submit');
                const targetNode = targetKind === 'field' && Number.isInteger(index) && index >= 0 && index < fields.length
                    ? canvas.querySelector(`.optin-canvas-field[data-index="${index}"]`)
                    : null;
                let beforeNode = null;
                if (targetNode instanceof HTMLElement) {
                    if (position === 'before') {
                        beforeNode = targetNode;
                    } else {
                        beforeNode = targetNode.nextElementSibling;
                    }
                } else if (targetKind === 'submit' && submitNode instanceof HTMLElement) {
                    if (position === 'before') {
                        beforeNode = submitNode;
                    } else {
                        beforeNode = submitNode.nextElementSibling;
                    }
                } else if (submitNode instanceof HTMLElement) {
                    beforeNode = submitNode;
                }
                if (beforeNode instanceof HTMLElement) {
                    if (slot !== beforeNode.previousElementSibling) {
                        canvas.insertBefore(slot, beforeNode);
                    }
                } else if (slot.parentNode !== canvas || canvas.lastElementChild !== slot) {
                    canvas.appendChild(slot);
                }
            };
            const buildEntries = () => {
                const entries = [];
                const safeSubmitPosition = Math.max(0, Math.min(fields.length, submitPosition));
                for (let fieldIndex = 0; fieldIndex <= fields.length; fieldIndex += 1) {
                    if (fieldIndex === safeSubmitPosition) {
                        entries.push({ kind: 'submit' });
                    }
                    if (fieldIndex < fields.length) {
                        entries.push({ kind: 'field', field: fields[fieldIndex], fieldIndex });
                    }
                }
                return entries;
            };
            const applyEntries = (entries) => {
                const nextFields = [];
                let nextSubmitPosition = entries.length;
                entries.forEach((entry) => {
                    if (!entry || typeof entry !== 'object') return;
                    if (entry.kind === 'submit') {
                        nextSubmitPosition = nextFields.length;
                        return;
                    }
                    if (entry.kind === 'field' && entry.field && typeof entry.field === 'object') {
                        nextFields.push(entry.field);
                    }
                });
                fields = nextFields;
                submitPosition = Math.max(0, Math.min(fields.length, nextSubmitPosition));
            };
            const resolveInsertOrder = (entries, targetKind, targetIndex, position) => {
                if (targetKind === 'field' && Number.isInteger(targetIndex) && targetIndex >= 0 && targetIndex < fields.length) {
                    const fieldRef = fields[targetIndex];
                    const targetOrderIndex = entries.findIndex((entry) => entry.kind === 'field' && entry.field === fieldRef);
                    if (targetOrderIndex >= 0) {
                        return position === 'after' ? targetOrderIndex + 1 : targetOrderIndex;
                    }
                }
                if (targetKind === 'submit') {
                    const submitOrderIndex = entries.findIndex((entry) => entry.kind === 'submit');
                    if (submitOrderIndex >= 0) {
                        return position === 'after' ? submitOrderIndex + 1 : submitOrderIndex;
                    }
                }
                const submitOrderIndex = entries.findIndex((entry) => entry.kind === 'submit');
                if (submitOrderIndex >= 0) {
                    return submitOrderIndex;
                }
                return entries.length;
            };
            const dropTargetFromPointer = (event) => {
                if (!canvas) {
                    return { kind: 'submit', index: -1, position: 'after' };
                }
                const pointerY = Number(event?.clientY ?? 0);
                const pointerX = Number(event?.clientX ?? 0);
                const submitNode = canvas.querySelector('.optin-canvas-submit');
                const fieldNodes = Array.from(canvas.querySelectorAll('.optin-canvas-field'));
                const nearestField = fieldNodes.reduce((acc, node) => {
                    if (!(node instanceof HTMLElement)) return acc;
                    const rect = node.getBoundingClientRect();
                    const midY = rect.top + (rect.height / 2);
                    const distance = Math.abs(pointerY - midY);
                    if (!acc || distance < acc.distance) {
                        return { node, rect, distance };
                    }
                    return acc;
                }, null);

                if (!nearestField) {
                    if (submitNode instanceof HTMLElement) {
                        const submitRect = submitNode.getBoundingClientRect();
                        if (pointerY < submitRect.top + (submitRect.height / 2)) {
                            return { kind: 'submit', index: -1, position: 'before' };
                        }
                    }
                    return { kind: 'submit', index: -1, position: 'after' };
                }

                const index = Number.parseInt(nearestField.node.getAttribute('data-index') || '-1', 10);
                if (!Number.isInteger(index) || index < 0) {
                    return { kind: 'submit', index: -1, position: 'after' };
                }
                const hoveredField = fields[index] || null;
                const canUseColumnSlots = canvasColumns === 2
                    && String(draggedFieldSpanClass()) === 'is-half'
                    && String(hoveredField?.width || 'full') === 'half';
                if (canUseColumnSlots) {
                    const placeLeft = pointerX < nearestField.rect.left + nearestField.rect.width / 2;
                    return { kind: 'field', index, position: placeLeft ? 'before' : 'after', slotSide: placeLeft ? 'left' : 'right', node: nearestField.node };
                }
                const before = pointerY < nearestField.rect.top + nearestField.rect.height / 2;
                return { kind: 'field', index, position: before ? 'before' : 'after', node: nearestField.node };
            };
            const deleteFieldAt = (index) => {
                if (!Number.isInteger(index) || index < 0 || index >= fields.length) return;
                if (String(fields[index]?.name || '') === 'email') return;
                const node = canvas?.querySelector(`.optin-canvas-field[data-index="${index}"]`);
                if (node instanceof HTMLElement) {
                    node.classList.add('is-removing');
                    window.setTimeout(() => {
                        if (index < submitPosition) {
                            submitPosition = Math.max(0, submitPosition - 1);
                        }
                        fields.splice(index, 1);
                        submitPosition = Math.min(submitPosition, fields.length);
                        selectedIndex = Math.min(selectedIndex, fields.length - 1);
                        selectedSubmit = false;
                        render();
                    }, 150);
                    return;
                }
                if (index < submitPosition) {
                    submitPosition = Math.max(0, submitPosition - 1);
                }
                fields.splice(index, 1);
                submitPosition = Math.min(submitPosition, fields.length);
                selectedIndex = Math.min(selectedIndex, fields.length - 1);
                selectedSubmit = false;
                render();
            };
            const moveField = (fromIndex, targetKind, targetIndex, position) => {
                if (!Number.isInteger(fromIndex) || fromIndex < 0 || fromIndex >= fields.length) return -1;
                const entries = buildEntries();
                const movedFieldRef = fields[fromIndex];
                if (!movedFieldRef) return -1;
                const sourceOrderIndex = entries.findIndex((entry) => entry.kind === 'field' && entry.field === movedFieldRef);
                if (sourceOrderIndex < 0) return -1;
                const [movedEntry] = entries.splice(sourceOrderIndex, 1);
                if (!movedEntry) return -1;
                let insertOrderIndex = resolveInsertOrder(entries, targetKind, targetIndex, position);
                insertOrderIndex = Math.max(0, Math.min(entries.length, insertOrderIndex));
                entries.splice(insertOrderIndex, 0, movedEntry);
                applyEntries(entries);
                const movedTo = fields.findIndex((field) => field === movedFieldRef);
                return movedTo >= 0 ? movedTo : 0;
            };
            const moveSubmit = (targetKind, targetIndex, position) => {
                const entries = buildEntries();
                const submitOrderIndex = entries.findIndex((entry) => entry.kind === 'submit');
                if (submitOrderIndex < 0) return;
                const [submitEntry] = entries.splice(submitOrderIndex, 1);
                if (!submitEntry) return;
                let insertOrderIndex = resolveInsertOrder(entries, targetKind, targetIndex, position);
                insertOrderIndex = Math.max(0, Math.min(entries.length, insertOrderIndex));
                entries.splice(insertOrderIndex, 0, submitEntry);
                applyEntries(entries);
            };
            const insertToolAtTarget = (type, targetKind, targetIndex, position) => {
                const draftField = defaultFieldByType(type || 'text', canvasColumns);
                const entries = buildEntries();
                const insertOrderIndex = resolveInsertOrder(entries, targetKind, targetIndex, position);
                const boundedIndex = Math.max(0, Math.min(entries.length, insertOrderIndex));
                entries.splice(boundedIndex, 0, { kind: 'field', field: draftField, fieldIndex: -1 });
                applyEntries(entries);
                const insertedIndex = fields.findIndex((field) => field === draftField);
                return insertedIndex >= 0 ? insertedIndex : Math.max(0, fields.length - 1);
            };

            const renderProps = () => {
                if (!propsBox) return;
                const layoutControl = `
                    <div class="field">
                        <label>Coloane formular</label>
                        <select data-prop="canvas_columns">
                            <option value="1" ${canvasColumns === 1 ? 'selected' : ''}>1 coloană</option>
                            <option value="2" ${canvasColumns === 2 ? 'selected' : ''}>2 coloane</option>
                        </select>
                    </div>
                `;
                if (selectedSubmit) {
                    if (propsTitle) propsTitle.textContent = 'Proprietăți submit';
                    if (deleteSelectedBtn instanceof HTMLButtonElement) {
                        deleteSelectedBtn.disabled = true;
                    }
                    const submitPalette = submitColors(submitConfig);
                    propsBox.innerHTML = `
                        ${layoutControl}
                        <div class="field">
                            <label>Text buton submit</label>
                            <input type="text" data-prop="submit_label" value="${esc(submitConfig.label || 'Ma abonez')}">
                        </div>
                        <div class="field">
                            <label>Stil buton</label>
                            <select data-prop="submit_style">
                                <option value="primary" ${submitConfig.style === 'primary' ? 'selected' : ''}>Primar (plin)</option>
                                <option value="outline" ${submitConfig.style === 'outline' ? 'selected' : ''}>Outline</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Aliniere buton</label>
                            <select data-prop="submit_align">
                                <option value="left" ${submitConfig.align === 'left' ? 'selected' : ''}>Stânga</option>
                                <option value="center" ${submitConfig.align === 'center' ? 'selected' : ''}>Centru</option>
                                <option value="right" ${submitConfig.align === 'right' ? 'selected' : ''}>Dreapta</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Așezare pe rând (submit)</label>
                            <select data-prop="submit_width">
                                <option value="full" ${submitConfig.width === 'full' ? 'selected' : ''}>Pe tot rândul</option>
                                <option value="half" ${submitConfig.width === 'half' ? 'selected' : ''}>Jumătate rând (doar la 2 coloane)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Poziție verticală (px)</label>
                            <input type="number" data-prop="submit_offset_y" min="-40" max="40" step="1" value="${normalizeOffset(submitConfig.offset_y)}">
                        </div>
                        <div class="field">
                            <label>Culoare fundal buton</label>
                            <input type="color" data-prop="submit_bg_color" value="${esc(submitPalette.bg)}">
                        </div>
                        <div class="field">
                            <label>Culoare text buton</label>
                            <input type="color" data-prop="submit_text_color" value="${esc(submitPalette.text)}">
                        </div>
                        <div class="field">
                            <label>Culoare bordură buton</label>
                            <input type="color" data-prop="submit_border_color" value="${esc(submitPalette.border)}">
                        </div>
                    `;
                    return;
                }
                if (propsTitle) propsTitle.textContent = 'Proprietăți câmp';
                const field = fields[selectedIndex];
                if (!field) {
                    propsBox.innerHTML = '<p style="margin:0;color:#64748b;">Nu există câmpuri.</p>';
                    return;
                }
                const isEmail = String(field?.name || '') === 'email';
                if (deleteSelectedBtn instanceof HTMLButtonElement) {
                    deleteSelectedBtn.disabled = isEmail;
                }
                const palette = fieldColors(field);
                propsBox.innerHTML = `
                    ${layoutControl}
                    <div class="field">
                        <label>Tip câmp</label>
                        <select data-prop="type" ${isEmail ? 'disabled' : ''}>
                            <option value="email" ${field.type === 'email' ? 'selected' : ''}>Email</option>
                            <option value="text" ${field.type === 'text' ? 'selected' : ''}>Text</option>
                            <option value="textarea" ${field.type === 'textarea' ? 'selected' : ''}>Textarea</option>
                            <option value="tel" ${field.type === 'tel' ? 'selected' : ''}>Telefon</option>
                            <option value="checkbox" ${field.type === 'checkbox' ? 'selected' : ''}>Checkbox</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Nume câmp</label>
                        <input type="text" data-prop="name" value="${esc(field.name || '')}" ${isEmail ? 'disabled' : ''}>
                    </div>
                    <div class="field">
                        <label>Label</label>
                        <input type="text" data-prop="label" value="${esc(field.label || '')}">
                    </div>
                    <div class="field">
                        <label>Placeholder</label>
                        <input type="text" data-prop="placeholder" value="${esc(field.placeholder || '')}">
                    </div>
                    <div class="field">
                        <label>Așezare pe rând</label>
                        <select data-prop="width">
                            <option value="full" ${field.width === 'full' ? 'selected' : ''}>Un singur câmp pe rând</option>
                            <option value="half" ${field.width === 'half' ? 'selected' : ''}>Două câmpuri pe rând</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Poziție verticală (px)</label>
                        <input type="number" data-prop="offset_y" min="-40" max="40" step="1" value="${normalizeOffset(field.offset_y)}">
                    </div>
                    <div class="field">
                        <label>Culoare label</label>
                        <input type="color" data-prop="label_color" value="${esc(palette.label)}">
                    </div>
                    <div class="field">
                        <label>Culoare text input</label>
                        <input type="color" data-prop="input_text_color" value="${esc(palette.inputText)}">
                    </div>
                    <div class="field">
                        <label>Culoare fundal input</label>
                        <input type="color" data-prop="input_bg_color" value="${esc(palette.inputBg)}">
                    </div>
                    <div class="field">
                        <label>Culoare bordură input</label>
                        <input type="color" data-prop="input_border_color" value="${esc(palette.inputBorder)}">
                    </div>
                    <div class="field">
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" data-prop="required" ${field.required ? 'checked' : ''} ${isEmail ? 'disabled' : ''}>
                            Câmp obligatoriu
                        </label>
                    </div>
                `;
            };

            const buildPreviewHtml = () => {
                const slug = (slugInput?.value || '').trim();
                const endpoint = '/newsletter/optin/' + slug;
                const columns = normalizeColumns(canvasColumns);
                const submitLabel = (submitConfig.label || buttonInput?.value || 'Ma abonez').trim() || 'Ma abonez';
                const submitRowStyle = submitRowInlineStyle(columns, submitConfig);
                const submitStyle = buttonInlineStyle(submitConfig);
                const rows = buildEntries().map((entry) => {
                    if (entry.kind === 'submit') {
                        return `<div class="submit-row" style="${submitRowStyle}"><button type="submit" style="${submitStyle}">${esc(submitLabel)}</button></div>`;
                    }
                    const field = entry.field || {};
                    const type = String(field?.type || 'text');
                    const label = esc(field.label || field.name || 'Câmp');
                    const placeholder = esc(field.placeholder || '');
                    const required = Number(field?.required || 0) === 1 ? ' required' : '';
                    const styles = fieldInlineStyles(field, columns);
                    const wrapperStyle = styles.wrapper !== '' ? ` style="${styles.wrapper}"` : '';
                    if (type === 'textarea') {
                        return `<div class="optin-item"${wrapperStyle}><label style="${styles.label}">${label}${required !== '' ? ' *' : ''}</label><textarea style="${styles.input}" name="${esc(field.name || '')}" placeholder="${placeholder}"${required}></textarea></div>`;
                    }
                    if (type === 'checkbox') {
                        return `<div class="optin-item"${wrapperStyle}><label class="optin-checkbox" style="${styles.checkboxLabel}"><input style="${styles.checkboxInput}" type="checkbox" name="${esc(field.name || '')}" value="1"${required}> ${label}${required !== '' ? ' *' : ''}</label></div>`;
                    }
                    return `<div class="optin-item"${wrapperStyle}><label style="${styles.label}">${label}${required !== '' ? ' *' : ''}</label><input style="${styles.input}" type="${esc(type)}" name="${esc(field.name || '')}" placeholder="${placeholder}"${required}></div>`;
                }).join('');
                return `<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>
                    body{margin:0;padding:20px;background:#f3f4f6;font-family:Arial,sans-serif;color:#0f172a;}
                    .card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;}
                    form{display:flex;flex-direction:column;gap:14px;}
                    .grid{display:grid;grid-template-columns:repeat(${columns},minmax(0,1fr));gap:10px 12px;}
                    .optin-item{display:flex;flex-direction:column;gap:6px;}
                    .submit-row button{align-self:flex-start;padding:10px 16px;border-radius:8px;font-weight:700;cursor:pointer;}
                    label{font-weight:600;font-size:13px;}
                    input,textarea{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:9px 10px;font:inherit;box-sizing:border-box;}
                    textarea{min-height:110px;resize:vertical;}
                    .optin-checkbox{display:flex;align-items:center;gap:8px;font-weight:500;}
                    .optin-checkbox input{width:auto;}
                    @media (max-width:700px){.grid{grid-template-columns:1fr;}}
                </style></head><body><div class="card"><form method="post" action="${esc(endpoint)}"><div class="grid">${rows}</div></form></div></body></html>`;
            };

            const sync = () => {
                ensureEmailField();
                canvasColumns = normalizeColumns(canvasColumns);
                if (selectedIndex >= fields.length) selectedIndex = Math.max(0, fields.length - 1);
                if (buttonInput instanceof HTMLInputElement) {
                    submitConfig.label = (buttonInput.value || submitConfig.label || '').trim() || 'Ma abonez';
                } else {
                    submitConfig.label = (submitConfig.label || '').trim() || 'Ma abonez';
                }
                const serialized = serializedFields();
                fieldsInput.value = JSON.stringify(serialized);
                const columns = normalizeColumns(canvasColumns);
                if (canvasColumnsInput instanceof HTMLInputElement) {
                    canvasColumnsInput.value = String(columns);
                }
                const slug = (slugInput?.value || '').trim();
                const endpoint = '/newsletter/optin/' + slug;
                if (endpointPreview) endpointPreview.textContent = endpoint;
                const escapedButton = esc(submitConfig.label);
                const submitRowStyle = submitRowInlineStyle(columns, submitConfig);
                const submitStyle = `${buttonInlineStyle(submitConfig)}padding:10px 16px;border-radius:8px;font-weight:700;cursor:pointer;line-height:1.2;font:inherit;`;
                const fieldsMarkup = buildEntries().map((entry) => {
                    if (entry.kind === 'submit') {
                        return `<div style="${submitRowStyle}">
      <button type="submit" style="${submitStyle}">${escapedButton}</button>
    </div>`;
                    }
                    const field = entry.field || {};
                    const type = String(field?.type || 'text');
                    const name = esc(field?.name || '');
                    const label = esc(field?.label || field?.name || 'Câmp');
                    const placeholder = esc(field?.placeholder || '');
                    const required = Number(field?.required || 0) === 1 ? ' required' : '';
                    const styles = fieldInlineStyles(field, columns);
                    const wrapperStyle = styles.wrapper !== '' ? ` style="${styles.wrapper}"` : '';
                    const labelStyle = `display:block;font-weight:600;font-size:13px;margin-bottom:6px;${styles.label}`;
                    const inputStyle = `width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:9px 10px;font:inherit;${styles.input}`;
                    if (type === 'textarea') {
                        return `<div${wrapperStyle}><label style="${labelStyle}">${label}${required !== '' ? ' *' : ''}</label><textarea style="${inputStyle}min-height:110px;resize:vertical;" name="${name}" placeholder="${placeholder}"${required}></textarea></div>`;
                    }
                    if (type === 'checkbox') {
                        return `<div${wrapperStyle}><label style="display:flex;align-items:center;gap:8px;font-weight:500;${styles.checkboxLabel}"><input style="width:auto;${styles.checkboxInput}" type="checkbox" name="${name}" value="1"${required}> ${label}${required !== '' ? ' *' : ''}</label></div>`;
                    }
                    return `<div${wrapperStyle}><label style="${labelStyle}">${label}${required !== '' ? ' *' : ''}</label><input style="${inputStyle}" type="${esc(type)}" name="${name}" placeholder="${placeholder}"${required}></div>`;
                }).join('\n    ');
                if (embedHtmlCode || embedCssCode || embedJsCode) {
                    const formId = `optin-form-${slugify(slug || 'formular-optin') || 'formular-optin'}`;
                    const scopedFieldMarkup = fieldsMarkup
                        .replaceAll('<div style="grid-column:1 / -1;', '<div class="optin-span-full" style="grid-column:1 / -1;')
                        .replaceAll('<div style="text-align:', '<div class="optin-submit-row" style="text-align:');
                    const embedHtml = `<form id="${formId}" method="post" action="${endpoint}">
  <div class="optin-grid">
    ${scopedFieldMarkup}
  </div>
</form>`;
                    const embedCss = `#${formId}{margin:0;}
#${formId} .optin-grid{display:grid;grid-template-columns:repeat(${columns},minmax(0,1fr));gap:10px 12px;align-items:start;}
#${formId} .optin-span-full,#${formId} .optin-submit-row{grid-column:1 / -1;}
#${formId} label{display:block;font-weight:600;font-size:13px;margin-bottom:6px;}
#${formId} input,#${formId} textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:9px 10px;font:inherit;}
#${formId} textarea{min-height:110px;resize:vertical;}
#${formId} .optin-checkbox{display:flex;align-items:center;gap:8px;font-weight:500;}
#${formId} .optin-checkbox input{width:auto;}
#${formId} button[type="submit"]{padding:10px 16px;border-radius:8px;font-weight:700;cursor:pointer;line-height:1.2;font:inherit;max-width:100%;}
@media (max-width:700px){#${formId} .optin-grid{grid-template-columns:1fr;}}`;
                    const embedJs = `(function(){
  // Optional JS hook for custom tracking/analytics on submit.
  // const form = document.getElementById('${formId}');
  // form?.addEventListener('submit', function(){ console.log('optin submit'); });
})();`;
                    if (embedHtmlCode) embedHtmlCode.textContent = embedHtml;
                    if (embedCssCode) embedCssCode.textContent = embedCss;
                    if (embedJsCode) embedJsCode.textContent = embedJs;
                }
            };

            const render = () => {
                if (!canvas) return;
                canvas.dataset.columns = String(normalizeColumns(canvasColumns));
                if (selectedIndex >= fields.length) selectedIndex = Math.max(0, fields.length - 1);
                const submitSpanClass = (canvasColumns === 2 && submitConfig.width === 'half') ? '' : 'is-full';
                const submitCanvasStyle = submitRowInlineStyle(canvasColumns, submitConfig);
                const entriesMarkup = buildEntries().map((entry) => {
                    if (entry.kind === 'submit') {
                        return `<article class="optin-canvas-submit ${submitSpanClass} ${selectedSubmit ? 'selected' : ''}" style="${submitCanvasStyle}" data-role="select-submit" data-drag-role="submit-drag" draggable="true">
                        <button type="button" style="${buttonInlineStyle(submitConfig)}">${esc((submitConfig.label || 'Ma abonez'))}</button>
                        <small>Buton submit (editabil din dreapta)</small>
                    </article>`;
                    }
                    const field = entry.field || {};
                    const index = Number(entry.fieldIndex ?? -1);
                    const fieldCardOffset = normalizeOffset(field?.offset_y);
                    const fieldCardStyle = fieldCardOffset !== 0 ? ` style="margin-top:${fieldCardOffset}px;"` : '';
                    return `<article class="optin-canvas-field ${!selectedSubmit && index === selectedIndex ? 'selected' : ''} ${field.width === 'full' || canvasColumns === 1 ? 'is-full' : ''} ${index === movedIndex ? 'is-entering' : ''}"${fieldCardStyle} data-role="select" data-index="${index}" data-drag-role="field-drag" draggable="true">
                            <div class="optin-canvas-tools">
                                <span>${fieldTitle(field)}</span>
                                <button type="button" data-role="up" data-index="${index}" ${index === 0 ? 'disabled' : ''}>↑</button>
                                <button type="button" data-role="down" data-index="${index}" ${index === fields.length - 1 ? 'disabled' : ''}>↓</button>
                                <button type="button" data-role="remove" data-index="${index}" ${String(field.name || '') === 'email' ? 'disabled title="Câmpul email este obligatoriu"' : ''}>🗑</button>
                            </div>
                            <div class="optin-canvas-inner">
                                ${fieldCanvasInner(field)}
                            </div>
                        </article>`;
                });
                canvas.innerHTML = entriesMarkup.join('');
                renderProps();
                sync();
                if (movedIndex >= 0) {
                    window.setTimeout(() => {
                        movedIndex = -1;
                        const entered = canvas.querySelector('.optin-canvas-field.is-entering');
                        entered?.classList.remove('is-entering');
                    }, 220);
                }
            };

            root.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;
                const roleNode = target.closest('[data-role]');
                if (!(roleNode instanceof HTMLElement)) return;
                const role = roleNode.getAttribute('data-role') || '';
                const index = Number.parseInt(roleNode.getAttribute('data-index') || '-1', 10);

                if (role === 'add') {
                    const type = roleNode.getAttribute('data-type') || 'text';
                    fields.push(defaultFieldByType(type, canvasColumns));
                    selectedIndex = fields.length - 1;
                    selectedSubmit = false;
                    movedIndex = selectedIndex;
                    render();
                    return;
                }
                if (role === 'select-submit') {
                    selectedSubmit = true;
                    render();
                    return;
                }
                if (role === 'select' && Number.isInteger(index) && index >= 0 && index < fields.length) {
                    selectedIndex = index;
                    selectedSubmit = false;
                    render();
                    return;
                }
                if (!Number.isInteger(index) || index < 0 || index >= fields.length) return;
                if (role === 'remove') {
                    deleteFieldAt(index);
                    return;
                }
                if (role === 'up' && index > 0) {
                    const tmp = fields[index - 1];
                    fields[index - 1] = fields[index];
                    fields[index] = tmp;
                    selectedIndex = index - 1;
                    selectedSubmit = false;
                    movedIndex = selectedIndex;
                    render();
                    return;
                }
                if (role === 'down' && index < fields.length - 1) {
                    const tmp = fields[index + 1];
                    fields[index + 1] = fields[index];
                    fields[index] = tmp;
                    selectedIndex = index + 1;
                    selectedSubmit = false;
                    movedIndex = selectedIndex;
                    render();
                }
            });

            root.addEventListener('dragstart', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;
                const tool = target.closest('[data-drag-role="tool-drag"]');
                const submit = target.closest('[data-drag-role="submit-drag"]');
                const fieldNode = target.closest('[data-drag-role="field-drag"]');
                if (tool instanceof HTMLElement) {
                    dragSource = 'tool';
                    dragToolType = tool.getAttribute('data-type') || 'text';
                    dragIndex = -1;
                } else if (submit instanceof HTMLElement) {
                    dragSource = 'submit';
                    dragToolType = '';
                    dragIndex = -1;
                } else if (fieldNode instanceof HTMLElement) {
                    const index = Number.parseInt(fieldNode.getAttribute('data-index') || '-1', 10);
                    if (!Number.isInteger(index) || index < 0 || index >= fields.length) {
                        event.preventDefault();
                        return;
                    }
                    dragSource = 'field';
                    dragToolType = '';
                    dragIndex = index;
                } else {
                    return;
                }
                dropTargetKind = 'none';
                dropTargetIndex = -1;
                dropPosition = 'after';
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', dragSource);
                canvas?.classList.add('is-dragging');
                if (dragSource === 'field' && dragIndex >= 0) {
                    const draggingNode = canvas?.querySelector(`.optin-canvas-field[data-index="${dragIndex}"]`);
                    draggingNode?.classList.add('is-dragging');
                } else if (dragSource === 'submit') {
                    const submitNode = canvas?.querySelector('.optin-canvas-submit');
                    submitNode?.classList.add('is-dragging');
                } else if (dragSource === 'tool' && tool instanceof HTMLElement) {
                    tool.classList.add('is-dragging');
                }
            });

            canvas?.addEventListener('dragover', (event) => {
                if (dragSource === '') return;
                event.preventDefault();
                clearDropDecor();
                canvas.classList.add('is-dragging');

                const item = event.target instanceof HTMLElement
                    ? event.target.closest('.optin-canvas-field, .optin-canvas-submit')
                    : null;
                if (!(item instanceof HTMLElement)) {
                    const fallbackTarget = dropTargetFromPointer(event);
                    dropTargetKind = fallbackTarget.kind;
                    dropTargetIndex = fallbackTarget.index;
                    dropPosition = fallbackTarget.position;
                    placeDropSlot(dropTargetKind, dropTargetIndex, dropPosition);
                    return;
                }
                if (item.classList.contains('optin-canvas-submit')) {
                    dropTargetKind = 'submit';
                    dropTargetIndex = -1;
                    dropPosition = 'before';
                    item.classList.add('drop-before');
                    placeDropSlot(dropTargetKind, dropTargetIndex, dropPosition);
                    return;
                }
                const index = Number.parseInt(item.getAttribute('data-index') || '-1', 10);
                if (!Number.isInteger(index) || index < 0) return;
                dropTargetKind = 'field';
                dropTargetIndex = index;
                const hoveredField = fields[index] || null;
                const rect = item.getBoundingClientRect();
                const canUseColumnSlots = canvasColumns === 2
                    && String(draggedFieldSpanClass()) === 'is-half'
                    && String(hoveredField?.width || 'full') === 'half';
                if (canUseColumnSlots) {
                    const placeLeft = event.clientX < rect.left + rect.width / 2;
                    dropPosition = placeLeft ? 'before' : 'after';
                    item.classList.add(placeLeft ? 'drop-slot-left' : 'drop-slot-right');
                    placeDropSlot(dropTargetKind, dropTargetIndex, dropPosition);
                    return;
                }
                const before = event.clientY < rect.top + rect.height / 2;
                dropPosition = before ? 'before' : 'after';
                item.classList.add(before ? 'drop-before' : 'drop-after');
                placeDropSlot(dropTargetKind, dropTargetIndex, dropPosition);
            });

            canvas?.addEventListener('drop', (event) => {
                if (dragSource === '') return;
                event.preventDefault();
                if (dragSource === 'field') {
                    const movedTo = moveField(dragIndex, dropTargetKind, dropTargetIndex, dropPosition);
                    if (movedTo >= 0) {
                        selectedIndex = movedTo;
                        selectedSubmit = false;
                        movedIndex = movedTo;
                        render();
                    }
                } else if (dragSource === 'submit') {
                    moveSubmit(dropTargetKind, dropTargetIndex, dropPosition);
                    selectedSubmit = true;
                    render();
                } else if (dragSource === 'tool') {
                    const insertedAt = insertToolAtTarget(dragToolType, dropTargetKind, dropTargetIndex, dropPosition);
                    selectedIndex = insertedAt;
                    selectedSubmit = false;
                    movedIndex = insertedAt;
                    render();
                }
                dragSource = '';
                dragToolType = '';
                dragIndex = -1;
                dropTargetKind = 'none';
                dropTargetIndex = -1;
                dropPosition = 'after';
                clearDropDecor();
            });

            root.addEventListener('dragend', () => {
                dragSource = '';
                dragToolType = '';
                dragIndex = -1;
                dropTargetKind = 'none';
                dropTargetIndex = -1;
                dropPosition = 'after';
                clearDropDecor();
            });

            deleteSelectedBtn?.addEventListener('click', () => {
                if (selectedSubmit) return;
                deleteFieldAt(selectedIndex);
            });

            propsBox?.addEventListener('input', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) return;
                const prop = target.getAttribute('data-prop') || '';
                if (prop === '') return;
                const isColorInput = target instanceof HTMLInputElement && target.type === 'color';

                const caretStart = target instanceof HTMLInputElement ? (target.selectionStart ?? null) : null;
                const caretEnd = target instanceof HTMLInputElement ? (target.selectionEnd ?? null) : null;

                if (prop === 'canvas_columns') {
                    canvasColumns = normalizeColumns(target.value);
                } else if (selectedSubmit) {
                    const currentSubmitColors = submitColors(submitConfig);
                    if (prop === 'submit_label') {
                        submitConfig.label = String(target.value || '');
                        if (buttonInput instanceof HTMLInputElement) {
                            buttonInput.value = submitConfig.label;
                        }
                    } else if (prop === 'submit_style') {
                        submitConfig.style = target.value === 'outline' ? 'outline' : 'primary';
                        const defaultSubmitColors = submitConfig.style === 'outline'
                            ? { bg: '#ffffff', text: '#0f8f7a', border: '#0f8f7a' }
                            : { bg: '#0f8f7a', text: '#ffffff', border: '#0f8f7a' };
                        submitConfig.bg_color = defaultSubmitColors.bg;
                        submitConfig.text_color = defaultSubmitColors.text;
                        submitConfig.border_color = defaultSubmitColors.border;
                    } else if (prop === 'submit_align') {
                        submitConfig.align = ['left', 'center', 'right'].includes(target.value) ? target.value : 'left';
                    } else if (prop === 'submit_width') {
                        submitConfig.width = target.value === 'half' ? 'half' : 'full';
                    } else if (prop === 'submit_offset_y') {
                        submitConfig.offset_y = normalizeOffset(target.value);
                        target.value = String(submitConfig.offset_y);
                    } else if (prop === 'submit_bg_color') {
                        submitConfig.bg_color = normalizeColor(target.value, currentSubmitColors.bg);
                    } else if (prop === 'submit_text_color') {
                        submitConfig.text_color = normalizeColor(target.value, currentSubmitColors.text);
                    } else if (prop === 'submit_border_color') {
                        submitConfig.border_color = normalizeColor(target.value, currentSubmitColors.border);
                    }
                } else {
                    if (!fields[selectedIndex]) return;
                    const currentFieldColors = fieldColors(fields[selectedIndex]);
                    if (prop === 'required') {
                        fields[selectedIndex].required = target instanceof HTMLInputElement && target.checked ? 1 : 0;
                    } else if (prop === 'name') {
                        fields[selectedIndex].name = slugify(target.value || '');
                        target.value = fields[selectedIndex].name;
                    } else if (prop === 'offset_y') {
                        fields[selectedIndex].offset_y = normalizeOffset(target.value);
                        target.value = String(fields[selectedIndex].offset_y);
                    } else if (prop === 'label_color') {
                        fields[selectedIndex].label_color = normalizeColor(target.value, currentFieldColors.label);
                    } else if (prop === 'input_text_color') {
                        fields[selectedIndex].input_text_color = normalizeColor(target.value, currentFieldColors.inputText);
                    } else if (prop === 'input_bg_color') {
                        fields[selectedIndex].input_bg_color = normalizeColor(target.value, currentFieldColors.inputBg);
                    } else if (prop === 'input_border_color') {
                        fields[selectedIndex].input_border_color = normalizeColor(target.value, currentFieldColors.inputBorder);
                    } else {
                        fields[selectedIndex][prop] = target.value;
                    }
                    if (String(fields[selectedIndex]?.name || '') === 'email') {
                        fields[selectedIndex].type = 'email';
                        fields[selectedIndex].required = 1;
                    }
                }
                if (isColorInput) {
                    // Keep native color picker open while selecting shades.
                    sync();
                    return;
                }
                render();

                const focusBack = propsBox.querySelector(`[data-prop="${prop}"]`);
                if (focusBack instanceof HTMLInputElement || focusBack instanceof HTMLSelectElement) {
                    focusBack.focus();
                    if (focusBack instanceof HTMLInputElement && caretStart !== null && caretEnd !== null) {
                        focusBack.setSelectionRange(caretStart, caretEnd);
                    }
                }
            });
            propsBox?.addEventListener('change', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLInputElement)) return;
                if (target.type !== 'color') return;
                // Apply final color selection after picker closes.
                render();
            });

            const tracked = [nameInput, slugInput, listInput, buttonInput, successInput, activeInput].filter(Boolean);
            tracked.forEach((input) => {
                input.addEventListener('input', () => {
                    if (input === nameInput && slugInput && (slugInput.value || '').trim() === '') {
                        slugInput.value = slugify(nameInput?.value || '');
                    }
                    if (input === slugInput && slugInput) {
                        slugInput.value = slugify(slugInput.value || '');
                    }
                    if (input === buttonInput) {
                        submitConfig.label = (buttonInput?.value || '').trim() || 'Ma abonez';
                    }
                    sync();
                    if (selectedSubmit && input === buttonInput) {
                        render();
                    }
                });
                input.addEventListener('change', () => sync());
            });

            previewBtn?.addEventListener('click', () => {
                sync();
                if (!optinPreviewModal || !optinPreviewFrame) return;
                setOptinPreviewDevice('desktop');
                optinPreviewFrame.srcdoc = buildPreviewHtml();
                openModal('optin-preview-modal');
            });

            const computeState = () => JSON.stringify({
                fields: fieldsInput.value || '',
                name: (nameInput?.value || '').trim(),
                slug: (slugInput?.value || '').trim(),
                list: listInput?.value || '',
                button: (buttonInput?.value || '').trim(),
                success: (successInput?.value || '').trim(),
                active: activeInput?.checked ? '1' : '0',
            });
            let initialState = '';
            const markSaved = () => { initialState = computeState(); };
            const hasUnsavedChanges = () => computeState() !== initialState;

            form.addEventListener('submit', () => {
                sync();
                markSaved();
            });

            render();
            markSaved();

            return { hasUnsavedChanges };
        };

        const copyTextToClipboard = async (text) => {
            const value = String(text || '');
            if (value === '') return false;
            try {
                if (navigator?.clipboard?.writeText) {
                    await navigator.clipboard.writeText(value);
                    return true;
                }
            } catch (error) {
                // Fallback below.
            }
            const ta = document.createElement('textarea');
            ta.value = value;
            ta.setAttribute('readonly', 'readonly');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        };
        document.querySelectorAll('[data-copy-target]').forEach((button) => {
            button.addEventListener('click', async () => {
                const targetId = button.getAttribute('data-copy-target') || '';
                const targetNode = targetId !== '' ? document.getElementById(targetId) : null;
                if (!(targetNode instanceof HTMLElement)) return;
                const text = targetNode.textContent || '';
                const original = button.textContent || 'Copy';
                const ok = await copyTextToClipboard(text);
                button.textContent = ok ? 'Copiat!' : 'Copiere eșuată';
                button.disabled = true;
                window.setTimeout(() => {
                    button.textContent = original;
                    button.disabled = false;
                }, 1200);
            });
        });
        const optinEmbedTabs = document.querySelectorAll('.optin-embed-tab');
        const optinEmbedPanes = document.querySelectorAll('.optin-embed-pane');
        const setOptinEmbedPane = (type) => {
            optinEmbedTabs.forEach((tab) => {
                const active = (tab.getAttribute('data-embed-type') || 'html') === type;
                tab.classList.toggle('active', active);
            });
            optinEmbedPanes.forEach((pane) => {
                const visible = (pane.getAttribute('data-embed-pane') || 'html') === type;
                pane.classList.toggle('is-hidden', !visible);
            });
        };
        optinEmbedTabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                setOptinEmbedPane(tab.getAttribute('data-embed-type') || 'html');
            });
        });
        setOptinEmbedPane('html');

        const optInBuilderApi = makeOptInBuilder();
        document.getElementById('optin-builder-back-btn')?.addEventListener('click', (event) => {
            const target = event.currentTarget;
            if (!(target instanceof HTMLElement)) return;
            if (optInBuilderApi && optInBuilderApi.hasUnsavedChanges() && !confirm('Ai modificări nesalvate. Sigur vrei să te întorci?')) {
                return;
            }
            window.location.href = target.getAttribute('data-back-url') || '/admin/emails/newsletters?tab=optin';
        });
    })();
    </script>
<?php endif; ?>
