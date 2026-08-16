<?php
$section = (string) ($section ?? 'config');
$galleryImages = is_array($galleryImages ?? null) ? $galleryImages : [];
?>

<section class="panel">
    <div class="section-head" style="margin-bottom:10px;">
        <div>
            <h1>Setări magazin</h1>
        </div>
    </div>

    <?php if ($section === 'config'): ?>
        <?php
        $settings = is_array($settings ?? null) ? $settings : [];
        $quantityStyle = (string) ($settings['store_quantity_control_style'] ?? 'default');
        if (!in_array($quantityStyle, ['default', 'stepper'], true)) {
            $quantityStyle = 'default';
        }
        $applyProductTemplate = (string) ($settings['store_quantity_apply_product_template'] ?? '0') === '1';
        $applyFloatingCart = (string) ($settings['store_quantity_apply_floating_cart'] ?? '0') === '1';
        $applyCartPage = (string) ($settings['store_quantity_apply_cart_page'] ?? '0') === '1';
        $bbdSidebarEnabled = (string) ($settings['store_bbd_sidebar_enabled'] ?? '1') !== '0';
        $orderNumberMode = trim((string) ($settings['order_number_mode'] ?? \App\Support\OrderNumber::MOD_SECVENTIAL));
        if (!in_array($orderNumberMode, [\App\Support\OrderNumber::MOD_SECVENTIAL, \App\Support\OrderNumber::MOD_VECHI], true)) {
            $orderNumberMode = \App\Support\OrderNumber::MOD_SECVENTIAL;
        }
        $orderNumberNext = (int) ($orderNumberNext ?? \App\Support\OrderNumber::normalizeazaStart($settings['order_number_next'] ?? null));
        $vatSumar = is_array($vatSumar ?? null) ? $vatSumar : [];
        $vatImplicit = trim((string) ($settings['default_vat_percent'] ?? '19'));
        // Cota cea mai folosită e cea de la care va vrea sa plece, cel mai probabil.
        $vatCotaDominanta = $vatImplicit;
        $vatMaxProduse = -1;
        foreach ($vatSumar as $vatRand) {
            if ((int) ($vatRand['total'] ?? 0) > $vatMaxProduse) {
                $vatMaxProduse = (int) ($vatRand['total'] ?? 0);
                $vatCotaDominanta = rtrim(rtrim(number_format((float) ($vatRand['cota'] ?? 0), 2, '.', ''), '0'), '.');
            }
        }
        $availableStoreTabs = ['pages', 'sitemap', 'branding', 'seo', 'clarity', 'quantity', 'widgets', 'caching', 'maintenance', 'numerotare', 'tva', 'popup'];
        $popupEnabled = (string) ($settings['welcome_popup_enabled'] ?? '0') === '1';
        $popupTitle = (string) ($settings['welcome_popup_title'] ?? '');
        $popupBody = (string) ($settings['welcome_popup_body'] ?? '');
        $defaultStoreTab = trim((string) ($_GET['tab'] ?? 'pages'));
        if (!in_array($defaultStoreTab, $availableStoreTabs, true)) {
            $defaultStoreTab = 'pages';
        }
        $cachePagesEnabled = (string) ($settings['cache_pages_enabled'] ?? '0') === '1';
        $cacheRules = \App\Support\ResponseCache::parseRules((string) ($settings['cache_pages_rules_json'] ?? '[]'));
        $cacheCustomExclusions = trim((string) ($settings['cache_exclusions_custom'] ?? ''));
        $cacheAssetsEnabled = (string) ($settings['cache_assets_enabled'] ?? '0') === '1';
        $cacheAssetsTtl = \App\Support\ResponseCache::normalizeTtlSeconds($settings['cache_assets_ttl_seconds'] ?? 86400, 86400);
        $cacheUploadsTtl = \App\Support\ResponseCache::normalizeTtlSeconds($settings['cache_uploads_ttl_seconds'] ?? 604800, 604800);
        $cacheAssetsVersioningMode = \App\Support\ResponseCache::normalizeAssetVersioningMode((string) ($settings['cache_assets_versioning_mode'] ?? 'none'));
        $cacheAssetsEtagEnabled = (string) ($settings['cache_assets_etag_enabled'] ?? '1') === '1';
        $cacheAssetsVersionToken = trim((string) ($settings['cache_assets_version_token'] ?? ''));
        $cacheStats = is_array($cacheStats ?? null) ? $cacheStats : ['entries' => 0, 'fresh' => 0, 'expired' => 0];
        ?>
        <style>
            .store-settings-tabs {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin: 6px 0 12px;
            }
            .store-settings-tabs .btn.is-active {
                background: #0f766e;
                border-color: #0f766e;
                color: #ffffff;
            }
            .store-settings-panel[hidden] {
                display: none !important;
            }
        </style>

        <div class="store-settings-tabs" data-store-settings-tabs data-default-tab="<?= htmlspecialchars($defaultStoreTab, ENT_QUOTES) ?>">
            <button class="btn btn-secondary <?= $defaultStoreTab === 'pages' ? 'is-active' : '' ?>" type="button" data-store-settings-tab="pages">Pagini magazin</button>
            <button class="btn btn-secondary <?= $defaultStoreTab === 'sitemap' ? 'is-active' : '' ?>" type="button" data-store-settings-tab="sitemap">Sitemap XML</button>
            <button class="btn btn-secondary <?= $defaultStoreTab === 'branding' ? 'is-active' : '' ?>" type="button" data-store-settings-tab="branding">Favicon</button>
            <button class="btn btn-secondary <?= $defaultStoreTab === 'seo' ? 'is-active' : '' ?>" type="button" data-store-settings-tab="seo">SEO Google</button>
            <button class="btn btn-secondary <?= $defaultStoreTab === 'clarity' ? 'is-active' : '' ?>" type="button" data-store-settings-tab="clarity">Microsoft Clarity</button>
            <button class="btn btn-secondary <?= $defaultStoreTab === 'maintenance' ? 'is-active' : '' ?>" type="button" data-store-settings-tab="maintenance">Mentenanță</button>
            <button class="btn btn-secondary <?= $defaultStoreTab === 'numerotare' ? 'is-active' : '' ?>" type="button" data-store-settings-tab="numerotare">Numerotare comenzi</button>
            <button class="btn btn-secondary <?= $defaultStoreTab === 'tva' ? 'is-active' : '' ?>" type="button" data-store-settings-tab="tva">TVA</button>
            <button class="btn btn-secondary <?= $defaultStoreTab === 'quantity' ? 'is-active' : '' ?>" type="button" data-store-settings-tab="quantity">Control cantitate</button>
            <button class="btn btn-secondary <?= $defaultStoreTab === 'widgets' ? 'is-active' : '' ?>" type="button" data-store-settings-tab="widgets">Sertar Oferte</button>
            <button class="btn btn-secondary <?= $defaultStoreTab === 'caching' ? 'is-active' : '' ?>" type="button" data-store-settings-tab="caching">Caching</button>
            <button class="btn btn-secondary <?= $defaultStoreTab === 'popup' ? 'is-active' : '' ?>" type="button" data-store-settings-tab="popup">Popup anunț</button>
        </div>

        <article class="panel store-settings-panel" data-store-settings-panel="pages" <?= $defaultStoreTab !== 'pages' ? 'hidden' : '' ?> style="margin:12px 0;">
            <h3 style="margin-top:0;">Pagini magazin</h3>
            <p style="margin:0 0 10px;color:#64748b;">
                Verifică și creează automat paginile necesare pentru fluxul de cumpărare:
                Acasă, Magazin, Coș, Checkout, Thank you (checkout/succes), Blog, Contact, 404.
            </p>
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="generate_pages">
                <button class="btn" type="submit">Generează pagini necesare</button>
            </form>
        </article>

        <article class="panel store-settings-panel" data-store-settings-panel="sitemap" <?= $defaultStoreTab !== 'sitemap' ? 'hidden' : '' ?> style="margin:12px 0;">
            <?php $sitemapUrl = (string) ($sitemapUrl ?? '/sitemap.xml'); ?>
            <?php $sitemapFilename = trim((string) ($sitemapFilename ?? 'sitemap.xml')); ?>
            <h3 style="margin-top:0;">Sitemap XML</h3>
            <p style="margin:0 0 10px;color:#64748b;">
                Generează sitemap-ul pentru indexare în Google Search Console.
            </p>
            <p style="margin:0 0 10px;">
                URL sitemap: <a href="<?= htmlspecialchars($sitemapUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($sitemapUrl, ENT_QUOTES) ?></a>
            </p>
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="generate_sitemap">
                <div class="field" style="max-width:360px;margin-bottom:10px;">
                    <label for="sitemap_filename">Nume fișier sitemap</label>
                    <input id="sitemap_filename" type="text" name="sitemap_filename" value="<?= htmlspecialchars($sitemapFilename, ENT_QUOTES) ?>" placeholder="sitemap.xml">
                    <small style="color:#64748b;">Exemplu: <code>sitemap-nou.xml</code></small>
                </div>
                <button class="btn" type="submit">Generează sitemap la URL nou</button>
            </form>
        </article>

        <article class="panel store-settings-panel" data-store-settings-panel="branding" <?= $defaultStoreTab !== 'branding' ? 'hidden' : '' ?> style="margin:12px 0;">
            <?php
            $faviconUrl = trim((string) ($settings['store_favicon_url'] ?? ''));
            $faviconPreview = $faviconUrl !== '' ? $faviconUrl : '/assets/img/product-placeholder.svg';
            ?>
            <h3 style="margin-top:0;">Favicon site</h3>
            <p style="margin:0 0 10px;color:#64748b;">
                Selectează o imagine din Galerie pentru favicon-ul site-ului.
            </p>
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_favicon">
                <div class="field" style="grid-column:1/-1;">
                    <input type="hidden" name="store_favicon_url" id="store_favicon_url" value="<?= htmlspecialchars($faviconUrl, ENT_QUOTES) ?>">
                    <div class="product-image-picker-inline">
                        <img id="store_favicon_preview" src="<?= htmlspecialchars($faviconPreview, ENT_QUOTES) ?>" alt="Preview favicon" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                        <div>
                            <button class="btn btn-secondary" type="button" id="open-store-favicon-picker">Selectează imagine</button>
                            <button class="btn btn-secondary" type="button" id="clear-store-favicon-picker" style="margin-left:6px;">Resetează</button>
                            <p id="store_favicon_label" class="muted" style="margin:8px 0 0;font-size:12px;"><?= htmlspecialchars($faviconPreview, ENT_QUOTES) ?></p>
                        </div>
                    </div>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează favicon</button>
                </div>
            </form>
        </article>

        <article class="panel store-settings-panel" data-store-settings-panel="seo" <?= $defaultStoreTab !== 'seo' ? 'hidden' : '' ?> style="margin:12px 0;">
            <?php
            $seoHomeTitle = trim((string) ($settings['store_seo_home_title'] ?? ''));
            $seoHomeDescription = trim((string) ($settings['store_seo_home_description'] ?? ''));
            $seoDescription = trim((string) ($settings['store_seo_default_description'] ?? ''));
            $seoImageUrl = trim((string) ($settings['store_seo_default_image_url'] ?? ''));
            $seoImagePreview = $seoImageUrl !== '' ? $seoImageUrl : '/assets/img/product-placeholder.svg';
            ?>
            <h3 style="margin-top:0;">SEO Google (global)</h3>
            <p style="margin:0 0 10px;color:#64748b;">
                Aceste valori sunt folosite ca fallback pentru paginile care nu au SEO specific.
            </p>
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_seo_defaults">
                <div class="field" style="max-width:680px;">
                    <label for="seo_home_title">Titlu SEO pentru Homepage</label>
                    <input id="seo_home_title" type="text" name="seo_home_title" value="<?= htmlspecialchars($seoHomeTitle, ENT_QUOTES) ?>" maxlength="120" placeholder="Ex: Bioscem - Produse naturale pentru sănătate">
                </div>
                <div class="field" style="max-width:680px;">
                    <label for="seo_home_description">Descriere SEO pentru Homepage</label>
                    <textarea id="seo_home_description" name="seo_home_description" rows="3" maxlength="320" placeholder="Descriere care apare în Google pentru pagina principală..."><?= htmlspecialchars($seoHomeDescription, ENT_QUOTES) ?></textarea>
                </div>
                <div class="field" style="max-width:680px;">
                    <label for="seo_default_description">Descriere SEO fallback (alte pagini)</label>
                    <textarea id="seo_default_description" name="seo_default_description" rows="3" maxlength="320" placeholder="Descriere fallback pentru paginile fără descriere specifică..."><?= htmlspecialchars($seoDescription, ENT_QUOTES) ?></textarea>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Imagine preview (Open Graph / social)</label>
                    <input type="hidden" name="seo_default_image_url" id="seo_default_image_url" value="<?= htmlspecialchars($seoImageUrl, ENT_QUOTES) ?>">
                    <div class="product-image-picker-inline">
                        <img id="seo_default_image_preview" src="<?= htmlspecialchars($seoImagePreview, ENT_QUOTES) ?>" alt="Preview SEO image" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                        <div>
                            <button class="btn btn-secondary" type="button" id="open-seo-image-picker">Selectează imagine</button>
                            <button class="btn btn-secondary" type="button" id="clear-seo-image-picker" style="margin-left:6px;">Resetează</button>
                            <p id="seo_default_image_label" class="muted" style="margin:8px 0 0;font-size:12px;"><?= htmlspecialchars($seoImagePreview, ENT_QUOTES) ?></p>
                        </div>
                    </div>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează SEO global</button>
                </div>
            </form>
        </article>

        <article class="panel store-settings-panel" data-store-settings-panel="clarity" <?= $defaultStoreTab !== 'clarity' ? 'hidden' : '' ?> style="margin:12px 0;">
            <?php
            $clarityEnabled = (string) ($settings['microsoft_clarity_enabled'] ?? '0') === '1';
            $clarityProjectId = trim((string) ($settings['microsoft_clarity_project_id'] ?? ''));
            ?>
            <h3 style="margin-top:0;">Microsoft Clarity</h3>
            <p style="margin:0 0 10px;color:#64748b;">
                Activează codul Clarity pentru heatmaps, sesiuni înregistrate și click tracking.
            </p>
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_clarity_settings">
                <div style="display:grid;gap:8px;max-width:760px;">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="microsoft_clarity_enabled" value="1" <?= $clarityEnabled ? 'checked' : '' ?>>
                        Activează Microsoft Clarity pe site
                    </label>
                    <div class="field" style="max-width:360px;">
                        <label for="microsoft_clarity_project_id">Project ID Clarity</label>
                        <input id="microsoft_clarity_project_id" type="text" name="microsoft_clarity_project_id" value="<?= htmlspecialchars($clarityProjectId, ENT_QUOTES) ?>" placeholder="ex: abc123xyz">
                        <small style="color:#64748b;">Doar litere și cifre. Se găsește în scriptul furnizat de Clarity.</small>
                    </div>
                    <div>
                        <button class="btn" type="submit">Salvează setările Clarity</button>
                    </div>
                </div>
            </form>
            <div class="panel" style="margin:12px 0 0;background:#f8fafc;border-color:#dbe4ef;">
                <h4 style="margin:0 0 8px;">Setup rapid (2 pași)</h4>
                <ol style="margin:0;padding-left:18px;display:grid;gap:6px;color:#334155;">
                    <li>Intră în <strong>clarity.microsoft.com</strong>, creează/alege proiectul site-ului tău.</li>
                    <li>Copiază <strong>Project ID</strong> din scriptul lor, pune-l aici, bifează activarea și salvează.</li>
                </ol>
                <p style="margin:10px 0 0;color:#64748b;font-size:13px;">
                    După salvare, Clarity începe să colecteze date automat (de obicei apar în câteva minute).
                </p>
            </div>
        </article>

        <article class="panel store-settings-panel" data-store-settings-panel="maintenance" <?= $defaultStoreTab !== 'maintenance' ? 'hidden' : '' ?> style="margin:12px 0;">
            <?php
            $maintenanceEnabled = (string) ($settings['maintenance_enabled'] ?? '0') === '1';
            $maintenanceKey = trim((string) ($settings['maintenance_key'] ?? ''));
            $maintenanceLink = \App\Support\Maintenance::linkPreview((string) ($appUrl ?? ''), $maintenanceKey);
            ?>
            <h3 style="margin-top:0;">Mod mentenanță</h3>
            <p style="margin:0 0 10px;color:#64748b;">
                Cât timp e activ, vizitatorii văd pagina „Revenim în curând", iar tu poți testa
                site-ul normal. Administrarea, plățile și legătura cu ERP-ul rămân funcționale.
            </p>

            <?php if ($maintenanceEnabled): ?>
                <div class="panel" style="margin:0 0 12px;background:#fffbeb;border-color:#fde68a;color:#92400e;">
                    <strong>Site-ul este în mentenanță acum.</strong>
                    Vizitatorii nu pot cumpăra. Dezactivează bifa de mai jos când ești gata de lansare.
                </div>
            <?php endif; ?>

            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_maintenance">
                <div style="display:grid;gap:10px;max-width:760px;">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="maintenance_enabled" value="1" <?= $maintenanceEnabled ? 'checked' : '' ?>>
                        <strong>Activează modul mentenanță</strong>
                    </label>

                    <div class="field">
                        <label for="maintenance_title">Titlu</label>
                        <input id="maintenance_title" type="text" name="maintenance_title" maxlength="120"
                               value="<?= htmlspecialchars((string) ($settings['maintenance_title'] ?? ''), ENT_QUOTES) ?>"
                               placeholder="Revenim în curând">
                    </div>

                    <div class="field">
                        <label for="maintenance_message">Mesaj</label>
                        <textarea id="maintenance_message" name="maintenance_message" rows="3"
                                  placeholder="Lucrăm la magazin chiar acum."><?= htmlspecialchars((string) ($settings['maintenance_message'] ?? ''), ENT_QUOTES) ?></textarea>
                        <small style="color:#64748b;">Se afișează sub titlu. Telefonul și emailul din setările de contact apar automat.</small>
                    </div>

                    <div class="field">
                        <label for="maintenance_allowed_ips">IP-uri cu acces (opțional)</label>
                        <input id="maintenance_allowed_ips" type="text" name="maintenance_allowed_ips"
                               value="<?= htmlspecialchars((string) ($settings['maintenance_allowed_ips'] ?? ''), ENT_QUOTES) ?>"
                               placeholder="ex: 86.120.10.5, 79.117.4.20">
                        <small style="color:#64748b;">
                            Separate prin virgulă. IP-ul tău curent este
                            <strong><?= htmlspecialchars((string) ($_SERVER['REMOTE_ADDR'] ?? '?'), ENT_QUOTES) ?></strong>.
                        </small>
                    </div>

                    <div>
                        <button class="btn" type="submit">Salvează setările de mentenanță</button>
                    </div>
                </div>
            </form>

            <div class="panel" style="margin:12px 0 0;background:#f8fafc;border-color:#dbe4ef;">
                <h4 style="margin:0 0 8px;">Cum testezi în timpul mentenanței</h4>
                <ol style="margin:0 0 10px;padding-left:18px;display:grid;gap:6px;color:#334155;">
                    <li>Ești logat în administrare → vezi site-ul normal, fără să faci nimic.</li>
                    <li>Pentru client sau colegi, trimite linkul de previzualizare de mai jos. La prima deschidere lasă un cookie valabil 30 de zile, deci linkul se dă o singură dată.</li>
                </ol>
                <?php if ($maintenanceLink !== ''): ?>
                    <div class="field" style="margin:0;">
                        <label for="maintenance_link">Link de previzualizare</label>
                        <input id="maintenance_link" type="text" readonly onfocus="this.select()"
                               value="<?= htmlspecialchars($maintenanceLink, ENT_QUOTES) ?>">
                    </div>
                <?php else: ?>
                    <p style="margin:0;color:#64748b;">Cheia de previzualizare se generează la prima salvare.</p>
                <?php endif; ?>
                <form method="post" action="/admin/settings/store" style="margin-top:10px;"
                      onsubmit="return confirm('Se generează o cheie nouă. Linkurile trimise până acum nu vor mai funcționa. Continui?');">
                    <input type="hidden" name="action" value="regenerate_maintenance_key">
                    <button class="btn btn-secondary" type="submit">Generează o cheie nouă</button>
                </form>
            </div>
        </article>

        <article class="panel store-settings-panel" data-store-settings-panel="tva" <?= $defaultStoreTab !== 'tva' ? 'hidden' : '' ?> style="margin:12px 0;">
            <h3 style="margin-top:0;">Cota de TVA</h3>
            <?php if ($vatSumar !== []): ?>
                <p style="margin:0 0 10px;color:#64748b;">
                    Acum în catalog:
                    <?php foreach ($vatSumar as $vatRand): ?>
                        <strong><?= htmlspecialchars(rtrim(rtrim(number_format((float) ($vatRand['cota'] ?? 0), 2, '.', ''), '0'), '.'), ENT_QUOTES) ?>%</strong>
                        — <?= (int) ($vatRand['total'] ?? 0) ?> produse&nbsp;&nbsp;
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <form method="post" action="/admin/settings/store" onsubmit="return confirm('Sigur schimbi cota de TVA pe toate produsele care o au pe cea veche? Operația nu se poate anula din interfață.');">
                <input type="hidden" name="action" value="save_vat_bulk">
                <div style="display:flex;gap:12px;flex-wrap:wrap;max-width:520px;">
                    <div class="field" style="flex:1;min-width:150px;">
                        <label for="vat_from">Schimbă produsele cu cota</label>
                        <input id="vat_from" name="vat_from" type="number" min="0" max="100" step="0.01" value="<?= htmlspecialchars($vatCotaDominanta, ENT_QUOTES) ?>" required>
                    </div>
                    <div class="field" style="flex:1;min-width:150px;">
                        <label for="vat_to">în cota</label>
                        <input id="vat_to" name="vat_to" type="number" min="0" max="100" step="0.01" value="21" required>
                    </div>
                </div>
                <div style="display:grid;gap:10px;margin-top:14px;max-width:640px;">
                    <label style="display:flex;gap:8px;align-items:flex-start;">
                        <input type="radio" name="vat_mode" value="pastreaza_pret" checked style="margin-top:3px;">
                        <span>
                            <strong>Păstrează prețurile afișate</strong><br>
                            <span style="color:#64748b;font-size:13px;">
                                Clientul plătește exact cât plătea și ieri. Se schimbă doar cât din preț
                                este TVA, deci magazinul încasează mai puțin net. Nimic altceva nu se atinge.
                            </span>
                        </span>
                    </label>
                    <label style="display:flex;gap:8px;align-items:flex-start;">
                        <input type="radio" name="vat_mode" value="pastreaza_net" style="margin-top:3px;">
                        <span>
                            <strong>Păstrează suma fără TVA (crește prețurile afișate)</strong><br>
                            <span style="color:#64748b;font-size:13px;">
                                Magazinul rămâne cu aceeași sumă după TVA, deci prețurile de raft cresc
                                (de la 19% la 21% înseamnă +1,68%). Se recalculează prețul de bază,
                                prețul redus, perioadele de reducere programate și prețurile BBD.
                                Prețurile rezultate pot ieși nerotunde.
                            </span>
                        </span>
                    </label>
                </div>
                <label style="display:flex;align-items:center;gap:8px;margin-top:12px;">
                    <input type="checkbox" name="vat_set_default" value="1" checked>
                    Folosește cota nouă și pentru produsele adăugate de acum înainte
                </label>
                <p style="margin:12px 0 0;color:#b45309;font-size:13px;max-width:640px;">
                    Comenzile deja plasate nu se ating: ele păstrează cota din momentul vânzării.
                    Fă o copie a bazei de date înainte, operația nu are buton de anulare.
                </p>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Schimbă TVA pe produse</button>
                </div>
            </form>
        </article>

        <article class="panel store-settings-panel" data-store-settings-panel="numerotare" <?= $defaultStoreTab !== 'numerotare' ? 'hidden' : '' ?> style="margin:12px 0;">
            <h3 style="margin-top:0;">Numerotare comenzi</h3>
            <p style="margin:0 0 10px;color:#64748b;">
                Numărul comenzii este cel văzut de client, cel trimis în ERP și cel
                trimis la EuPlătesc. Comenzile deja emise nu se renumerotează.
            </p>
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_order_numbering">
                <div class="field" style="max-width:420px;">
                    <label for="order_number_mode">Format</label>
                    <select id="order_number_mode" name="order_number_mode">
                        <option value="<?= \App\Support\OrderNumber::MOD_SECVENTIAL ?>" <?= $orderNumberMode === \App\Support\OrderNumber::MOD_SECVENTIAL ? 'selected' : '' ?>>Numere consecutive (203800, 203801, …)</option>
                        <option value="<?= \App\Support\OrderNumber::MOD_VECHI ?>" <?= $orderNumberMode === \App\Support\OrderNumber::MOD_VECHI ? 'selected' : '' ?>>Format vechi (BV + dată + aleator)</option>
                    </select>
                </div>
                <div class="field" style="max-width:420px;margin-top:10px;">
                    <label for="order_number_next">Numărul următoarei comenzi</label>
                    <input id="order_number_next" name="order_number_next" type="number" min="1" step="1" value="<?= htmlspecialchars((string) $orderNumberNext, ENT_QUOTES) ?>">
                    <p style="margin:6px 0 0;color:#64748b;font-size:13px;">
                        Următoarea comandă primește exact acest număr, apoi contorul crește singur.
                        Dacă pui un număr deja folosit, sistemul îl sare până găsește unul liber.
                    </p>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează numerotarea</button>
                </div>
            </form>
        </article>

        <article class="panel store-settings-panel" data-store-settings-panel="quantity" <?= $defaultStoreTab !== 'quantity' ? 'hidden' : '' ?> style="margin:12px 0;">
            <h3 style="margin-top:0;">Control cantitate</h3>
            <p style="margin:0 0 10px;color:#64748b;">
                Alege modelul de cantitate și unde să fie afișat.
            </p>
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_quantity_controls">
                <div class="field" style="max-width:420px;">
                    <label for="store_quantity_control_style">Model cantitate</label>
                    <select id="store_quantity_control_style" name="store_quantity_control_style">
                        <option value="default" <?= $quantityStyle === 'default' ? 'selected' : '' ?>>Implicit (input numeric simplu)</option>
                        <option value="stepper" <?= $quantityStyle === 'stepper' ? 'selected' : '' ?>>Stepper (+ / - lângă câmp)</option>
                    </select>
                </div>
                <div style="display:grid;gap:8px;margin-top:10px;">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="store_quantity_apply_product_template" value="1" <?= $applyProductTemplate ? 'checked' : '' ?>>
                        Aplică în template-urile produselor
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="store_quantity_apply_floating_cart" value="1" <?= $applyFloatingCart ? 'checked' : '' ?>>
                        Aplică în coșul flotant
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="store_quantity_apply_cart_page" value="1" <?= $applyCartPage ? 'checked' : '' ?>>
                        Aplică în pagina coșului
                    </label>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează control cantitate</button>
                </div>
            </form>
        </article>

        <article class="panel store-settings-panel" data-store-settings-panel="widgets" <?= $defaultStoreTab !== 'widgets' ? 'hidden' : '' ?> style="margin:12px 0;">
            <h3 style="margin-top:0;">Sertar lateral „Oferte”</h3>
            <p style="margin:0 0 10px;color:#64748b;">
                Panoul care apare pe marginea din stânga a site-ului, cu produsele
                aflate la preț redus. Dezactivează-l dacă nu vrei să apară deloc.
            </p>
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_bbd_sidebar">
                <div style="display:grid;gap:8px;">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="store_bbd_sidebar_enabled" value="1" <?= $bbdSidebarEnabled ? 'checked' : '' ?>>
                        Afișează sertarul „Oferte” pe site
                    </label>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează</button>
                </div>
            </form>
        </article>

        <article class="panel store-settings-panel" data-store-settings-panel="caching" <?= $defaultStoreTab !== 'caching' ? 'hidden' : '' ?> style="margin:12px 0;">
            <h3 style="margin-top:0;">Caching (file-based)</h3>
            <p style="margin:0 0 10px;color:#64748b;">
                Cache public doar pentru pagini GET din whitelist. Checkout/cont/admin/api/cos sunt excluse implicit.
            </p>

            <div class="panel" style="margin:12px 0;background:#f8fafc;border-color:#dbe4ef;">
                <h4 style="margin:0 0 8px;">Status cache pagini</h4>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <span class="status-pill">Intrări: <?= (int) ($cacheStats['entries'] ?? 0) ?></span>
                    <span class="status-pill ok">Fresh: <?= (int) ($cacheStats['fresh'] ?? 0) ?></span>
                    <span class="status-pill off">Expirate: <?= (int) ($cacheStats['expired'] ?? 0) ?></span>
                </div>
            </div>

            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_caching_settings">
                <div class="panel" style="margin:12px 0;">
                    <h4 style="margin-top:0;">Pagini (whitelist + TTL)</h4>
                    <label style="display:flex;align-items:center;gap:8px;margin:0 0 10px;">
                        <input type="checkbox" name="cache_pages_enabled" value="1" <?= $cachePagesEnabled ? 'checked' : '' ?>>
                        Activează cache pentru paginile din whitelist
                    </label>
                    <div id="cache-rules-wrap" style="display:grid;gap:8px;">
                        <?php foreach ($cacheRules as $idx => $rule): ?>
                            <?php if (!is_array($rule)) {
                                continue;
                            } ?>
                            <div class="cache-rule-row" style="display:grid;grid-template-columns:minmax(0,1fr) 180px auto;gap:8px;align-items:end;">
                                <label style="display:grid;gap:4px;">
                                    <span>URL / pattern</span>
                                    <input type="text" name="cache_page_paths[]" value="<?= htmlspecialchars((string) ($rule['path_pattern'] ?? ''), ENT_QUOTES) ?>" placeholder="/magazin sau /blog/*">
                                </label>
                                <label style="display:grid;gap:4px;">
                                    <span>TTL</span>
                                    <select name="cache_page_ttls[]">
                                        <?php
                                        $ttlValue = (int) ($rule['ttl_seconds'] ?? 1800);
                                        $ttlOptions = [
                                            300 => '5 minute',
                                            1800 => '30 minute',
                                            7200 => '2 ore',
                                            21600 => '6 ore',
                                            86400 => '24 ore',
                                        ];
                                        foreach ($ttlOptions as $ttlSeconds => $ttlLabel):
                                            ?>
                                            <option value="<?= $ttlSeconds ?>" <?= $ttlValue === $ttlSeconds ? 'selected' : '' ?>><?= htmlspecialchars($ttlLabel, ENT_QUOTES) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="btn btn-secondary" type="button" data-remove-cache-rule>Elimină</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="btn btn-secondary" type="button" id="add-cache-rule-btn">+ Adaugă pagină în whitelist</button>
                    </div>
                </div>

                <div class="panel" style="margin:12px 0;">
                    <h4 style="margin-top:0;">Imagini / assets</h4>
                    <label style="display:flex;align-items:center;gap:8px;margin:0 0 10px;">
                        <input type="checkbox" name="cache_assets_enabled" value="1" <?= $cacheAssetsEnabled ? 'checked' : '' ?>>
                        Activează cache headers pentru <code>/assets</code> și <code>/uploads</code>
                    </label>
                    <div class="field" style="max-width:360px;">
                        <label for="cache_assets_ttl_seconds">TTL assets</label>
                        <select id="cache_assets_ttl_seconds" name="cache_assets_ttl_seconds">
                            <?php
                            $assetTtlOptions = [
                                1800 => '30 minute',
                                7200 => '2 ore',
                                21600 => '6 ore',
                                86400 => '24 ore',
                                604800 => '7 zile',
                            ];
                            foreach ($assetTtlOptions as $ttlSeconds => $ttlLabel):
                                ?>
                                <option value="<?= $ttlSeconds ?>" <?= $cacheAssetsTtl === $ttlSeconds ? 'selected' : '' ?>><?= htmlspecialchars($ttlLabel, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" style="max-width:360px;">
                        <label for="cache_uploads_ttl_seconds">TTL uploads</label>
                        <select id="cache_uploads_ttl_seconds" name="cache_uploads_ttl_seconds">
                            <?php foreach ($assetTtlOptions as $ttlSeconds => $ttlLabel): ?>
                                <option value="<?= $ttlSeconds ?>" <?= $cacheUploadsTtl === $ttlSeconds ? 'selected' : '' ?>><?= htmlspecialchars($ttlLabel, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" style="max-width:360px;">
                        <label for="cache_assets_versioning_mode">Versioning assets</label>
                        <select id="cache_assets_versioning_mode" name="cache_assets_versioning_mode">
                            <option value="none" <?= $cacheAssetsVersioningMode === 'none' ? 'selected' : '' ?>>Dezactivat</option>
                            <option value="query" <?= $cacheAssetsVersioningMode === 'query' ? 'selected' : '' ?>>Query parameter (?v=token)</option>
                            <option value="hash" <?= $cacheAssetsVersioningMode === 'hash' ? 'selected' : '' ?>>Query hash (?h=token)</option>
                        </select>
                        <small style="color:#64748b;">Folosit pentru cache busting după update.</small>
                    </div>
                    <label style="display:flex;align-items:center;gap:8px;margin:10px 0 0;">
                        <input type="checkbox" name="cache_assets_etag_enabled" value="1" <?= $cacheAssetsEtagEnabled ? 'checked' : '' ?>>
                        Activează ETag / Last-Modified pentru assets
                    </label>
                    <?php if ($cacheAssetsVersionToken !== ''): ?>
                        <small style="color:#64748b;">Token curent versioning: <code><?= htmlspecialchars($cacheAssetsVersionToken, ENT_QUOTES) ?></code></small>
                    <?php endif; ?>
                    <label style="display:flex;align-items:center;gap:8px;margin:4px 0 0;">
                        <input type="checkbox" name="regenerate_assets_version_token" value="1">
                        Regenerează token-ul de versioning la salvare
                    </label>
                </div>

                <div class="panel" style="margin:12px 0;">
                    <h4 style="margin-top:0;">Excluderi custom</h4>
                    <p style="margin:0 0 8px;color:#64748b;">
                        Excluderile implicite sunt active permanent: /checkout, /contul-meu, /admin, /api/*, /cos, /auth/*.
                    </p>
                    <div class="field">
                        <label for="cache_exclusions_custom">Excluderi suplimentare (un pattern pe rând)</label>
                        <textarea id="cache_exclusions_custom" name="cache_exclusions_custom" rows="5" placeholder="/contact&#10;/blog/*/preview"><?= htmlspecialchars($cacheCustomExclusions, ENT_QUOTES) ?></textarea>
                    </div>
                </div>

                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează setările de caching</button>
                </div>
            </form>

            <div class="panel" style="margin:12px 0;">
                <h4 style="margin-top:0;">Purge / invalidare</h4>
                <div style="display:grid;gap:8px;">
                    <form method="post" action="/admin/settings/store" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
                        <input type="hidden" name="action" value="purge_cache_page">
                        <div class="field" style="min-width:320px;max-width:560px;">
                            <label for="purge_cache_path">Invalidate cache pagină (pattern)</label>
                            <input id="purge_cache_path" type="text" name="purge_cache_path" placeholder="/magazin sau /blog/*">
                        </div>
                        <button class="btn btn-secondary" type="submit">Invalidate cache pagină</button>
                    </form>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <form method="post" action="/admin/settings/store">
                            <input type="hidden" name="action" value="purge_cache_pages">
                            <button class="btn btn-secondary" type="submit">Golește doar pagini</button>
                        </form>
                        <form method="post" action="/admin/settings/store">
                            <input type="hidden" name="action" value="purge_cache_assets">
                            <button class="btn btn-secondary" type="submit">Golește doar assets</button>
                        </form>
                        <form method="post" action="/admin/settings/store" onsubmit="return confirm('Sigur vrei să golești TOT cache-ul?');">
                            <input type="hidden" name="action" value="purge_cache_all">
                            <button class="btn" type="submit">Golește tot cache-ul</button>
                        </form>
                    </div>
                </div>
            </div>
        </article>

        <article class="panel store-settings-panel" data-store-settings-panel="popup" <?= $defaultStoreTab !== 'popup' ? 'hidden' : '' ?> style="margin:12px 0;">
            <h3 style="margin-top:0;">Popup anunț la intrarea pe site</h3>
            <p style="margin:0 0 10px;color:#64748b;max-width:640px;">
                Mesajul apare o singură dată, imediat ce un vizitator deschide site-ul.
                După ce apasă pe „Am înțeles" (sau pe X), nu îi mai apare.
                Dacă modifici textul, popup-ul reapare o dată pentru toată lumea, inclusiv
                pentru cei care l-au închis pe cel vechi.
            </p>
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_welcome_popup">
                <label style="display:flex;align-items:center;gap:8px;margin:0 0 12px;">
                    <input type="checkbox" name="welcome_popup_enabled" value="1" <?= $popupEnabled ? 'checked' : '' ?>>
                    Afișează popup-ul pe site
                </label>
                <div class="field" style="max-width:640px;">
                    <label for="welcome_popup_title">Titlu</label>
                    <input id="welcome_popup_title" name="welcome_popup_title" type="text" value="<?= htmlspecialchars($popupTitle, ENT_QUOTES) ?>">
                </div>
                <div class="field" style="max-width:640px;margin-top:10px;">
                    <label for="welcome_popup_body">Mesaj</label>
                    <textarea id="welcome_popup_body" name="welcome_popup_body" rows="12" style="width:100%;"><?= htmlspecialchars($popupBody, ENT_QUOTES) ?></textarea>
                    <p style="margin:6px 0 0;color:#64748b;font-size:13px;">
                        Text simplu; rândurile goale despart paragrafele.
                    </p>
                </div>
                <div style="margin-top:12px;">
                    <button class="btn" type="submit">Salvează popup-ul</button>
                </div>
            </form>
        </article>
    <?php endif; ?>
</section>

<div class="modal-overlay" id="store-favicon-modal">
    <div class="modal-card" style="max-width:900px;">
        <div class="modal-head">
            <h3>Selectează favicon din Galerie</h3>
            <button type="button" class="icon-btn" id="close-store-favicon-picker" aria-label="Închide">✕</button>
        </div>
        <div class="field" style="margin-bottom:10px;">
            <input type="text" id="store-favicon-search" placeholder="Caută imagine după titlu...">
        </div>
        <div class="product-picker-grid" id="store-favicon-grid">
            <?php foreach ($galleryImages as $image): ?>
                <button
                    type="button"
                    class="product-picker-item store-favicon-item"
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

<div class="modal-overlay" id="store-seo-image-modal">
    <div class="modal-card" style="max-width:900px;">
        <div class="modal-head">
            <h3>Selectează imagine SEO din Galerie</h3>
            <button type="button" class="icon-btn" id="close-seo-image-picker" aria-label="Închide">✕</button>
        </div>
        <div class="field" style="margin-bottom:10px;">
            <input type="text" id="seo-image-search" placeholder="Caută imagine după titlu...">
        </div>
        <div class="product-picker-grid" id="seo-image-grid">
            <?php foreach ($galleryImages as $image): ?>
                <button
                    type="button"
                    class="product-picker-item seo-image-item"
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

<script>
(() => {
    const modal = document.getElementById('store-favicon-modal');
    const openBtn = document.getElementById('open-store-favicon-picker');
    const closeBtn = document.getElementById('close-store-favicon-picker');
    const clearBtn = document.getElementById('clear-store-favicon-picker');
    const searchInput = document.getElementById('store-favicon-search');
    const items = Array.from(document.querySelectorAll('.store-favicon-item'));
    const input = document.getElementById('store_favicon_url');
    const preview = document.getElementById('store_favicon_preview');
    const label = document.getElementById('store_favicon_label');

    if (!(modal instanceof HTMLElement) || !(input instanceof HTMLInputElement) || !(preview instanceof HTMLImageElement) || !(label instanceof HTMLElement)) {
        return;
    }

    const fallback = '/assets/img/product-placeholder.svg';
    const setFavicon = (url) => {
        const safe = String(url || '').trim();
        input.value = safe;
        preview.src = safe !== '' ? safe : fallback;
        label.textContent = safe !== '' ? safe : fallback;
    };

    openBtn?.addEventListener('click', () => modal.classList.add('open'));
    closeBtn?.addEventListener('click', () => modal.classList.remove('open'));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.classList.remove('open');
        }
    });
    clearBtn?.addEventListener('click', () => setFavicon(''));
    searchInput?.addEventListener('input', () => {
        const query = (searchInput.value || '').trim().toLowerCase();
        items.forEach((item) => {
            const haystack = item.getAttribute('data-search') || '';
            item.style.display = haystack.includes(query) ? '' : 'none';
        });
    });
    items.forEach((item) => {
        item.addEventListener('click', () => {
            setFavicon(item.getAttribute('data-image-url') || '');
            modal.classList.remove('open');
        });
    });
})();

