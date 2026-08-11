(() => {
    const path = window.location.pathname || "/";
    if (path.startsWith("/admin")) {
        return;
    }

    const cfg = window.floatingCartConfig || {};
    const toNumber = (value, fallback) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : fallback;
    };
    const toBool01 = (value, fallback) => {
        if (value === undefined || value === null || value === "") {
            return fallback;
        }
        const normalized = String(value).trim().toLowerCase();
        if (normalized === "1" || normalized === "true" || normalized === "yes" || normalized === "on") {
            return true;
        }
        if (normalized === "0" || normalized === "false" || normalized === "no" || normalized === "off") {
            return false;
        }
        const num = Number(value);
        if (Number.isFinite(num)) {
            return num === 1;
        }
        return fallback;
    };
    const normalizeHex = (value, fallback) => {
        const raw = String(value || "").trim();
        return /^#[0-9a-fA-F]{6}$/.test(raw) ? raw.toLowerCase() : fallback;
    };
    const normalizeCheckoutLabel = (value) => {
        const label = String(value || "").trim();
        if (label === "") {
            return "Finalizează comanda";
        }
        return /^checkout$/i.test(label) ? "Finalizează comanda" : label;
    };
    const parseTriggerIcon = (value) => {
        const raw = String(value ?? "").trim();
        if (raw === "") {
            return "";
        }
        if (raw.includes("<svg")) {
            return raw;
        }
        return raw
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#39;");
    };
    const config = {
        enabled: toBool01(cfg.enabled, false),
        label: String(cfg.label || "Coș"),
        position: String(cfg.position || "right"),
        panelTitle: String(cfg.panelTitle || "Coșul tău"),
        showSku: toBool01(cfg.showSku, true),
        showPrice: toBool01(cfg.showPrice, false),
        showSubtotal: toBool01(cfg.showSubtotal, true),
        showDiscount: toBool01(cfg.showDiscount, true),
        showPointsDiscount: toBool01(cfg.showPointsDiscount, true),
        showShipping: toBool01(cfg.showShipping, true),
        showVat: toBool01(cfg.showVat, true),
        showEstimatedEarnedPoints: toBool01(cfg.showEstimatedEarnedPoints, true),
        pointsPosition: String(cfg.pointsPosition || "before_total"),
        pointsTextTemplate: String(cfg.pointsTextTemplate || "Primești {points} puncte la această comandă."),
        buttonBg: normalizeHex(cfg.buttonBg || "#0f766e", "#0f766e"),
        buttonText: normalizeHex(cfg.buttonText || "#ffffff", "#ffffff"),
        buttonCounterBg: normalizeHex(cfg.buttonCounterBg || "#ffffff", "#ffffff"),
        buttonCounterText: normalizeHex(cfg.buttonCounterText || "#0f766e", "#0f766e"),
        openOnAdd: toBool01(cfg.openOnAdd, true),
        showOnDesktop: toBool01(cfg.showOnDesktop, true),
        showOnMobile: toBool01(cfg.showOnMobile, true),
        panelWidth: Math.min(560, Math.max(320, toNumber(cfg.panelWidth || 420, 420))),
        offsetX: Math.max(0, toNumber(cfg.offsetX || 18, 18)),
        offsetY: Math.max(0, toNumber(cfg.offsetY || 18, 18)),
        viewCartLabel: String(cfg.viewCartLabel || "Vezi coșul"),
        checkoutLabel: normalizeCheckoutLabel(cfg.checkoutLabel || "Finalizează comanda"),
        continueShoppingLabel: String(cfg.continueShoppingLabel || "Continuă cumpărăturile"),
        emptyCtaLabel: String(cfg.emptyCtaLabel || "Vezi produsele"),
        triggerIcon: parseTriggerIcon(cfg.triggerIconSvg || cfg.triggerIcon || ""),
        showImages: toBool01(cfg.showImages, true),
        showViewCartButton: toBool01(cfg.showViewCartButton, true),
        showCheckoutButton: toBool01(cfg.showCheckoutButton, true),
        quantityControlStyle: String(cfg.quantityControlStyle || "default"),
        quantityControlEnabled: toBool01(cfg.quantityControlEnabled, false),
    };
    if (!config.enabled) {
        return;
    }
    const isMobile = window.matchMedia("(max-width: 768px)").matches;
    if ((isMobile && !config.showOnMobile) || (!isMobile && !config.showOnDesktop)) {
        return;
    }

    const useStepperQtyControls = config.quantityControlStyle === "stepper" && config.quantityControlEnabled;
    const state = {
        open: false,
        loading: false,
        summary: null,
    };

    const formatMoney = (value) => {
        const amount = Number(value || 0);
        const safeAmount = Number.isFinite(amount) ? amount : 0;
        return `${safeAmount.toFixed(2)} lei`;
    };
    const escapeHtml = (value) =>
        String(value ?? "")
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#39;");

    const root = document.createElement("div");
    root.className = "floating-cart floating-cart--" + (config.position === "left" ? "left" : "right");
    root.innerHTML = `
        <button class="floating-cart__trigger" type="button" aria-label="Deschide coșul flotant">
            <span class="floating-cart__icon" aria-hidden="true">
                ${config.triggerIcon || '<svg viewBox="0 0 24 24"><path d="M3 4h2l1.7 8.1a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 1.9-1.4l1.5-5.2H7.4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path><circle cx="8.7" cy="19" r="1.4" fill="none" stroke="currentColor" stroke-width="1.7"></circle><circle cx="17.5" cy="19" r="1.4" fill="none" stroke="currentColor" stroke-width="1.7"></circle></svg>'}
            </span>
            <span class="floating-cart__label">${escapeHtml(config.label)}</span>
            <span class="floating-cart__count">0</span>
        </button>
        <div class="floating-cart__overlay" hidden></div>
        <aside class="floating-cart__panel floating-cart__panel--${config.position === "left" ? "left" : "right"}" aria-hidden="true">
            <header class="floating-cart__head">
                <div class="floating-cart__title-wrap">
                    <span class="floating-cart__head-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M6.5 5.5h11v13h-11z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"></path><path d="M9 8.5V7a3 3 0 0 1 6 0v1.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"></path></svg>
                    </span>
                    <h3>${escapeHtml(config.panelTitle)}</h3>
                    <span class="floating-cart__head-count">0</span>
                </div>
                <button class="floating-cart__close" type="button" aria-label="Închide coș">×</button>
            </header>
            <div class="floating-cart__body">
                <p class="floating-cart__empty">Se încarcă...</p>
            </div>
            <section class="floating-cart__coupon" hidden>
                <form class="floating-cart__coupon-form" data-coupon-form>
                    <label class="floating-cart__coupon-label" for="floating-cart-coupon-input">Cod cupon</label>
                    <div class="floating-cart__coupon-row">
                        <input id="floating-cart-coupon-input" class="floating-cart__coupon-input" type="text" name="coupon_code" placeholder="ex: TRANSPORT0" autocomplete="off">
                        <button class="btn floating-cart__coupon-apply" type="submit">Aplică</button>
                    </div>
                </form>
                <form class="floating-cart__coupon-clear-form" data-coupon-clear-form hidden>
                    <p class="floating-cart__coupon-active">Cupon activ: <strong data-coupon-active-code></strong></p>
                    <button class="btn btn-secondary floating-cart__coupon-clear" type="submit">Elimină cupon</button>
                </form>
            </section>
            <footer class="floating-cart__footer" hidden>
                <div class="floating-cart__totals"></div>
                <div class="floating-cart__actions">
                    <a class="btn btn-secondary floating-cart__view-cart" href="/cos">${escapeHtml(config.viewCartLabel)}</a>
                    <a class="btn floating-cart__checkout" href="/checkout">${escapeHtml(config.checkoutLabel)}</a>
                </div>
                <a class="floating-cart__continue-link" href="#">${escapeHtml(config.continueShoppingLabel)}</a>
            </footer>
        </aside>
        <div class="floating-cart__toast" role="status" aria-live="polite" hidden></div>
    `;

    document.body.appendChild(root);

    const trigger = root.querySelector(".floating-cart__trigger");
    const countEl = root.querySelector(".floating-cart__count");
    const overlay = root.querySelector(".floating-cart__overlay");
    const panel = root.querySelector(".floating-cart__panel");
    const closeBtn = root.querySelector(".floating-cart__close");
    const body = root.querySelector(".floating-cart__body");
    const footer = root.querySelector(".floating-cart__footer");
    const totalsEl = root.querySelector(".floating-cart__totals");
    const couponWrap = root.querySelector(".floating-cart__coupon");
    const couponForm = root.querySelector("[data-coupon-form]");
    const couponClearForm = root.querySelector("[data-coupon-clear-form]");
    const couponInput = root.querySelector("#floating-cart-coupon-input");
    const couponActiveCode = root.querySelector("[data-coupon-active-code]");
    const actionsEl = root.querySelector(".floating-cart__actions");
    const headCountEl = root.querySelector(".floating-cart__head-count");
    const viewCartLink = root.querySelector(".floating-cart__view-cart");
    const checkoutLink = root.querySelector(".floating-cart__checkout");
    const continueShoppingLink = root.querySelector(".floating-cart__continue-link");
    const toast = root.querySelector(".floating-cart__toast");

    if (!trigger || !countEl || !overlay || !panel || !closeBtn || !body || !footer || !totalsEl || !actionsEl || !headCountEl || !viewCartLink || !checkoutLink || !continueShoppingLink || !toast || !couponWrap || !couponForm || !couponClearForm || !couponInput || !couponActiveCode) {
        return;
    }

    root.style.setProperty("--fc-button-bg", config.buttonBg);
    root.style.setProperty("--fc-button-text", config.buttonText);
    root.style.setProperty("--fc-counter-bg", config.buttonCounterBg);
    root.style.setProperty("--fc-counter-text", config.buttonCounterText);
    const mobileMedia = window.matchMedia("(max-width: 768px)");
    const mobileSideGap = Math.min(12, Math.max(6, config.offsetX));
    const applyFloatingRootOffsets = () => {
        const mobile = mobileMedia.matches;
        if (config.position === "left") {
            root.style.left = mobile ? `${mobileSideGap}px` : `${config.offsetX}px`;
            root.style.right = "auto";
        } else {
            root.style.right = mobile ? `${mobileSideGap}px` : `${config.offsetX}px`;
            root.style.left = "auto";
        }
        root.style.bottom = `${config.offsetY}px`;
    };
    applyFloatingRootOffsets();
    if (typeof mobileMedia.addEventListener === "function") {
        mobileMedia.addEventListener("change", applyFloatingRootOffsets);
    } else if (typeof mobileMedia.addListener === "function") {
        mobileMedia.addListener(applyFloatingRootOffsets);
    }
    const applyPanelWidth = () => {
        if (mobileMedia.matches) {
            panel.style.width = "100vw";
            panel.style.maxWidth = "100vw";
            return;
        }
        panel.style.width = `${config.panelWidth}px`;
        panel.style.maxWidth = "";
    };
    applyPanelWidth();
    if (typeof mobileMedia.addEventListener === "function") {
        mobileMedia.addEventListener("change", applyPanelWidth);
    } else if (typeof mobileMedia.addListener === "function") {
        mobileMedia.addListener(applyPanelWidth);
    }

    const setOpen = (open) => {
        if (state.open === open) {
            return;
        }
        state.open = open;
        panel.setAttribute("aria-hidden", open ? "false" : "true");
        panel.classList.toggle("is-open", open);
        overlay.hidden = !open;
    };

    let loaderStylesInjected = false;
    const ensureLoaderStyles = () => {
        if (loaderStylesInjected) {
            return;
        }
        loaderStylesInjected = true;
        const style = document.createElement("style");
        style.textContent = `
            @keyframes fcBtnSpin { to { transform: rotate(360deg); } }
            .fc-btn-loading {
                pointer-events: none;
            }
            .fc-btn-loading .fc-btn-spinner {
                display: inline-block;
                width: 16px;
                height: 16px;
                border: 2px solid currentColor;
                border-right-color: transparent;
                border-radius: 999px;
                animation: fcBtnSpin .7s linear infinite;
                vertical-align: middle;
            }
        `;
        document.head.appendChild(style);
    };
    const setSubmitterLoading = (submitter, loading) => {
        if (!(submitter instanceof HTMLElement)) {
            return;
        }
        if (loading) {
            if (submitter.dataset.fcLoading === "1") {
                return;
            }
            ensureLoaderStyles();
            submitter.dataset.fcLoading = "1";
            submitter.dataset.fcWasDisabled = submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement
                ? (submitter.disabled ? "1" : "0")
                : "0";
            const measuredWidth = submitter.getBoundingClientRect().width;
            if (measuredWidth > 0) {
                submitter.style.minWidth = `${Math.ceil(measuredWidth)}px`;
            }
            if (submitter instanceof HTMLButtonElement) {
                submitter.dataset.fcOriginalHtml = submitter.innerHTML;
                submitter.classList.add("fc-btn-loading");
                submitter.innerHTML = '<span class="fc-btn-spinner" aria-hidden="true"></span>';
            } else if (submitter instanceof HTMLInputElement && submitter.type === "submit") {
                submitter.dataset.fcOriginalValue = submitter.value;
                submitter.value = "...";
            }
            if (submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement) {
                submitter.disabled = true;
            }
            return;
        }
        if (submitter.dataset.fcLoading !== "1") {
            return;
        }
        submitter.dataset.fcLoading = "0";
        if (submitter instanceof HTMLButtonElement) {
            submitter.innerHTML = submitter.dataset.fcOriginalHtml || submitter.innerHTML;
            submitter.classList.remove("fc-btn-loading");
        } else if (submitter instanceof HTMLInputElement && submitter.type === "submit") {
            submitter.value = submitter.dataset.fcOriginalValue || submitter.value;
        }
        if (submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement) {
            submitter.disabled = submitter.dataset.fcWasDisabled === "1";
        }
        submitter.style.minWidth = "";
    };
    const waitForPanelOpenTransition = () =>
        new Promise((resolve) => {
            if (!config.openOnAdd) {
                resolve();
                return;
            }
            let settled = false;
            const finish = () => {
                if (settled) {
                    return;
                }
                settled = true;
                panel.removeEventListener("transitionend", onTransitionEnd);
                resolve();
            };
            const onTransitionEnd = (event) => {
                if (event.target === panel && event.propertyName === "transform") {
                    finish();
                }
            };
            panel.addEventListener("transitionend", onTransitionEnd);
            window.setTimeout(finish, 260);
        });

    const notify = (message) => {
        if (!message) {
            return;
        }
        toast.textContent = String(message);
        toast.hidden = false;
        toast.classList.add("is-visible");
        window.setTimeout(() => {
            toast.classList.remove("is-visible");
            window.setTimeout(() => {
                toast.hidden = true;
            }, 220);
        }, 1700);
    };

    const emitCartCount = (count) => {
        const safeCount = Math.max(0, Number.parseInt(String(count || 0), 10) || 0);
        window.dispatchEvent(new CustomEvent("cart:count-updated", {
            detail: {
                items_count: safeCount,
                count: safeCount,
                source: "floating-cart",
            },
        }));
    };

    const render = () => {
        const summary = state.summary;
        if (!summary || !Array.isArray(summary.lines)) {
            body.innerHTML = `<p class="floating-cart__empty">Coș indisponibil momentan.</p>`;
            footer.hidden = true;
            return;
        }

        const itemsCount = Number(summary.items_count || 0);
        countEl.textContent = String(itemsCount);
        headCountEl.textContent = String(itemsCount);
        trigger.classList.toggle("has-items", itemsCount > 0);
        emitCartCount(itemsCount);

        viewCartLink.href = String(summary.cart_url || "/cos");
        checkoutLink.href = String(summary.checkout_url || "/checkout");
        viewCartLink.hidden = !config.showViewCartButton;
        checkoutLink.hidden = !config.showCheckoutButton;
        actionsEl.hidden = !config.showViewCartButton && !config.showCheckoutButton;
        continueShoppingLink.href = "#";

        trigger.hidden = false;

        if (summary.lines.length === 0) {
            body.innerHTML = `
                <div class="floating-cart__empty-wrap">
                    <div class="floating-cart__empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M6.5 5.5h11v13h-11z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"></path><path d="M9 8.5V7a3 3 0 0 1 6 0v1.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"></path></svg>
                    </div>
                    <p class="floating-cart__empty">Coșul tău este gol</p>
                    <a class="floating-cart__empty-cta" href="${escapeHtml(summary.continue_shopping_url || "/magazin")}">${escapeHtml(config.emptyCtaLabel)}</a>
                </div>
            `;
            totalsEl.innerHTML = "";
            couponWrap.hidden = true;
            footer.hidden = true;
            return;
        }

        body.innerHTML = `
            <ul class="floating-cart__list">
                ${summary.lines
                    .map((line) => {
                        const itemKey = String(line.item_key || line.id || "");
                        const safeName = escapeHtml(line.name || "Produs");
                        const safeUrl = escapeHtml(line.url || "#");
                        const safeImage = escapeHtml(line.image_url || "/assets/img/product-placeholder.svg");
                        const safeDescription = escapeHtml(line.short_description || "");
                        const safeBbdLabel = escapeHtml(line.bbd_label || "");
                        const safeSku = escapeHtml(line.sku || "");
                        const qtyDisplay = Number(line.quantity || 1);
                        const lineTotal = Number(line.line_total || 0);
                        const lineCouponDiscount = Math.max(0, Number(line.coupon_discount || 0));
                        const lineTotalAfterCoupon = Math.max(
                            0,
                            Number(line.line_total_after_coupon ?? (lineTotal - lineCouponDiscount))
                        );
                        const hasLineCouponDiscount = lineCouponDiscount > 0.0001;
                        const linePriceHtml = hasLineCouponDiscount
                            ? `
                                    <span class="floating-cart__item-price-old">${formatMoney(lineTotal)}</span>
                                    <strong>${formatMoney(lineTotalAfterCoupon)}</strong>
                                    <small class="floating-cart__item-price-discount">Reducere cupon: -${formatMoney(lineCouponDiscount)}</small>
                            `
                            : `<strong>${formatMoney(lineTotal)}</strong>`;
                        const quantityHtml = useStepperQtyControls
                            ? `
                                    <div class="floating-cart__qty floating-cart__qty--stepper">
                                        <button type="button" data-action="decrease">−</button>
                                        <input type="text" value="${qtyDisplay}" readonly aria-label="Cantitate">
                                        <button type="button" data-action="increase">+</button>
                                    </div>
                            `
                            : `
                                    <div class="floating-cart__qty">
                                        <button type="button" data-action="decrease">−</button>
                                        <span>${qtyDisplay}</span>
                                        <button type="button" data-action="increase">+</button>
                                    </div>
                            `;
                        return `
                            <li class="floating-cart__item${config.showImages ? "" : " floating-cart__item--no-image"}" data-item-key="${escapeHtml(itemKey)}">
                                ${config.showImages ? `<img src="${safeImage}" alt="${safeName}">` : ""}
                                <div class="floating-cart__meta">
                                    <a href="${safeUrl}">${safeName}</a>
                                    ${safeDescription ? `<small class="floating-cart__desc">${safeDescription}</small>` : ""}
                                    ${safeBbdLabel ? `<small class="floating-cart__desc">Ofertă: ${safeBbdLabel}</small>` : ""}
                                    ${config.showSku && safeSku ? `<small class="floating-cart__sku">SKU: ${safeSku}</small>` : ""}
                                    ${quantityHtml}
                                </div>
                                <div class="floating-cart__item-side">
                                    ${linePriceHtml}
                                    <button type="button" class="floating-cart__remove" data-action="remove" aria-label="Elimină produs">
                                        <svg class="floating-cart__trash-icon" viewBox="0 0 24 24"><path d="M9 4.5h6m-8 2h10m-9 0v12a1.5 1.5 0 0 0 1.5 1.5h5A1.5 1.5 0 0 0 16.5 18v-12m-5 3.5v6m3-6v6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                                    </button>
                                </div>
                            </li>
                        `;
                    })
                    .join("")}
            </ul>
        `;

        const shippingAmount = Number(summary.shipping || 0);
        const shippingRemaining = Number(summary.shipping_remaining_for_free || 0);
        const shippingFreeThreshold = Number(summary.shipping_free_threshold || 0);
        const hasFreeShippingByThreshold = Number.isFinite(shippingRemaining)
            && shippingRemaining <= 0
            && Number.isFinite(shippingFreeThreshold)
            && shippingFreeThreshold > 0;
        const shippingLabel = (() => {
            if (Number.isFinite(shippingAmount) && shippingAmount > 0) {
                return formatMoney(shippingAmount);
            }
            if (hasFreeShippingByThreshold) {
                return "Gratuit";
            }
            return "Se va calcula la Checkout";
        })();
        const shippingLabelHtml = shippingLabel === "Se va calcula la Checkout"
            ? `<span class="floating-cart__shipping-estimate">${escapeHtml(shippingLabel)}</span>`
            : escapeHtml(shippingLabel);

        const rows = [];
        if (config.showSubtotal) {
            rows.push(`<p class="floating-cart__subtotal"><span>Subtotal produse</span><strong>${formatMoney(summary.subtotal_without_vat ?? summary.subtotal ?? 0)}</strong></p>`);
        }
        if (config.showVat) {
            rows.push(`<p class="floating-cart__line floating-cart__line--vat"><span>TVA</span><strong>${formatMoney(summary.vat_total || 0)}</strong></p>`);
        }
        if (config.showShipping) {
            rows.push(`<p class="floating-cart__line floating-cart__line--shipping"><span>Transport</span><strong>${shippingLabelHtml}</strong></p>`);
        }
        // Liniile de reducere apar doar când chiar există o reducere; „-0.00 lei"
        // nu spune nimic și încarcă degeaba sumarul. Setarea rămâne comutatorul
        // principal — dacă e oprită, linia nu apare nici când există reducere.
        const discountValue = Number(summary.discount || 0);
        if (config.showDiscount && discountValue > 0) {
            rows.push(`<p class="floating-cart__line"><span>Reducere</span><strong>-${formatMoney(discountValue)}</strong></p>`);
        }
        const pointsDiscountValue = Number(summary.points_discount || 0);
        if (config.showPointsDiscount && pointsDiscountValue > 0) {
            rows.push(`<p class="floating-cart__line"><span>Reducere puncte</span><strong>-${formatMoney(pointsDiscountValue)}</strong></p>`);
        }
        rows.push(`<p class="floating-cart__total"><span>Total</span><strong>${formatMoney(summary.total || 0)}</strong></p>`);

        if (Number.isFinite(shippingRemaining) && shippingRemaining > 0) {
            rows.push(
                `<p class="floating-cart__shipping-note">🌿 Mai adaugă <strong>${formatMoney(shippingRemaining)}</strong> pentru transport gratuit</p>`
            );
        } else if (hasFreeShippingByThreshold) {
            rows.push(`<p class="floating-cart__shipping-note floating-cart__shipping-note--free">Transport gratuit activ</p>`);
        }
        if (config.showEstimatedEarnedPoints && Number(summary.estimated_earned_points || 0) > 0) {
            const pointsText = config.pointsTextTemplate.replaceAll(
                "{points}",
                String(Number(summary.estimated_earned_points || 0))
            );
            rows.push(
                `<p class="floating-cart__points-earned">${escapeHtml(pointsText)}</p>`
            );
        }
        totalsEl.innerHTML = rows.join("");
        couponWrap.hidden = false;
        const couponCode = String(summary.coupon?.code || "").trim();
        const couponOnlySelectedProducts = summary.coupon?.applies_only_selected_products === true;
        couponInput.value = couponCode;
        const hasCoupon = couponCode !== "";
        couponForm.hidden = hasCoupon;
        couponClearForm.hidden = !hasCoupon;
        couponActiveCode.textContent = hasCoupon && couponOnlySelectedProducts
            ? `${couponCode} (doar produse selectate)`
            : couponCode;
        footer.hidden = false;
    };

    const api = async (url, method = "GET", payload = null) => {
        const options = { method, headers: {} };
        if (payload !== null) {
            options.headers["Content-Type"] = "application/json";
            options.body = JSON.stringify(payload);
        }
        const response = await fetch(url, options);
        let data = null;
        try {
            data = await response.json();
        } catch (_) {
            data = null;
        }
        if (!response.ok || !data || data.ok === false) {
            throw new Error(data?.message || "Nu am putut actualiza coșul.");
        }
        return data;
    };

    const loadSummary = async () => {
        if (state.loading) {
            return;
        }
        state.loading = true;
        try {
            state.summary = await api("/api/cart/summary");
            render();
        } catch (_) {
            state.summary = null;
            render();
        } finally {
            state.loading = false;
        }
    };

    const updateItem = async (itemKey, action) => {
        const safeItemKey = String(itemKey || "").trim();
        if (safeItemKey === "" || !state.summary) {
            return;
        }
        const line = state.summary.lines.find((item) => String(item.item_key || item.id || "") === safeItemKey);
        if (!line) {
            return;
        }

        let endpoint = `/api/cart/items/${encodeURIComponent(safeItemKey)}/set`;
        let payload = { quantity: Number(line.quantity || 1) };
        if (action === "increase") {
            payload.quantity += 1;
        } else if (action === "decrease") {
            payload.quantity -= 1;
        } else if (action === "remove") {
            endpoint = `/api/cart/items/${encodeURIComponent(safeItemKey)}/remove`;
            payload = null;
        }

        try {
            state.summary = await api(endpoint, "POST", payload);
            render();
            if (state.summary.message) {
                notify(state.summary.message);
            }
        } catch (error) {
            notify(error.message);
        }
    };

    const applyCoupon = async (code) => {
        const safeCode = String(code || "").trim();
        if (safeCode === "") {
            notify("Introdu un cod de cupon.");
            return;
        }
        const response = await api("/api/cart/coupon/apply", "POST", { coupon_code: safeCode });
        state.summary = response;
        render();
        notify(response.message || "Cupon aplicat.");
    };

    const clearCoupon = async () => {
        const response = await api("/api/cart/coupon/clear", "POST");
        state.summary = response;
        render();
        notify(response.message || "Cupon eliminat.");
    };

    trigger.addEventListener("click", () => {
        setOpen(!state.open);
    });
    closeBtn.addEventListener("click", () => setOpen(false));
    overlay.addEventListener("click", () => setOpen(false));
    continueShoppingLink.addEventListener("click", (event) => {
        event.preventDefault();
        setOpen(false);
    });

    body.addEventListener("click", (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const actionTarget = target.closest("[data-action]");
        if (!(actionTarget instanceof HTMLElement)) {
            return;
        }
        const action = actionTarget.getAttribute("data-action");
        if (!action) {
            return;
        }
        const item = actionTarget.closest(".floating-cart__item");
        if (!(item instanceof HTMLElement)) {
            return;
        }
        const itemKey = String(item.getAttribute("data-item-key") || "").trim();
        updateItem(itemKey, action);
    });

    couponForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const submitter = couponForm.querySelector('button[type="submit"]');
        setSubmitterLoading(submitter, true);
        try {
            await applyCoupon(couponInput.value);
        } catch (error) {
            notify(error.message || "Nu am putut aplica cuponul.");
        } finally {
            setSubmitterLoading(submitter, false);
        }
    });

    couponClearForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const submitter = couponClearForm.querySelector('button[type="submit"]');
        setSubmitterLoading(submitter, true);
        try {
            await clearCoupon();
        } catch (error) {
            notify(error.message || "Nu am putut elimina cuponul.");
        } finally {
            setSubmitterLoading(submitter, false);
        }
    });

    document.addEventListener("submit", async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        const submitter = event.submitter instanceof HTMLElement
            ? event.submitter
            : form.querySelector('button[type="submit"], input[type="submit"]');
        const actionAttr = form.getAttribute("action") || "";
        const actionPath = (() => {
            try {
                return new URL(actionAttr, window.location.origin).pathname;
            } catch (_) {
                return actionAttr;
            }
        })();
        const match = actionPath.match(/^\/cos\/adauga\/(\d+)$/);
        if (!match) {
            return;
        }

        event.preventDefault();
        setSubmitterLoading(submitter, true);
        const id = Number(match[1] || 0);
        const quantityInput = form.querySelector('input[name="quantity"]');
        const quantity = quantityInput instanceof HTMLInputElement ? Number(quantityInput.value || "1") : 1;
        const bbdInput = form.querySelector('select[name="bbd_key"], input[name="bbd_key"]');
        const bbdKey = bbdInput instanceof HTMLSelectElement || bbdInput instanceof HTMLInputElement
            ? String(bbdInput.value || "").trim()
            : "";

        try {
            state.summary = await api(`/api/cart/items/${id}/add`, "POST", {
                quantity: Number.isFinite(quantity) && quantity > 0 ? quantity : 1,
                bbd_key: bbdKey,
            });
            render();
            if (config.openOnAdd) {
                setOpen(true);
                await waitForPanelOpenTransition();
            }
            setSubmitterLoading(submitter, false);
        } catch (_) {
            setSubmitterLoading(submitter, false);
            form.submit();
        }
    });

    window.addEventListener("floating-cart:added", async (event) => {
        const detail = (event && typeof event === "object" && "detail" in event) ? event.detail : null;
        const shouldOpen = !!(detail && typeof detail === "object" && detail.open === true) || config.openOnAdd;
        try {
            state.summary = await api("/api/cart/summary");
            render();
            if (shouldOpen) {
                setOpen(true);
            }
        } catch (_) {
            if (shouldOpen) {
                setOpen(true);
            }
        }
    });

    loadSummary();
})();
