<?php
$extraFields = is_array($extraFields ?? null) ? $extraFields : [];
$template = is_array($productTemplate ?? null) ? $productTemplate : null;
$templateRender = is_array($productTemplateRender ?? null) ? $productTemplateRender : null;
$reviews = is_array($productReviews ?? null) ? $productReviews : [];
$similarProductsSectionHtml = (string) ($similarProductsSectionHtml ?? '');
$hasTemplateRender = is_array($template) && is_array($templateRender) && trim((string) ($templateRender['html'] ?? '')) !== '';
$badgeHtml = (string) ($product['badge_html'] ?? '');
$galleryUrls = is_array($product['gallery_images'] ?? null) ? $product['gallery_images'] : [];
$isOutOfStock = (bool) ($isOutOfStock ?? false);
$templateCurrentPriceDisplay = number_format((float) ($product['price'] ?? 0), 2) . ' lei';
$templateOldPriceDisplay = (
    (bool) ($product['has_sale_price'] ?? false)
    && (float) ($product['base_price'] ?? 0) > (float) ($product['price'] ?? 0)
)
    ? number_format((float) ($product['base_price'] ?? 0), 2) . ' lei'
    : '';
$bbdEntries = is_array($product['bbd_entries'] ?? null) ? $product['bbd_entries'] : [];
$requiresBbdSelection = (bool) ($product['requires_bbd_selection'] ?? false) && $bbdEntries !== [];
$postCartNoteEnabled = (int) ($product['post_cart_note_enabled'] ?? 0) === 1;
$postCartNoteText = trim((string) ($product['post_cart_note_text'] ?? ''));
$singleFormGap = $requiresBbdSelection ? 'display:grid;gap:10px;max-width:520px;' : 'display:flex;gap:8px;align-items:center;';
if ($galleryUrls === []) {
    $galleryUrls = [(string) ($product['image_url'] ?? '/assets/img/product-placeholder.svg')];
}
?>
<?php if ($hasTemplateRender): ?>
    <section class="panel product-template-render">
        <?php if (trim((string) ($templateRender['css'] ?? '')) !== ''): ?>
            <style><?= (string) ($templateRender['css'] ?? '') ?></style>
        <?php endif; ?>
        <?= (string) ($templateRender['html'] ?? '') ?>
        <?php if (trim((string) ($templateRender['js'] ?? '')) !== ''): ?>
            <script><?= str_replace('</script>', '<\/script>', (string) ($templateRender['js'] ?? '')) ?></script>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="panel">
        <h1><?= htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES) ?></h1>
        <div class="product-default-gallery">
            <?php if ($badgeHtml !== ''): ?>
                <div class="product-badge-corner"><?= $badgeHtml ?></div>
            <?php endif; ?>
            <img
                id="product-default-main-image"
                src="<?= htmlspecialchars((string) ($galleryUrls[0] ?? '/assets/img/product-placeholder.svg'), ENT_QUOTES) ?>"
                alt="<?= htmlspecialchars((string) ($product['name'] ?? 'Produs Bioscem'), ENT_QUOTES) ?>"
                loading="lazy"
                decoding="async"
                onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';"
                style="max-width:420px;width:100%;border-radius:10px;"
            >
            <?php if (count($galleryUrls) > 1): ?>
                <div class="product-default-thumbs">
                    <?php foreach ($galleryUrls as $url): ?>
                        <button type="button" class="product-default-thumb" data-image-url="<?= htmlspecialchars((string) $url, ENT_QUOTES) ?>">
                            <img
                                src="<?= htmlspecialchars((string) $url, ENT_QUOTES) ?>"
                                alt="<?= htmlspecialchars((string) ($product['name'] ?? 'Produs Bioscem'), ENT_QUOTES) ?>"
                                loading="lazy"
                                decoding="async"
                                onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';"
                            >
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="product-description"><?= \App\Support\RichText::render((string) ($product['description'] ?? $product['short_description'] ?? '')) ?></div>
        <?php if ($extraFields !== []): ?>
            <section class="panel" style="margin-top:14px;">
                <h3>Informații suplimentare</h3>
                <div class="order-modal-grid">
                    <?php foreach ($extraFields as $field): ?>
                        <?php
                        if (!is_array($field)) {
                            continue;
                        }
                        $label = trim((string) (($field['name'] ?? $field['label']) ?? ''));
                        if ($label === '') {
                            continue;
                        }
                        $value = trim((string) ($field['value'] ?? ''));
                        ?>
                        <div>
                            <small><?= htmlspecialchars($label, ENT_QUOTES) ?></small>
                            <p><?= nl2br(htmlspecialchars($value !== '' ? $value : '-', ENT_QUOTES)) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        <p class="price">
            <?php if ((bool) ($product['has_sale_price'] ?? false)): ?>
                <span class="price-old"><?= number_format((float) ($product['base_price'] ?? 0), 2) ?> lei</span>
            <?php endif; ?>
            <span class="price-current"><?= number_format((float) ($product['price'] ?? 0), 2) ?> lei</span>
        </p>
        <?php if ($isOutOfStock): ?>
            <p style="display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 16px;border:1px solid #e5e7eb;background:#f8fafc;color:#b91c1c;font-weight:700;border-radius:999px;">Stoc epuizat</p>
        <?php else: ?>
            <form method="post" action="/cos/adauga/<?= (int) ($product['id'] ?? 0) ?>" style="<?= $singleFormGap ?>" data-product-single-cart-form="1">
                <?php if ($requiresBbdSelection): ?>
                    <div style="display:grid;gap:8px;">
                        <span style="font-weight:600;">Alege oferta</span>
                        <input
                            id="bbd_key"
                            name="bbd_key"
                            type="hidden"
                            value=""
                            data-bbd-select
                            data-product-bbd-select="1"
                            data-unit-price="<?= htmlspecialchars(number_format((float) ($product['price'] ?? 0), 2, '.', ''), ENT_QUOTES) ?>"
                        >
                        <div class="product-bbd-choice-group" data-bbd-choice-group="1">
                            <?php foreach ($bbdEntries as $entry): ?>
                                <?php
                                if (!is_array($entry)) {
                                    continue;
                                }
                                $entryKey = trim((string) ($entry['key'] ?? ''));
                                if ($entryKey === '') {
                                    continue;
                                }
                                $entryPrice = $entry['reduced_price'] ?? null;
                                $entryDisplayPrice = ((is_numeric((string) $entryPrice) && (float) $entryPrice > 0.0)
                                    ? (float) $entryPrice
                                    : (float) ($product['price'] ?? 0.0));
                                $entryDateRaw = trim((string) ($entry['date'] ?? ''));
                                $entryDateTs = $entryDateRaw !== '' ? strtotime($entryDateRaw) : false;
                                $entryDateLabel = $entryDateTs !== false
                                    ? ('Expiră: ' . date('d.m.Y', $entryDateTs))
                                    : trim((string) ($entry['label'] ?? ''));
                                if ($entryDateLabel === '') {
                                    $entryDateLabel = 'Ofertă BBD';
                                }
                                $entryStockRaw = $entry['stock_remaining'] ?? $entry['stock'] ?? null;
                                $entryStock = (is_numeric((string) $entryStockRaw) ? max(0, (int) $entryStockRaw) : null);
                                $entryDisabled = $entryStock !== null && $entryStock <= 0;
                                ?>
                                <button
                                    type="button"
                                    class="product-bbd-pill"
                                    data-bbd-option="1"
                                    data-bbd-key="<?= htmlspecialchars($entryKey, ENT_QUOTES) ?>"
                                    data-unit-price="<?= htmlspecialchars(number_format($entryDisplayPrice, 2, '.', ''), ENT_QUOTES) ?>"
                                    <?= $entryDisabled ? 'disabled data-bbd-out-of-stock="1"' : '' ?>
                                >
                                    <span><?= htmlspecialchars($entryDateLabel, ENT_QUOTES) ?></span>
                                    <strong><?= htmlspecialchars(number_format($entryDisplayPrice, 2), ENT_QUOTES) ?> lei</strong>
                                    <?php if ($entryDisabled): ?>
                                        <em>Stoc epuizat</em>
                                    <?php elseif ($entryStock !== null && $entryStock < 10): ?>
                                        <em class="product-bbd-pill__low">Doar <?= (int) $entryStock ?> în stoc</em>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div style="display:flex;gap:8px;align-items:center;">
                    <label for="quantity">Cantitate</label>
                    <?php
                    $stocMaximProdus = ((int) ($product['stock_from_erp'] ?? 0) === 1)
                        ? max(0, (int) ($product['stock'] ?? 0))
                        : null;
                    ?>
                    <?php if ($stocMaximProdus !== null && $stocMaximProdus > 0): ?>
                        <p class="qty-stoc-note" data-stoc-note hidden>Cantitate maximă în stoc</p>
                    <?php endif; ?>
                    <input id="quantity" name="quantity" type="number" min="1" value="1"<?= $stocMaximProdus !== null && $stocMaximProdus > 0 ? ' max="' . $stocMaximProdus . '" data-stoc-maxim="' . $stocMaximProdus . '"' : '' ?><?= $requiresBbdSelection ? ' data-requires-bbd="1" disabled' : '' ?> style="width:90px;padding:8px;border:1px solid #d1d5db;border-radius:8px;">
                    <button class="btn" type="submit"<?= $requiresBbdSelection ? ' data-product-requires-bbd="1" disabled title="Alege oferta dorită"' : '' ?>>Adaugă în coș</button>
                </div>
                <?php if ($postCartNoteEnabled && $postCartNoteText !== ''): ?>
                    <div class="product-post-cart-note" style="font:500 13px/1.4 'DM Sans',Arial,sans-serif;color:#475569;">
                        <?= nl2br(htmlspecialchars($postCartNoteText, ENT_QUOTES)) ?>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        <section id="product-reviews" class="product-reviews" style="margin-top:18px;">
            <div class="product-reviews-head">
                <div class="product-reviews-head__inline">
                    <?= (string) ($reviews['stars_html'] ?? '') ?>
                    <p><?= htmlspecialchars((string) ($reviews['average_label'] ?? '0.0'), ENT_QUOTES) ?> din 5 · <?= (int) ($reviews['count'] ?? 0) ?> review-uri</p>
                </div>
            </div>
            <?= (string) ($reviews['list_html'] ?? '<p class="product-reviews-empty">Nu există review-uri încă.</p>') ?>
            <?= (string) ($reviews['form_html'] ?? '') ?>
        </section>
        <?= $similarProductsSectionHtml ?>
    </section>