(() => {
    const modal = document.getElementById('store-seo-image-modal');
    const openBtn = document.getElementById('open-seo-image-picker');
    const closeBtn = document.getElementById('close-seo-image-picker');
    const clearBtn = document.getElementById('clear-seo-image-picker');
    const searchInput = document.getElementById('seo-image-search');
    const items = Array.from(document.querySelectorAll('.seo-image-item'));
    const input = document.getElementById('seo_default_image_url');
    const preview = document.getElementById('seo_default_image_preview');
    const label = document.getElementById('seo_default_image_label');

    if (!(modal instanceof HTMLElement) || !(input instanceof HTMLInputElement) || !(preview instanceof HTMLImageElement) || !(label instanceof HTMLElement)) {
        return;
    }

    const fallback = '/assets/img/product-placeholder.svg';
    const setImage = (url) => {
        const safe = String(url || '').trim();
        input.value = safe;
        preview.src = safe !== '' ? safe : fallback;
        label.textContent = safe !== '' ? safe : fallback;
    };

    openBtn?.addEventListener('click', () => modal.classList.add('open'));
    closeBtn?.addEventListener('click', () => modal.classList.remove('open'));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.classList.remove('open');
        }
    });
    clearBtn?.addEventListener('click', () => setImage(''));
    searchInput?.addEventListener('input', () => {
        const query = (searchInput.value || '').trim().toLowerCase();
        items.forEach((item) => {
            const haystack = item.getAttribute('data-search') || '';
            item.style.display = haystack.includes(query) ? '' : 'none';
        });
    });
    items.forEach((item) => {
        item.addEventListener('click', () => {
            setImage(item.getAttribute('data-image-url') || '');
            modal.classList.remove('open');
        });
    });
})();