<?php endif; ?>
<style>
.product-bbd-choice-group{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}
.product-bbd-pill{
    border:1px solid #cbd5e1;
    background:#ffffff;
    color:#0f172a;
    border-radius:999px;
    padding:8px 12px;
    display:inline-flex;
    flex-direction:column;
    align-items:flex-start;
    gap:2px;
    cursor:pointer;
    min-width:148px;
}
.product-bbd-pill span{
    font:600 12px/1.2 "DM Sans",Arial,sans-serif;
}
.product-bbd-pill strong{
    font:800 13px/1.2 "DM Sans",Arial,sans-serif;
    color:#0f7b53;
}
.product-bbd-pill em{
    font:600 11px/1.2 "DM Sans",Arial,sans-serif;
    color:#b91c1c;
    font-style:normal;
}
.product-bbd-pill em.product-bbd-pill__low{
    color:#b45309;
}
.product-bbd-pill.is-selected{
    border-color:#0f7b53;
    box-shadow:0 0 0 2px rgba(15,123,83,.15);
    background:#f0fdf4;
}
.product-bbd-pill:disabled{
    opacity:.65;
    cursor:not-allowed;
}
@media (max-width:640px){
    .product-bbd-pill{
        min-width:136px;
        padding:7px 10px;
    }
}
</style>
<script>
(() => {
    const initTabs = (scope) => {
        const navCandidates = Array.from(scope.querySelectorAll('.product-tabs__nav, .pdp-v2__tabs, [data-tabs-nav]'));
        navCandidates.forEach((nav) => {
            if (!(nav instanceof HTMLElement) || nav.dataset.tabsInitialized === '1') {
                return;
            }
            const tabs = Array.from(nav.querySelectorAll('[data-tab]'));
            if (tabs.length === 0) {
                return;
            }
            const container =
                nav.closest('[data-tabs-root], .product-tabs, .pdp-v2')
                || nav.closest('section, article, div')
                || scope;
            const panes = Array.from(container.querySelectorAll('[data-pane]'));
            if (panes.length === 0) {
                return;
            }

            panes.forEach((pane) => {
                const raw = (pane.textContent || '').replace(/\s+/g, ' ').trim();
                if (!/\{\{\s*field[_:][^}]+\}\}/.test(raw)) {
                    return;
                }
                const key = String(pane.getAttribute('data-pane') || '');
                pane.style.display = 'none';
                tabs
                    .filter((tab) => String(tab.getAttribute('data-tab') || '') === key)
                    .forEach((tab) => {
                        tab.style.display = 'none';
                        tab.classList.remove('is-active');
                    });
                pane.classList.remove('is-active');
            });

            const getVisibleTabs = () => tabs.filter((tab) => tab.style.display !== 'none');
            const getVisiblePanes = () => panes.filter((pane) => pane.style.display !== 'none');
            const contentWrap = container instanceof Element ? container.querySelector('.product-tabs__content') : null;
            const adjustTabsHeightToKey = () => {
                if (contentWrap instanceof HTMLElement) {
                    contentWrap.style.height = '';
                }
            };
            let activeKey = '';
            const activate = (key, animate = false) => {
                const previousKey = activeKey;
                activeKey = key;
                tabs.forEach((tab) => {
                    const isActive = tab.style.display !== 'none' && String(tab.getAttribute('data-tab') || '') === key;
                    tab.classList.toggle('is-active', isActive);
                    tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });
                panes.forEach((pane) => {
                    if (pane.style.display === 'none') {
                        pane.classList.remove('is-active');
                        pane.classList.remove('tab-anim-enter');
                        pane.classList.remove('tab-anim-leave');
                        return;
                    }
                    const paneKey = String(pane.getAttribute('data-pane') || '');
                    const nowActive = paneKey === key;
                    const wasActive = paneKey === previousKey;
                    if (animate && nowActive && !wasActive) {
                        pane.classList.remove('tab-anim-enter');
                        void pane.offsetWidth;
                        pane.classList.add('tab-anim-enter');
                    } else {
                        pane.classList.remove('tab-anim-enter');
                        pane.classList.remove('tab-anim-leave');
                    }
                    pane.classList.toggle('is-active', nowActive);
                });
                adjustTabsHeightToKey();
            };

            nav.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                const button = target.closest('[data-tab]');
                if (!(button instanceof HTMLElement) || !nav.contains(button) || button.style.display === 'none') {
                    return;
                }
                event.preventDefault();
                const key = String(button.getAttribute('data-tab') || '').trim();
                if (key !== '') {
                    activate(key, true);
                }
            });

            const visibleTabs = getVisibleTabs();
            const visiblePanes = getVisiblePanes();
            if (visibleTabs.length === 0 || visiblePanes.length === 0) {
                nav.dataset.tabsInitialized = '1';
                return;
            }
            const activeTab = visibleTabs.find((tab) => tab.classList.contains('is-active')) || visibleTabs[0];
            const initialKey = String(activeTab.getAttribute('data-tab') || '');
            if (initialKey !== '') {
                activate(initialKey);
            }
            adjustTabsHeightToKey();
            window.addEventListener('resize', () => adjustTabsHeightToKey(), { passive: true });

            // Support deep-linking via URL hash (#ingrediente, #administrare, #recenzii, etc.)
            const activateByAnchor = (rawHash, scroll) => {
                const anchor = String(rawHash || '').replace(/^#/, '').trim().toLowerCase();
                if (anchor === '') {
                    return false;
                }
                const slugify = (value) => String(value || '')
                    .toLowerCase()
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '');
                let btn = tabs.find((tab) =>
                    tab.style.display !== 'none'
                    && String(tab.getAttribute('data-anchor') || '').toLowerCase() === anchor
                );
                if (!(btn instanceof HTMLElement)) {
                    // Fallback: match by the tab's visible label slug.
                    btn = tabs.find((tab) =>
                        tab.style.display !== 'none'
                        && slugify(tab.textContent) === anchor
                    );
                }
                if (!(btn instanceof HTMLElement)) {
                    // Final fallback: match when the requested word is one of the tab's
                    // slug tokens (so #administrare matches a "mod-administrare" tab).
                    btn = tabs.find((tab) => {
                        if (tab.style.display === 'none') {
                            return false;
                        }
                        const tokens = new Set([
                            ...slugify(tab.getAttribute('data-anchor') || '').split('-'),
                            ...slugify(tab.textContent).split('-'),
                        ]);
                        return tokens.has(anchor);
                    });
                }
                if (!(btn instanceof HTMLElement)) {
                    return false;
                }
                const key = String(btn.getAttribute('data-tab') || '').trim();
                if (key === '') {
                    return false;
                }
                activate(key, true);
                if (scroll && container instanceof HTMLElement) {
                    container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                return true;
            };
            activateByAnchor(window.location.hash, true);
            window.addEventListener('hashchange', () => {
                activateByAnchor(window.location.hash, true);
            });

            nav.dataset.tabsInitialized = '1';
        });
    };

    const createFullscreenCarousel = (root, getCurrentIndex, setActive) => {
        const slides = Array.from(root.querySelectorAll('.product-carousel__slide img'));
        if (slides.length === 0) {
            return;
        }
        const overlay = document.createElement('div');
        overlay.className = 'product-carousel-fullscreen';
        overlay.innerHTML = ''
            + '<button type="button" class="product-carousel-fullscreen__close" aria-label="Închide">×</button>'
            + '<button type="button" class="product-carousel-fullscreen__nav" data-action="prev" aria-label="Imagine anterioară">‹</button>'
            + '<img class="product-carousel-fullscreen__image" alt="">'
            + '<button type="button" class="product-carousel-fullscreen__nav" data-action="next" aria-label="Imagine următoare">›</button>';
        document.body.appendChild(overlay);
        const image = overlay.querySelector('.product-carousel-fullscreen__image');
        const update = () => {
            if (!(image instanceof HTMLImageElement)) {
                return;
            }
            const idx = getCurrentIndex();
            const source = slides[idx];
            if (!(source instanceof HTMLImageElement)) {
                return;
            }
            image.src = source.currentSrc || source.src;
            image.alt = source.alt || '';
        };
        const close = () => overlay.classList.remove('is-active');
        overlay.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            const closeButton = target.closest('.product-carousel-fullscreen__close');
            if (closeButton || target === overlay) {
                close();
                return;
            }
            const navButton = target.closest('[data-action]');
            if (!(navButton instanceof HTMLElement)) {
                return;
            }
            const action = navButton.getAttribute('data-action');
            if (action === 'prev') {
                setActive(getCurrentIndex() - 1);
                update();
            } else if (action === 'next') {
                setActive(getCurrentIndex() + 1);
                update();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (!overlay.classList.contains('is-active')) {
                return;
            }
            if (event.key === 'Escape') {
                close();
            } else if (event.key === 'ArrowLeft') {
                setActive(getCurrentIndex() - 1);
                update();
            } else if (event.key === 'ArrowRight') {
                setActive(getCurrentIndex() + 1);
                update();
            }
        });
        root.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            const fullscreenButton = target.closest('.product-carousel__fullscreen,[data-action="fullscreen"]');
            if (fullscreenButton instanceof HTMLElement) {
                event.preventDefault();
                update();
                overlay.classList.add('is-active');
                return;
            }
            const imageCandidate = target.closest('[data-carousel-image="1"]');
            if (!(imageCandidate instanceof HTMLElement)) {
                return;
            }
            const targetIndex = Number(imageCandidate.getAttribute('data-target') || '0');
            if (Number.isFinite(targetIndex)) {
                setActive(targetIndex);
            }
        });
    };

    const initCarousels = (scope) => {
        const roots = Array.from(scope.querySelectorAll('[data-product-carousel], .product-carousel'));
        roots.forEach((root) => {
            if (!(root instanceof HTMLElement) || root.dataset.carouselInitialized === '1') {
                return;
            }
            const viewport = root.querySelector('[data-carousel-viewport], .product-carousel__viewport');
            const track = root.querySelector('.product-carousel__track');
            const slides = Array.from(root.querySelectorAll('.product-carousel__slide'));
            const thumbs = Array.from(root.querySelectorAll('.product-carousel__thumb'));
            if (!(viewport instanceof HTMLElement) || !(track instanceof HTMLElement) || slides.length === 0) {
                return;
            }
            let current = 0;
            let dragging = false;
            let startX = 0;
            let deltaX = 0;
            let activePointerId = null;
            const slideCount = slides.length;
            const getWidth = () => Math.max(1, viewport.clientWidth || root.clientWidth || 1);
            const getCurrentIndex = () => current;
            const setActive = (next, animate = true) => {
                if (next < 0) {
                    current = 0;
                } else if (next >= slideCount) {
                    current = slideCount - 1;
                } else {
                    current = next;
                }
                track.style.transition = animate ? 'transform .35s ease' : 'none';
                track.style.transform = 'translateX(' + (-100 * current) + '%)';
                thumbs.forEach((thumb, index) => {
                    thumb.classList.toggle('is-active', index === current);
                });
                const activeThumb = thumbs[current];
                if (activeThumb instanceof HTMLElement) {
                    activeThumb.scrollIntoView({ behavior: animate ? 'smooth' : 'auto', inline: 'center', block: 'nearest' });
                }
                root.setAttribute('data-current', String(current));
            };

            root.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                const navButton = target.closest('.product-carousel__nav');
                if (navButton instanceof HTMLElement) {
                    const action = navButton.getAttribute('data-action');
                    if (action === 'prev') {
                        setActive(current - 1);
                    } else if (action === 'next') {
                        setActive(current + 1);
                    }
                    return;
                }
                const thumbButton = target.closest('.product-carousel__thumb');
                if (thumbButton instanceof HTMLElement) {
                    const targetIndex = Number(thumbButton.getAttribute('data-target') || '0');
                    setActive(Number.isFinite(targetIndex) ? targetIndex : 0);
                }
            });

            viewport.addEventListener('pointerdown', (event) => {
                if (event.pointerType === 'mouse' && event.button !== 0) {
                    return;
                }
                const target = event.target;
                if (target instanceof Element && target.closest('.product-carousel__fullscreen')) {
                    return;
                }
                dragging = true;
                startX = event.clientX;
                deltaX = 0;
                activePointerId = event.pointerId;
                track.style.willChange = 'transform';
                viewport.setPointerCapture(event.pointerId);
                event.preventDefault();
                track.style.transition = 'none';
                root.classList.add('is-dragging');
            });
            viewport.addEventListener('pointermove', (event) => {
                if (!dragging) {
                    return;
                }
                deltaX = event.clientX - startX;
                const width = getWidth();
                const percentOffset = (deltaX / width) * 100;
                track.style.transform = 'translateX(' + ((-100 * current) + percentOffset) + '%)';
            });
            const endDrag = () => {
                if (!dragging) {
                    return;
                }
                dragging = false;
                if (activePointerId !== null && typeof viewport.releasePointerCapture === 'function') {
                    try {
                        viewport.releasePointerCapture(activePointerId);
                    } catch (_error) {
                        // ignore invalid pointer release
                    }
                }
                activePointerId = null;
                root.classList.remove('is-dragging');
                track.style.willChange = '';
                const threshold = getWidth() * 0.12;
                if (Math.abs(deltaX) >= threshold) {
                    setActive(current + (deltaX < 0 ? 1 : -1));
                } else {
                    setActive(current);
                }
                deltaX = 0;
            };
            viewport.addEventListener('pointerup', endDrag);
            viewport.addEventListener('pointercancel', endDrag);
            viewport.addEventListener('lostpointercapture', endDrag);

            createFullscreenCarousel(root, getCurrentIndex, (next) => setActive(next));
            setActive(0);
            root.dataset.carouselInitialized = '1';
        });
    };

    const initReviewRatings = (scope) => {
        const roots = Array.from(scope.querySelectorAll('[data-review-rating]'));
        roots.forEach((root) => {
            if (!(root instanceof HTMLElement) || root.dataset.reviewRatingInitialized === '1') {
                return;
            }
            const input = root.querySelector('[data-review-rating-input]');
            const stars = Array.from(root.querySelectorAll('[data-rating-value]'));
            if (!(input instanceof HTMLInputElement) || stars.length === 0) {
                return;
            }
            const apply = (nextValue) => {
                const value = Number.isFinite(nextValue) ? Math.max(1, Math.min(5, Math.round(nextValue))) : 5;
                input.value = String(value);
                stars.forEach((star, index) => {
                    if (!(star instanceof HTMLElement)) {
                        return;
                    }
                    const isActive = index < value;
                    star.classList.toggle('is-active', isActive);
                    star.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
            };

            root.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                const star = target.closest('[data-rating-value]');
                if (!(star instanceof HTMLElement) || !root.contains(star)) {
                    return;
                }
                event.preventDefault();
                apply(Number(star.getAttribute('data-rating-value') || '5'));
            });

            root.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                    return;
                }
                event.preventDefault();
                const current = Number(input.value || '5');
                const delta = event.key === 'ArrowRight' ? 1 : -1;
                apply(current + delta);
            });

            apply(Number(input.value || '5'));
            root.dataset.reviewRatingInitialized = '1';
        });
    };

    const initReviewPagination = (scope) => {
        const lists = Array.from(scope.querySelectorAll('.product-reviews-list'));
        const pageSize = 5;
        lists.forEach((list) => {
            if (!(list instanceof HTMLElement) || list.dataset.reviewPaginationInitialized === '1') {
                return;
            }
            const items = Array.from(list.querySelectorAll('.product-review-item'));
            if (items.length <= pageSize) {
                list.dataset.reviewPaginationInitialized = '1';
                return;
            }
            const totalPages = Math.ceil(items.length / pageSize);
            let currentPage = 0;
            const controls = document.createElement('div');
            controls.className = 'product-reviews-pagination';
            controls.innerHTML = ''
                + '<button type="button" class="product-reviews-pagination__btn" data-page-action="prev">‹ Anterior</button>'
                + '<span class="product-reviews-pagination__label" data-page-label>Pagina 1 / ' + totalPages + '</span>'
                + '<button type="button" class="product-reviews-pagination__btn" data-page-action="next">Următor ›</button>';
            list.insertAdjacentElement('afterend', controls);
            const prevBtn = controls.querySelector('[data-page-action="prev"]');
            const nextBtn = controls.querySelector('[data-page-action="next"]');
            const label = controls.querySelector('[data-page-label]');
            const render = () => {
                const start = currentPage * pageSize;
                const end = start + pageSize;
                items.forEach((item, index) => {
                    if (!(item instanceof HTMLElement)) {
                        return;
                    }
                    item.hidden = index < start || index >= end;
                });
                if (label instanceof HTMLElement) {
                    label.textContent = 'Pagina ' + (currentPage + 1) + ' / ' + totalPages;
                }
                if (prevBtn instanceof HTMLButtonElement) {
                    prevBtn.disabled = currentPage <= 0;
                }
                if (nextBtn instanceof HTMLButtonElement) {
                    nextBtn.disabled = currentPage >= (totalPages - 1);
                }
            };
            prevBtn?.addEventListener('click', () => {
                if (currentPage <= 0) {
                    return;
                }
                currentPage--;
                render();
            });
            nextBtn?.addEventListener('click', () => {
                if (currentPage >= (totalPages - 1)) {
                    return;
                }
                currentPage++;
                render();
            });
            render();
            list.dataset.reviewPaginationInitialized = '1';
        });
    };

    const initSimilarProducts = (scope) => {
        const roots = Array.from(scope.querySelectorAll('[data-similar-products]'));
        roots.forEach((root) => {
            if (!(root instanceof HTMLElement) || root.dataset.similarInitialized === '1') {
                return;
            }
            const dataNode = root.querySelector('.similar-products__data');
            const track = root.querySelector('[data-similar-track]');
            const viewport = root.querySelector('.similar-products__viewport');
            if (!(dataNode instanceof HTMLScriptElement) || !(track instanceof HTMLElement) || !(viewport instanceof HTMLElement)) {
                return;
            }
            let products = [];
            try {
                const parsed = JSON.parse(dataNode.textContent || '[]');
                if (Array.isArray(parsed)) {
                    products = parsed.filter((item) => item && typeof item === 'object');
                }
            } catch (_error) {
                products = [];
            }
            const selectors = Array.from(root.querySelectorAll('[data-similar-slot]'));
            const navButtons = Array.from(root.querySelectorAll('.similar-products__nav'));
            const dotsWrap = root.querySelector('[data-similar-dots]');
            const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => {
                if (char === '&') return '&amp;';
                if (char === '<') return '&lt;';
                if (char === '>') return '&gt;';
                if (char === '"') return '&quot;';
                return '&#39;';
            });
            const visibleColumns = () => {
                const width = window.innerWidth || document.documentElement.clientWidth || 1200;
                if (width <= 800) return 2;
                if (width <= 1100) return 3;
                return 4;
            };
            const pageWidth = () => viewport.clientWidth || Math.max(1, root.clientWidth || 1);
            const pagesCount = () => {
                return Math.max(0, track.querySelectorAll('.similar-products__slide').length);
            };
            const currentPage = () => {
                const width = pageWidth();
                if (width <= 1) {
                    return 0;
                }
                return Math.round(viewport.scrollLeft / width);
            };
            const buildDots = () => {
                if (!(dotsWrap instanceof HTMLElement)) {
                    return;
                }
                const count = pagesCount();
                if (count <= 1) {
                    dotsWrap.innerHTML = '';
                    dotsWrap.style.display = 'none';
                    return;
                }
                dotsWrap.style.display = '';
                const active = Math.max(0, Math.min(count - 1, currentPage()));
                dotsWrap.innerHTML = Array.from({ length: count }).map((_, index) => {
                    const activeClass = index === active ? ' is-active' : '';
                    return '<button type="button" class="similar-products__dot' + activeClass + '" data-dot-page="' + index + '" aria-label="Pagina ' + (index + 1) + '"></button>';
                }).join('');
            };
            const safeImageUrl = (value) => {
                const raw = String(value || '').trim();
                if (raw.startsWith('/') || /^https?:\/\//i.test(raw)) {
                    return raw;
                }
                return '/assets/img/product-placeholder.svg';
            };
            const safeLinkUrl = (value) => {
                const raw = String(value || '').trim();
                if (raw.startsWith('/') || /^https?:\/\//i.test(raw)) {
                    return raw;
                }
                return '#';
            };
            const render = () => {
                const selected = selectors.length > 0
                    ? []
                    : products.slice(0, 24);

                if (selectors.length > 0) {
                    const byId = new Map(products.map((item) => [String(item.id || ''), item]));
                    selectors.forEach((select) => {
                        if (!(select instanceof HTMLSelectElement)) {
                            return;
                        }
                        const key = String(select.value || '');
                        if (key === '' || selected.some((item) => String(item.id || '') === key)) {
                            return;
                        }
                        const found = byId.get(key);
                        if (found) {
                            selected.push(found);
                        }
                    });
                }
                if (selected.length === 0) {
                    track.innerHTML = '<div class="similar-products__slide"><article class="similar-products__empty"><p>Selectează cel puțin un produs din lista de mai sus.</p></article></div>';
                    viewport.scrollLeft = 0;
                    navButtons.forEach((button) => {
                        if (button instanceof HTMLButtonElement) {
                            button.disabled = true;
                            button.style.visibility = 'hidden';
                        }
                    });
                    buildDots();
                    return;
                }
                const cols = Math.max(1, visibleColumns());
                root.style.setProperty('--similar-columns', String(cols));
                const cards = selected.map((item) => {
                    const image = escapeHtml(safeImageUrl(item.image_url || '/assets/img/product-placeholder.svg'));
                    const name = escapeHtml(item.name || 'Produs');
                    const url = escapeHtml(safeLinkUrl(item.url || '#'));
                    const description = escapeHtml(item.short_description || 'Supliment natural');
                    const priceLabel = escapeHtml(item.price_label || '');
                    const regularPriceLabel = escapeHtml(item.regular_price_label || '');
                    const reviewsCount = Math.max(0, Number.parseInt(item.reviews_count || '0', 10) || 0);
                    const reviewsAverage = Math.max(0, Math.min(5, Number.parseFloat(item.reviews_average || '0') || 0));
                    const roundedStars = Math.round(reviewsAverage);
                    const starsHtml = Array.from({ length: 5 }).map((_, index) => {
                        const isFilled = index < roundedStars;
                        return '<svg class="similar-product-card__star" viewBox="0 0 24 24" fill="' + (isFilled ? 'currentColor' : 'none') + '" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3.8l2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 17l-5.2 2.8 1-5.8-4.2-4.1 5.8-.8L12 3.8z"/></svg>';
                    }).join('');
                    const priceValue = Math.max(0, Number.parseFloat(item.price || '0') || 0);
                    const regularPriceValue = Math.max(0, Number.parseFloat(item.regular_price || '0') || 0);
                    const hasSalePrice = String(item.has_sale_price || '0') === '1';
                    const discount = hasSalePrice && regularPriceValue > priceValue
                        ? Math.round(((regularPriceValue - priceValue) / regularPriceValue) * 100)
                        : 0;
                    const badgeMode = String(item.discount_badge_mode || 'percent') === 'value' ? 'value' : 'percent';
                    const discountValueLabel = escapeHtml(item.discount_value_label || '');
                    const bubbleText = badgeMode === 'value' && discountValueLabel ? ('-' + discountValueLabel + ' lei') : ('-' + discount + '%');
                    const outOfStock = String(item.out_of_stock || '0') === '1';
                    const productId = Math.max(0, Number.parseInt(item.id || '0', 10) || 0);
                    return ''
                        + '<article class="similar-product-card">'
                        + '<div class="similar-product-card__media-wrap">'
                        + '<a class="similar-product-card__media" href="' + url + '"><img class="similar-product-card__img" src="' + image + '" alt="' + name + '" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'/assets/img/product-placeholder.svg\';">' + (discount > 0 ? '<span class="similar-product-card__bubble' + (badgeMode === 'value' ? ' similar-product-card__bubble--value' : '') + '">' + bubbleText + '</span>' : '') + '</a>'
                        + '</div>'
                        + '<div class="similar-product-card__body">'
                        + '<p class="similar-product-card__desc">' + description + '</p>'
                        + '<h4 class="similar-product-card__name"><a href="' + url + '">' + name + '</a></h4>'
                        + '<div class="similar-product-card__rating"><span class="similar-product-card__stars" aria-label="Rating ' + reviewsAverage.toFixed(1) + ' din 5">' + starsHtml + '</span><span class="similar-product-card__reviews">(' + reviewsCount + ')</span></div>'
                        + '<div class="similar-product-card__bottom">'
                        + '<p class="similar-product-card__price">' + priceLabel + (regularPriceLabel !== '' ? '<span class="similar-product-card__old">' + regularPriceLabel + '</span>' : '') + '</p>'
                        + (outOfStock
                            ? '<span class="similar-product-card__stock-out">Stoc epuizat</span>'
                            : '<form method="post" action="/cos/adauga/' + productId + '"><button type="submit" class="similar-product-card__cart-btn" aria-label="Adaugă în coș"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 7H7"/></svg></button></form>'
                        )
                        + '</div>'
                        + '</article>';
                });
                const slides = [];
                for (let index = 0; index < cards.length; index += cols) {
                    slides.push(cards.slice(index, index + cols));
                }
                track.innerHTML = slides.map((slideCards) => {
                    return '<div class="similar-products__slide">' + slideCards.join('') + '</div>';
                }).join('');
                viewport.scrollLeft = 0;
                const hasOverflow = slides.length > 1;
                navButtons.forEach((button) => {
                    if (!(button instanceof HTMLButtonElement)) {
                        return;
                    }
                    button.disabled = !hasOverflow;
                    button.style.visibility = hasOverflow ? 'visible' : 'hidden';
                });
                buildDots();
                updateNavState();
            };

            const updateNavState = () => {
                const hasOverflow = pagesCount() > 1;
                const activePage = currentPage();
                const lastPage = Math.max(0, pagesCount() - 1);
                navButtons.forEach((button) => {
                    if (!(button instanceof HTMLButtonElement)) {
                        return;
                    }
                    const action = button.getAttribute('data-action');
                    if (!hasOverflow) {
                        button.disabled = true;
                        button.style.visibility = 'hidden';
                        return;
                    }
                    button.style.visibility = 'visible';
                    if (action === 'prev') {
                        button.disabled = activePage <= 0;
                    } else if (action === 'next') {
                        button.disabled = activePage >= lastPage;
                    }
                });
                if (dotsWrap instanceof HTMLElement) {
                    dotsWrap.querySelectorAll('[data-dot-page]').forEach((dotNode) => {
                        if (!(dotNode instanceof HTMLElement)) {
                            return;
                        }
                        const page = Number(dotNode.getAttribute('data-dot-page') || '0');
                        dotNode.classList.toggle('is-active', page === activePage);
                    });
                }
            };

            selectors.forEach((select) => {
                if (!(select instanceof HTMLSelectElement)) {
                    return;
                }
                select.addEventListener('change', render);
            });

            navButtons.forEach((button) => {
                if (!(button instanceof HTMLButtonElement)) {
                    return;
                }
                button.addEventListener('click', () => {
                    const direction = button.getAttribute('data-action') === 'prev' ? -1 : 1;
                    const step = pageWidth();
                    viewport.scrollBy({
                        left: direction * step,
                        behavior: 'smooth',
                    });
                });
            });
            if (dotsWrap instanceof HTMLElement) {
                dotsWrap.addEventListener('click', (event) => {
                    const target = event.target;
                    if (!(target instanceof Element)) {
                        return;
                    }
                    const dot = target.closest('[data-dot-page]');
                    if (!(dot instanceof HTMLElement) || !dotsWrap.contains(dot)) {
                        return;
                    }
                    const page = Number(dot.getAttribute('data-dot-page') || '0');
                    if (!Number.isFinite(page) || page < 0) {
                        return;
                    }
                    viewport.scrollTo({
                        left: page * pageWidth(),
                        behavior: 'smooth',
                    });
                });
            }
            viewport.addEventListener('scroll', updateNavState, { passive: true });
            window.addEventListener('resize', () => {
                render();
                updateNavState();
            }, { passive: true });

            render();
            updateNavState();
            root.dataset.similarInitialized = '1';
        });
    };

    const injectTemplateOldPrice = (scope = document) => {
        const toAmount = (value) => {
            const raw = String(value || '').replace(',', '.');
            const num = Number.parseFloat(raw.replace(/[^\d.-]/g, ''));
            return Number.isFinite(num) ? num : NaN;
        };
        const fallbackOldPriceText = <?= json_encode($templateOldPriceDisplay, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const fallbackCurrentAmount = toAmount(
            <?= json_encode($templateCurrentPriceDisplay, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        );
        const renderRoots = Array.from(scope.querySelectorAll('.product-template-render'));
        renderRoots.forEach((renderRoot) => {
            if (!(renderRoot instanceof HTMLElement)) {
                return;
            }
            let priceNodes = Array.from(
                renderRoot.querySelectorAll('.pdp-price, .product-price, [data-product-price], .price')
            ).filter((node) => node instanceof HTMLElement);
            if (priceNodes.length === 0 && Number.isFinite(fallbackCurrentAmount) && fallbackCurrentAmount > 0) {
                const fallbackCandidates = Array.from(
                    renderRoot.querySelectorAll('p,div,span,strong,h1,h2,h3,h4,h5,h6')
                ).filter((node) => {
                    if (!(node instanceof HTMLElement)) {
                        return false;
                    }
                    if (node.children.length > 0) {
                        return false;
                    }
                    if (node.closest('button,a,label,script,style,noscript,form')) {
                        return false;
                    }
                    const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
                    if (text === '') {
                        return false;
                    }
                    const amount = toAmount(text);
                    return Number.isFinite(amount) && Math.abs(amount - fallbackCurrentAmount) < 0.02;
                });
                priceNodes = fallbackCandidates;
            }
            if (priceNodes.length === 0) {
                return;
            }
            const oldPriceCandidates = Array.from(
                renderRoot.querySelectorAll('.pdp-price-old, .product-price-old, [data-product-old-price]')
            );
            let oldPriceText = '';
            for (const node of oldPriceCandidates) {
                const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
                if (text !== '') {
                    oldPriceText = text;
                    break;
                }
            }
            if (oldPriceText === '' && fallbackOldPriceText !== '') {
                oldPriceText = String(fallbackOldPriceText);
            }
            const oldAmount = toAmount(oldPriceText);
            if (!Number.isFinite(oldAmount) || oldAmount <= 0) {
                return;
            }

            priceNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }
                const currentText = (node.textContent || '').replace(/\s+/g, ' ').trim();
                if (currentText === '' || currentText === oldPriceText) {
                    return;
                }
                const currentAmount = toAmount(currentText);
                if (!Number.isFinite(currentAmount) || currentAmount <= 0 || currentAmount >= oldAmount) {
                    return;
                }
                if (Number.isFinite(fallbackCurrentAmount) && Math.abs(currentAmount - fallbackCurrentAmount) > 0.02) {
                    return;
                }
                let oldEl = node.querySelector('.price-old');
                if (!(oldEl instanceof HTMLElement)) {
                    oldEl = document.createElement('span');
                    oldEl.className = 'price-old';
                    oldEl.dataset.injectedOldPrice = '1';
                }
                oldEl.textContent = oldPriceText;
                node.insertBefore(oldEl, node.firstChild);
                node.classList.add('price-row');
            });
        });
    };

    initTabs(document);
    initCarousels(document);
    initReviewRatings(document);
    initReviewPagination(document);
    initSimilarProducts(document);
    injectTemplateOldPrice(document);
    if (typeof window.MutationObserver === 'function') {
        const observer = new MutationObserver(() => {
            initTabs(document);
            initCarousels(document);
            initReviewRatings(document);
            initReviewPagination(document);
            initSimilarProducts(document);
            injectTemplateOldPrice(document);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    const productBbdSelect = document.querySelector('[data-bbd-select], [data-product-bbd-select]');
    const productSingleCartForm = document.querySelector('[data-product-single-cart-form="1"]');
    const productBbdChoiceGroup = document.querySelector('[data-bbd-choice-group]');
    const bbdRequiredControls = Array.from(document.querySelectorAll('[data-product-requires-bbd="1"], [data-requires-bbd="1"]'));
    const toggleProductCartInputs = () => {
        if (!(productBbdSelect instanceof HTMLSelectElement) && !(productBbdSelect instanceof HTMLInputElement)) {
            return;
        }
        const selectedKey = String(productBbdSelect.value || '').trim();
        const enabled = selectedKey !== '';
        bbdRequiredControls.forEach((control) => {
            if (!(control instanceof HTMLButtonElement) && !(control instanceof HTMLInputElement)) {
                return;
            }
            control.disabled = !enabled;
            if (control instanceof HTMLButtonElement) {
                control.title = enabled ? '' : 'Alege oferta dorită';
            }
        });
        const productQuantityInput = document.querySelector('input[name="quantity"]');
        if (productQuantityInput instanceof HTMLInputElement && productQuantityInput.hasAttribute('data-requires-bbd')) {
            productQuantityInput.disabled = !enabled;
        }
        if (productSingleCartForm instanceof HTMLFormElement) {
            const submitButton = productSingleCartForm.querySelector('button[type="submit"]');
            if (submitButton instanceof HTMLButtonElement && submitButton.hasAttribute('data-product-requires-bbd')) {
                submitButton.disabled = !enabled;
                submitButton.title = enabled ? '' : 'Alege oferta dorită';
            }
        }
    };

    const initBbdChoiceGroup = (scope = document) => {
        const groups = Array.from(scope.querySelectorAll('[data-bbd-choice-group]'));
        groups.forEach((group) => {
            if (!(group instanceof HTMLElement) || group.dataset.bbdChoicesInit === '1') {
                return;
            }
            const hiddenInput = group.parentElement?.querySelector('[data-bbd-select], [data-product-bbd-select]');
            if (!(hiddenInput instanceof HTMLInputElement)) {
                return;
            }
            const options = Array.from(group.querySelectorAll('[data-bbd-option]'))
                .filter((node) => node instanceof HTMLButtonElement);
            if (options.length === 0) {
                return;
            }
            const syncSelectedState = () => {
                const selectedKey = String(hiddenInput.value || '').trim();
                options.forEach((option) => {
                    const key = String(option.getAttribute('data-bbd-key') || '').trim();
                    const selected = selectedKey !== '' && key === selectedKey;
                    option.classList.toggle('is-selected', selected);
                    option.setAttribute('aria-pressed', selected ? 'true' : 'false');
                });
                const selectedOption = options.find((option) => option.classList.contains('is-selected'));
                if (selectedOption instanceof HTMLButtonElement) {
                    hiddenInput.dataset.unitPrice = selectedOption.dataset.unitPrice || '';
                } else {
                    hiddenInput.dataset.unitPrice = hiddenInput.getAttribute('data-unit-price') || '';
                }
            };
            options.forEach((option) => {
                option.addEventListener('click', () => {
                    if (option.disabled) {
                        return;
                    }
                    hiddenInput.value = String(option.getAttribute('data-bbd-key') || '').trim();
                    syncSelectedState();
                    hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });
            syncSelectedState();
            group.dataset.bbdChoicesInit = '1';
        });
    };

    const applyBbdSelectedPrice = (scope = document) => {
        const bbdField = scope.querySelector('[data-bbd-select], [data-product-bbd-select]');
        if (!(bbdField instanceof HTMLSelectElement) && !(bbdField instanceof HTMLInputElement)) {
            return;
        }
        let selectedPrice = Number.NaN;
        if (bbdField instanceof HTMLSelectElement) {
            const selectedOption = bbdField.options[bbdField.selectedIndex] || null;
            if (!(selectedOption instanceof HTMLOptionElement)) {
                return;
            }
            selectedPrice = Number.parseFloat(selectedOption.dataset.unitPrice || '');
        } else {
            const key = String(bbdField.value || '').trim();
            if (key === '') {
                return;
            }
            const selectedOption = scope.querySelector('[data-bbd-option].is-selected');
            if (selectedOption instanceof HTMLButtonElement) {
                selectedPrice = Number.parseFloat(selectedOption.dataset.unitPrice || '');
            } else {
                selectedPrice = Number.parseFloat(bbdField.dataset.unitPrice || '');
            }
        }
        if (!Number.isFinite(selectedPrice) || selectedPrice <= 0) {
            return;
        }
        const safePrice = Number(selectedPrice.toFixed(2));
        const unitPriceLabel = `${safePrice.toFixed(2)} lei`;
        const initialPriceDisplayLabel = <?= json_encode($templateCurrentPriceDisplay, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        scope.querySelectorAll('.price-current').forEach((node) => {
            if (node instanceof HTMLElement) {
                node.textContent = unitPriceLabel;
            }
        });
        scope.querySelectorAll('[data-product-price], [data-product-price-label], [data-product-price-display], .product-price-display, .pdp-price-display').forEach((node) => {
            if (node instanceof HTMLElement) {
                node.textContent = unitPriceLabel;
            }
        });
        scope.querySelectorAll('.product-template-render p, .product-template-render span, .product-template-render strong, .product-template-render b, .product-template-render div').forEach((node) => {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            if (node.children.length > 0 || node.closest('button,a,label,script,style,noscript,.price-old,[data-product-cart-button="1"]')) {
                return;
            }
            const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
            if (text === '' || (text !== initialPriceDisplayLabel && node.dataset.dynamicPriceDisplay !== '1')) {
                return;
            }
            node.textContent = unitPriceLabel;
            node.dataset.dynamicPriceDisplay = '1';
        });
        scope.querySelectorAll('[data-product-cart-button="1"]').forEach((button) => {
            if (button instanceof HTMLButtonElement) {
                button.dataset.unitPrice = safePrice.toFixed(2);
            }
        });
    };

    const updateTemplateCartButtonTotal = (scope = document) => {
        const qtyInput = scope.querySelector('input[name="quantity"]');
        if (!(qtyInput instanceof HTMLInputElement)) {
            return;
        }
        const quantity = Math.max(1, Number.parseInt(qtyInput.value || '1', 10) || 1);
        const qtyAnimatedValue = qtyInput.closest('.qty-stepper')?.querySelector('[data-qty-animated-value]');
        if (qtyAnimatedValue instanceof HTMLElement) {
            qtyAnimatedValue.textContent = String(quantity);
        }
        scope.querySelectorAll('[data-product-cart-button="1"]').forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }
            const unitPrice = Math.max(0, Number.parseFloat(button.dataset.unitPrice || '0') || 0);
            const total = unitPrice * quantity;
            const target = button.querySelector('[data-cart-button-total]');
            if (target instanceof HTMLElement) {
                target.textContent = total.toFixed(2) + ' lei';
            }
        });
    };

    document.querySelectorAll('.qty-stepper').forEach((wrap) => {
        wrap.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;
            const role = target.getAttribute('data-role');
            if (!role) return;
            const input = wrap.querySelector('input[type="number"]');
            if (!(input instanceof HTMLInputElement)) return;
            const min = Math.max(1, Number(input.min || '1'));
            const current = Number(input.value || min);
            const next = role === 'qty-plus' ? current + 1 : Math.max(min, current - 1);
            input.value = String(next);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    document.querySelectorAll('input[name="quantity"]').forEach((input) => {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }
        const sync = () => updateTemplateCartButtonTotal(document);
        input.addEventListener('input', sync);
        input.addEventListener('change', sync);
    });

    let templateCartLoaderStylesInjected = false;
    const ensureTemplateCartLoaderStyles = () => {
        if (templateCartLoaderStylesInjected) {
            return;
        }
        templateCartLoaderStylesInjected = true;
        const style = document.createElement('style');
        style.textContent = `
            @keyframes templateCartBtnSpin { to { transform: rotate(360deg); } }
            .template-cart-btn-loading { pointer-events: none; }
            .template-cart-btn-spinner {
                display: inline-block;
                width: 16px;
                height: 16px;
                border: 2px solid currentColor;
                border-right-color: transparent;
                border-radius: 999px;
                animation: templateCartBtnSpin .7s linear infinite;
                vertical-align: middle;
            }
        `;
        document.head.appendChild(style);
    };
    const setTemplateCartButtonLoading = (button, loading) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        if (loading) {
            if (button.dataset.templateCartLoading === '1') {
                return;
            }
            ensureTemplateCartLoaderStyles();
            button.dataset.templateCartLoading = '1';
            button.dataset.templateCartOriginalHtml = button.innerHTML;
            button.dataset.templateCartWasDisabled = button.disabled ? '1' : '0';
            const measuredWidth = button.getBoundingClientRect().width;
            if (measuredWidth > 0) {
                button.style.minWidth = `${Math.ceil(measuredWidth)}px`;
            }
            button.innerHTML = '<span class="template-cart-btn-spinner" aria-hidden="true"></span>';
            button.classList.add('template-cart-btn-loading');
            button.disabled = true;
            return;
        }
        if (button.dataset.templateCartLoading !== '1') {
            return;
        }
        button.dataset.templateCartLoading = '0';
        button.innerHTML = button.dataset.templateCartOriginalHtml || button.innerHTML;
        button.classList.remove('template-cart-btn-loading');
        button.style.minWidth = '';
        button.disabled = button.dataset.templateCartWasDisabled === '1';
    };

    document.querySelectorAll('[data-product-cart-button="1"]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            const productId = Number.parseInt(button.dataset.productId || '0', 10);
            if (!Number.isFinite(productId) || productId <= 0) {
                return;
            }
            const quantityInput = document.querySelector('input[name="quantity"]');
            const bbdSelect = document.querySelector('[data-bbd-select], [data-product-bbd-select]');
            const bbdKey = bbdSelect instanceof HTMLSelectElement || bbdSelect instanceof HTMLInputElement
                ? String(bbdSelect.value || '').trim()
                : '';
            if ((bbdSelect instanceof HTMLSelectElement || bbdSelect instanceof HTMLInputElement) && bbdKey === '') {
                if (bbdSelect instanceof HTMLSelectElement) {
                    bbdSelect.focus();
                } else {
                    const firstOption = document.querySelector('[data-bbd-option]:not([disabled])');
                    if (firstOption instanceof HTMLButtonElement) {
                        firstOption.focus();
                    }
                }
                return;
            }
            const quantity = quantityInput instanceof HTMLInputElement
                ? Math.max(1, Number.parseInt(quantityInput.value || '1', 10) || 1)
                : 1;

            setTemplateCartButtonLoading(button, true);
            let addedToCart = false;
            try {
                const response = await fetch('/api/cart/items/' + productId + '/add', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json; charset=utf-8',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        quantity,
                        bbd_key: bbdKey,
                    }),
                });

                if (!response.ok) {
                    throw new Error('request_failed');
                }

                const payload = await response.json();
                const count = Math.max(0, Number(payload?.items_count ?? payload?.count ?? 0) || 0);
                document.querySelectorAll('[data-cart-count], [data-floating-cart-count]').forEach((badge) => {
                    if (badge instanceof HTMLElement) {
                        badge.textContent = String(count);
                    }
                });

                addedToCart = true;
                // Ask floating cart to refresh summary and open panel.
                window.dispatchEvent(new CustomEvent('floating-cart:added', {
                    detail: {
                        count,
                        message: String(payload?.message || 'Produs adăugat în coș.'),
                        open: true,
                    },
                }));
            } catch (_error) {
                // Keep user on PDP if API call fails; avoid redirecting to /cos.
            } finally {
                window.setTimeout(() => {
                    setTemplateCartButtonLoading(button, false);
                }, addedToCart ? 220 : 0);
            }
        });
    });

    initBbdChoiceGroup(document);
    applyBbdSelectedPrice(document);
    updateTemplateCartButtonTotal(document);

    productBbdSelect?.addEventListener('change', () => {
        toggleProductCartInputs();
        applyBbdSelectedPrice(document);
        updateTemplateCartButtonTotal(document);
    });
    productBbdChoiceGroup?.addEventListener('keydown', (event) => {
        if (!(event.target instanceof HTMLButtonElement) || !event.target.hasAttribute('data-bbd-option')) {
            return;
        }
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        event.preventDefault();
        event.target.click();
    });
    toggleProductCartInputs();

    const mainImage = document.getElementById('product-default-main-image');
    document.querySelectorAll('.product-default-thumb').forEach((btn) => {
        btn.addEventListener('click', () => {
            const url = btn.getAttribute('data-image-url') || '';
            if (!mainImage || url.trim() === '') return;
            mainImage.src = url;
        });
    });
})();
</script>