(() => {
    const tabsWrap = document.querySelector('[data-store-settings-tabs]');
    if (!(tabsWrap instanceof HTMLElement)) {
        return;
    }
    const tabs = Array.from(tabsWrap.querySelectorAll('[data-store-settings-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-store-settings-panel]'));
    if (tabs.length === 0 || panels.length === 0) {
        return;
    }

    const storageKey = 'admin_store_settings_active_tab';
    const activate = (key, updateHash = false) => {
        tabs.forEach((btn) => {
            if (!(btn instanceof HTMLElement)) {
                return;
            }
            const active = btn.getAttribute('data-store-settings-tab') === key;
            btn.classList.toggle('is-active', active);
        });
        panels.forEach((panel) => {
            if (!(panel instanceof HTMLElement)) {
                return;
            }
            panel.hidden = panel.getAttribute('data-store-settings-panel') !== key;
        });
        try {
            window.localStorage.setItem(storageKey, key);
        } catch (_error) {
        }
        if (updateHash) {
            window.location.hash = key;
        }
    };

    const hashKey = String(window.location.hash || '').replace(/^#/, '').trim();
    const hasHash = tabs.some((btn) => btn.getAttribute('data-store-settings-tab') === hashKey);
    if (hasHash) {
        activate(hashKey);
    } else {
        let defaultKey = String(tabsWrap.getAttribute('data-default-tab') || 'pages').trim();
        if (!tabs.some((btn) => btn.getAttribute('data-store-settings-tab') === defaultKey)) {
            defaultKey = 'pages';
        }
        try {
            const stored = String(window.localStorage.getItem(storageKey) || '').trim();
            if (tabs.some((btn) => btn.getAttribute('data-store-settings-tab') === stored)) {
                defaultKey = stored;
            }
        } catch (_error) {
        }
        activate(defaultKey);
    }

    tabs.forEach((btn) => {
        btn.addEventListener('click', () => {
            const key = String(btn.getAttribute('data-store-settings-tab') || 'pages').trim();
            activate(key, true);
        });
    });

    window.addEventListener('hashchange', () => {
        const nextKey = String(window.location.hash || '').replace(/^#/, '').trim();
        if (tabs.some((btn) => btn.getAttribute('data-store-settings-tab') === nextKey)) {
            activate(nextKey);
        }
    });
})();

(() => {
    const addRuleBtn = document.getElementById('add-cache-rule-btn');
    const wrap = document.getElementById('cache-rules-wrap');
    if (!(addRuleBtn instanceof HTMLElement) || !(wrap instanceof HTMLElement)) {
        return;
    }
    const ttlOptionsHtml = [
        { value: 300, label: '5 minute' },
        { value: 1800, label: '30 minute' },
        { value: 7200, label: '2 ore' },
        { value: 21600, label: '6 ore' },
        { value: 86400, label: '24 ore' },
    ].map((item) => `<option value="${item.value}">${item.label}</option>`).join('');
    addRuleBtn.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'cache-rule-row';
        row.style.display = 'grid';
        row.style.gridTemplateColumns = 'minmax(0,1fr) 180px auto';
        row.style.gap = '8px';
        row.style.alignItems = 'end';
        row.innerHTML = `
            <label style="display:grid;gap:4px;">
                <span>URL / pattern</span>
                <input type="text" name="cache_page_paths[]" value="" placeholder="/magazin sau /blog/*">
            </label>
            <label style="display:grid;gap:4px;">
                <span>TTL</span>
                <select name="cache_page_ttls[]">${ttlOptionsHtml}</select>
            </label>
            <button class="btn btn-secondary" type="button" data-remove-cache-rule>Elimină</button>
        `;
        wrap.appendChild(row);
    });
    wrap.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        const removeBtn = target.closest('[data-remove-cache-rule]');
        if (!(removeBtn instanceof HTMLElement)) {
            return;
        }
        const row = removeBtn.closest('.cache-rule-row');
        if (row instanceof HTMLElement) {
            row.remove();
        }
    });
})();
</script>
