<?php
$settings = is_array($settings ?? null) ? $settings : [];
$points = is_array($points ?? null) ? $points : [];
$products = is_array($products ?? null) ? $products : [];
$mannequinEnabled = (string) ($settings['mannequin_enabled'] ?? '1') === '1';
$mannequinEmpty = (string) ($settings['mannequin_empty_text'] ?? 'Nu sunt produse pentru această categorie.');
$mannequinCode = (string) ($settings['mannequin_code'] ?? '{{mannequin_section}}');
?>

<section class="panel">
    <div class="section-head" style="margin-bottom:10px;">
        <div>
            <h1>Configurare manechin</h1>
            <p style="margin:6px 0 0;color:#64748b;">
                Configurează punctele de pe manechin prin drag & drop și asociază produse direct din catalog.
            </p>
        </div>
    </div>

    <article class="panel" style="margin:12px 0;background:#f8fafc;border-color:#cbd5e1;">
        <h3 style="margin:0 0 8px;">Cod disponibil pentru template/page builder</h3>
        <p style="margin:0 0 8px;color:#64748b;">
            Inserează codul de mai jos în HTML-ul paginii pentru a afișa secțiunea manechin configurată din admin.
        </p>
        <code style="display:inline-block;padding:8px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;"><?= htmlspecialchars($mannequinCode, ENT_QUOTES) ?></code>
    </article>

    <form method="post" action="/admin/settings/mannequin" id="mannequin-settings-form">
        <div class="form-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
            <label class="field" style="grid-column:1 / -1;">
                <span style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="mannequin_enabled" value="1" <?= $mannequinEnabled ? 'checked' : '' ?>>
                    Activează secțiunea manechin
                </span>
            </label>
            <label class="field" style="grid-column:1 / -1;">
                <span>Mesaj fără produse</span>
                <input type="text" name="mannequin_empty_text" value="<?= htmlspecialchars($mannequinEmpty, ENT_QUOTES) ?>" maxlength="255">
            </label>
        </div>

        <input type="hidden" name="mannequin_points_json" id="mannequin_points_json" value="<?= htmlspecialchars((string) json_encode($points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>">

        <div class="panel" style="margin-top:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <h3 style="margin:0;">Puncte manechin</h3>
                <button type="button" class="btn btn-secondary" id="mannequin-add-point">+ Adaugă punct</button>
            </div>

            <div class="mannequin-admin-grid">
                <div class="mannequin-admin-stage-wrap">
                    <div class="mannequin-admin-stage" id="mannequin-stage">
                        <svg class="mannequin-admin-svg" viewBox="52 0 100 210" aria-hidden="true" preserveAspectRatio="xMidYMin meet">
                            <path class="mannequin-admin-fill" d="M104.265,117.959c-0.304,3.58,2.126,22.529,3.38,29.959c0.597,3.52,2.234,9.255,1.645,12.3c-0.841,4.244-1.084,9.736-0.621,12.934c0.292,1.942,1.211,10.899-0.104,14.175c-0.688,1.718-1.949,10.522-1.949,10.522c-3.285,8.294-1.431,7.886-1.431,7.886c1.017,1.248,2.759,0.098,2.759,0.098c1.327,0.846,2.246-0.201,2.246-0.201c1.139,0.943,2.467-0.116,2.467-0.116c1.431,0.743,2.758-0.627,2.758-0.627c0.822,0.414,1.023-0.109,1.023-0.109c2.466-0.158-1.376-8.05-1.376-8.05c-0.92-7.088,0.913-11.033,0.913-11.033c6.004-17.805,6.309-22.53,3.909-29.24c-0.676-1.937-0.847-2.704-0.536-3.545c0.719-1.941,0.195-9.748,1.072-12.848c1.692-5.979,3.361-21.142,4.231-28.217c1.169-9.53-4.141-22.308-4.141-22.308c-1.163-5.2,0.542-23.727,0.542-23.727c2.381,3.705,2.29,10.245,2.29,10.245c-0.378,6.859,5.541,17.342,5.541,17.342c2.844,4.332,3.921,8.442,3.921,8.747c0,1.248-0.273,4.269-0.273,4.269l0.109,2.631c0.049,0.67,0.426,2.977,0.365,4.092c-0.444,6.862,0.646,5.571,0.646,5.571c0.92,0,1.931-5.522,1.931-5.522c0,1.424-0.348,5.687,0.42,7.295c0.919,1.918,1.595-0.329,1.607-0.78c0.243-8.737,0.768-6.448,0.768-6.448c0.511,7.088,1.139,8.689,2.265,8.135c0.853-0.407,0.073-8.506,0.073-8.506c1.461,4.811,2.569,5.577,2.569,5.577c2.411,1.693,0.92-2.983,0.585-3.909c-1.784-4.92-1.839-6.625-1.839-6.625c2.229,4.421,3.909,4.257,3.909,4.257c2.174-0.694-1.9-6.954-4.287-9.953c-1.218-1.528-2.789-3.574-3.245-4.789c-0.743-2.058-1.304-8.674-1.304-8.674c-0.225-7.807-2.155-11.198-2.155-11.198c-3.3-5.282-3.921-15.135-3.921-15.135l-0.146-16.635c-1.157-11.347-9.518-11.429-9.518-11.429c-8.451-1.258-9.627-3.988-9.627-3.988c-1.79-2.576-0.767-7.514-0.767-7.514c1.485-1.208,2.058-4.415,2.058-4.415c2.466-1.891,2.345-4.658,1.206-4.628c-0.914,0.024-0.707-0.733-0.707-0.733C115.068,0.636,104.01,0,104.01,0h-1.688c0,0-11.063,0.636-9.523,13.089c0,0,0.207,0.758-0.715,0.733c-1.136-0.03-1.242,2.737,1.215,4.628c0,0,0.572,3.206,2.058,4.415c0,0,1.023,4.938-0.767,7.514c0,0-1.172,2.73-9.627,3.988c0,0-8.375,0.082-9.514,11.429l-0.158,16.635c0,0-0.609,9.853-3.922,15.135c0,0-1.921,3.392-2.143,11.198c0,0-0.563,6.616-1.303,8.674c-0.451,1.209-2.021,3.255-3.249,4.789c-2.408,2.993-6.455,9.24-4.29,9.953c0,0,1.689,0.164,3.909-4.257c0,0-0.046,1.693-1.827,6.625c-0.35,0.914-1.839,5.59,0.573,3.909c0,0,1.117-0.767,2.569-5.577c0,0-0.779,8.099,0.088,8.506c1.133,0.555,1.751-1.047,2.262-8.135c0,0,0.524-2.289,0.767,6.448c0.012,0.451,0.673,2.698,1.596,0.78c0.779-1.608,0.429-5.864,0.429-7.295c0,0,0.999,5.522,1.933,5.522c0,0,1.099,1.291,0.648-5.571c-0.073-1.121,0.32-3.422,0.369-4.092l0.106-2.631c0,0-0.274-3.014-0.274-4.269c0-0.311,1.078-4.415,3.921-8.747c0,0,5.913-10.488,5.532-17.342c0,0-0.082-6.54,2.299-10.245c0,0,1.69,18.526,0.545,23.727c0,0-5.319,12.778-4.146,22.308c0.864,7.094,2.53,22.237,4.226,28.217c0.886,3.094,0.362,10.899,1.072,12.848c0.32,0.847,0.152,1.627-0.536,3.545c-2.387,6.71-2.083,11.436,3.921,29.24c0,0,1.848,3.945,0.914,11.033c0,0-3.836,7.892-1.379,8.05c0,0,0.192,0.523,1.023,0.109c0,0,1.327,1.37,2.761,0.627c0,0,1.328,1.06,2.463,0.116c0,0,0.91,1.047,2.237,0.201c0,0,1.742,1.175,2.777-0.098c0,0,1.839,0.408-1.435-7.886c0,0-1.254-8.793-1.945-10.522c-1.318-3.275-0.387-12.251-0.106-14.175c0.453-3.216,0.21-8.695-0.618-12.934c-0.606-3.038,1.035-8.774,1.641-12.3c1.245-7.423,3.685-26.373,3.38-29.959l1.008,0.354C103.809,118.312,104.265,117.959,104.265,117.959z"></path>
                            <path class="mannequin-admin-stroke" d="M104.265,117.959c-0.304,3.58,2.126,22.529,3.38,29.959c0.597,3.52,2.234,9.255,1.645,12.3c-0.841,4.244-1.084,9.736-0.621,12.934c0.292,1.942,1.211,10.899-0.104,14.175c-0.688,1.718-1.949,10.522-1.949,10.522c-3.285,8.294-1.431,7.886-1.431,7.886c1.017,1.248,2.759,0.098,2.759,0.098c1.327,0.846,2.246-0.201,2.246-0.201c1.139,0.943,2.467-0.116,2.467-0.116c1.431,0.743,2.758-0.627,2.758-0.627c0.822,0.414,1.023-0.109,1.023-0.109c2.466-0.158-1.376-8.05-1.376-8.05c-0.92-7.088,0.913-11.033,0.913-11.033c6.004-17.805,6.309-22.53,3.909-29.24c-0.676-1.937-0.847-2.704-0.536-3.545c0.719-1.941,0.195-9.748,1.072-12.848c1.692-5.979,3.361-21.142,4.231-28.217c1.169-9.53-4.141-22.308-4.141-22.308c-1.163-5.2,0.542-23.727,0.542-23.727c2.381,3.705,2.29,10.245,2.29,10.245c-0.378,6.859,5.541,17.342,5.541,17.342c2.844,4.332,3.921,8.442,3.921,8.747c0,1.248-0.273,4.269-0.273,4.269l0.109,2.631c0.049,0.67,0.426,2.977,0.365,4.092c-0.444,6.862,0.646,5.571,0.646,5.571c0.92,0,1.931-5.522,1.931-5.522c0,1.424-0.348,5.687,0.42,7.295c0.919,1.918,1.595-0.329,1.607-0.78c0.243-8.737,0.768-6.448,0.768-6.448c0.511,7.088,1.139,8.689,2.265,8.135c0.853-0.407,0.073-8.506,0.073-8.506c1.461,4.811,2.569,5.577,2.569,5.577c2.411,1.693,0.92-2.983,0.585-3.909c-1.784-4.92-1.839-6.625-1.839-6.625c2.229,4.421,3.909,4.257,3.909,4.257c2.174-0.694-1.9-6.954-4.287-9.953c-1.218-1.528-2.789-3.574-3.245-4.789c-0.743-2.058-1.304-8.674-1.304-8.674c-0.225-7.807-2.155-11.198-2.155-11.198c-3.3-5.282-3.921-15.135-3.921-15.135l-0.146-16.635c-1.157-11.347-9.518-11.429-9.518-11.429c-8.451-1.258-9.627-3.988-9.627-3.988c-1.79-2.576-0.767-7.514-0.767-7.514c1.485-1.208,2.058-4.415,2.058-4.415c2.466-1.891,2.345-4.658,1.206-4.628c-0.914,0.024-0.707-0.733-0.707-0.733C115.068,0.636,104.01,0,104.01,0h-1.688c0,0-11.063,0.636-9.523,13.089c0,0,0.207,0.758-0.715,0.733c-1.136-0.03-1.242,2.737,1.215,4.628c0,0,0.572,3.206,2.058,4.415c0,0,1.023,4.938-0.767,7.514c0,0-1.172,2.73-9.627,3.988c0,0-8.375,0.082-9.514,11.429l-0.158,16.635c0,0-0.609,9.853-3.922,15.135c0,0-1.921,3.392-2.143,11.198c0,0-0.563,6.616-1.303,8.674c-0.451,1.209-2.021,3.255-3.249,4.789c-2.408,2.993-6.455,9.24-4.29,9.953c0,0,1.689,0.164,3.909-4.257c0,0-0.046,1.693-1.827,6.625c-0.35,0.914-1.839,5.59,0.573,3.909c0,0,1.117-0.767,2.569-5.577c0,0-0.779,8.099,0.088,8.506c1.133,0.555,1.751-1.047,2.262-8.135c0,0,0.524-2.289,0.767,6.448c0.012,0.451,0.673,2.698,1.596,0.78c0.779-1.608,0.429-5.864,0.429-7.295c0,0,0.999,5.522,1.933,5.522c0,0,1.099,1.291,0.648-5.571c-0.073-1.121,0.32-3.422,0.369-4.092l0.106-2.631c0,0-0.274-3.014-0.274-4.269c0-0.311,1.078-4.415,3.921-8.747c0,0,5.913-10.488,5.532-17.342c0,0-0.082-6.54,2.299-10.245c0,0,1.69,18.526,0.545,23.727c0,0-5.319,12.778-4.146,22.308c0.864,7.094,2.53,22.237,4.226,28.217c0.886,3.094,0.362,10.899,1.072,12.848c0.32,0.847,0.152,1.627-0.536,3.545c-2.387,6.71-2.083,11.436,3.921,29.24c0,0,1.848,3.945,0.914,11.033c0,0-3.836,7.892-1.379,8.05c0,0,0.192,0.523,1.023,0.109c0,0,1.327,1.37,2.761,0.627c0,0,1.328,1.06,2.463,0.116c0,0,0.91,1.047,2.237,0.201c0,0,1.742,1.175,2.777-0.098c0,0,1.839,0.408-1.435-7.886c0,0-1.254-8.793-1.945-10.522c-1.318-3.275-0.387-12.251-0.106-14.175c0.453-3.216,0.21-8.695-0.618-12.934c-0.606-3.038,1.035-8.774,1.641-12.3c1.245-7.423,3.685-26.373,3.38-29.959l1.008,0.354C103.809,118.312,104.265,117.959,104.265,117.959z"></path>
                        </svg>
                        <div id="mannequin-point-layer"></div>
                    </div>
                </div>

                <div>
                    <div id="mannequin-point-list" style="display:grid;gap:10px;"></div>
                </div>
            </div>
        </div>

        <div style="margin-top:14px;display:flex;justify-content:flex-end;">
            <button class="btn" type="submit">Salvează configurarea manechinului</button>
        </div>
    </form>
</section>

<style>
.mannequin-admin-grid {
    display:grid;
    grid-template-columns:300px 1fr;
    gap:16px;
    margin-top:12px;
}

.mannequin-admin-stage-wrap {
    border:1px solid #e5e7eb;
    border-radius:12px;
    background:#f8fafc;
    padding:10px;
}

.mannequin-admin-stage {
    position:relative;
    width:100%;
    aspect-ratio: 100 / 210;
    min-height:520px;
}

.mannequin-admin-svg {
    width:100%;
    height:100%;
    display:block;
}

.mannequin-admin-fill {
    fill: rgba(202, 237, 220, 0.24);
}

.mannequin-admin-stroke {
    fill:none;
    stroke:#9fd1b6;
    stroke-width:.8;
}

#mannequin-point-layer {
    position:absolute;
    inset:0;
}

.mannequin-admin-dot {
    position:absolute;
    width:14px;
    height:14px;
    border-radius:999px;
    border:2px solid #90cfad;
    background:#ecfff4;
    box-shadow:0 0 0 0 rgba(90,184,130,.5);
    cursor:grab;
    transform:translate(-50%, -50%);
    z-index:3;
}

.mannequin-admin-dot::after {
    content:'';
    position:absolute;
    inset:-6px;
    border-radius:999px;
    border:1px solid rgba(90,184,130,.4);
}

.mannequin-admin-dot.is-selected {
    border-color:#17864f;
    background:#d6ffe7;
}

.mannequin-admin-card {
    border:1px solid #e5e7eb;
    border-radius:10px;
    padding:10px;
    background:#fff;
}

.mannequin-admin-card h4 {
    margin:0 0 8px;
    font-size:14px;
}

.mannequin-admin-grid-fields {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:8px;
}

.mannequin-admin-products {
    border:1px dashed #cbd5e1;
    border-radius:8px;
    padding:8px;
    margin-top:8px;
    max-height:170px;
    overflow:auto;
    background:#f8fafc;
}

.mannequin-admin-products label {
    display:flex;
    align-items:center;
    gap:8px;
    font-size:12px;
    line-height:1.35;
    margin-bottom:6px;
}

@media (max-width: 1100px) {
    .mannequin-admin-grid {
        grid-template-columns:1fr;
    }

    .mannequin-admin-stage {
        min-height:460px;
    }
}
</style>

<script>
(() => {
    const pointsInput = document.getElementById('mannequin_points_json');
    const addBtn = document.getElementById('mannequin-add-point');
    const stage = document.getElementById('mannequin-stage');
    const layer = document.getElementById('mannequin-point-layer');
    const list = document.getElementById('mannequin-point-list');
    if (!pointsInput || !addBtn || !stage || !layer || !list) {
        return;
    }

    const products = <?= json_encode(array_values($products), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const safeSlug = (value) => String(value || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    const normalizePoint = (point, idx) => {
        const p = (point && typeof point === 'object') ? point : {};
        const id = String(p.id || `point-${idx + 1}`).trim() || `point-${idx + 1}`;
        const label = String(p.label || `Punct ${idx + 1}`).trim() || `Punct ${idx + 1}`;
        const slug = safeSlug(String(p.slug || id || label)) || `punct-${idx + 1}`;
        const x = Math.max(0, Math.min(100, Number(p.x ?? 50)));
        const y = Math.max(0, Math.min(100, Number(p.y ?? 50)));
        const rawProducts = Array.isArray(p.product_ids) ? p.product_ids : (Array.isArray(p.products) ? p.products : []);
        const selectedProducts = rawProducts
            .map((v) => Number(v))
            .filter((v) => Number.isFinite(v) && v > 0);
        const uniqueProducts = selectedProducts.filter((value, index) => selectedProducts.indexOf(value) === index);
        return { id, label, slug, x, y, product_ids: uniqueProducts };
    };

    const toStoragePoint = (point) => ({
        id: String(point.id || '').trim(),
        label: String(point.label || '').trim(),
        x: Math.max(0, Math.min(100, Number(point.x || 0))),
        y: Math.max(0, Math.min(100, Number(point.y || 0))),
        product_ids: Array.isArray(point.product_ids)
            ? point.product_ids.map((v) => Number(v)).filter((v) => Number.isFinite(v) && v > 0)
            : [],
    });

    let state = [];
    try {
        const parsed = JSON.parse(pointsInput.value || '[]');
        if (Array.isArray(parsed)) {
            state = parsed.map((point, idx) => normalizePoint(point, idx));
        }
    } catch {
        state = [];
    }

    let dragState = null;

    const persist = () => {
        pointsInput.value = JSON.stringify(state.map((point) => toStoragePoint(point)));
    };

    const renderDots = () => {
        layer.innerHTML = '';
        state.forEach((point, idx) => {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'mannequin-admin-dot';
            dot.dataset.index = String(idx);
            dot.style.left = point.x + '%';
            dot.style.top = point.y + '%';
            dot.title = point.label;
            dot.addEventListener('pointerdown', (event) => {
                dragState = { idx, pointerId: event.pointerId };
                dot.setPointerCapture(event.pointerId);
                dot.style.cursor = 'grabbing';
                event.preventDefault();
            });
            dot.addEventListener('pointermove', (event) => {
                if (!dragState || dragState.idx !== idx || dragState.pointerId !== event.pointerId) {
                    return;
                }
                const rect = stage.getBoundingClientRect();
                const x = ((event.clientX - rect.left) / rect.width) * 100;
                const y = ((event.clientY - rect.top) / rect.height) * 100;
                point.x = Math.max(0, Math.min(100, Number(x.toFixed(2))));
                point.y = Math.max(0, Math.min(100, Number(y.toFixed(2))));
                dot.style.left = point.x + '%';
                dot.style.top = point.y + '%';
                const xField = list.querySelector(`input[data-field="x"][data-index="${idx}"]`);
                const yField = list.querySelector(`input[data-field="y"][data-index="${idx}"]`);
                if (xField) xField.value = String(point.x);
                if (yField) yField.value = String(point.y);
                persist();
            });
            const stopDrag = (event) => {
                if (!dragState || dragState.idx !== idx || dragState.pointerId !== event.pointerId) {
                    return;
                }
                dragState = null;
                dot.style.cursor = 'grab';
                try {
                    dot.releasePointerCapture(event.pointerId);
                } catch {}
            };
            dot.addEventListener('pointerup', stopDrag);
            dot.addEventListener('pointercancel', stopDrag);
            layer.appendChild(dot);
        });
    };

    const renderList = () => {
        list.innerHTML = '';
        state.forEach((point, idx) => {
            const card = document.createElement('article');
            card.className = 'mannequin-admin-card';
            card.innerHTML = `
                <h4>Punct #${idx + 1}</h4>
                <div class="mannequin-admin-grid-fields">
                    <label class="field"><span>ID</span><input type="text" data-field="id" data-index="${idx}" value="${point.id}"></label>
                    <label class="field"><span>Slug</span><input type="text" data-field="slug" data-index="${idx}" value="${point.slug}"></label>
                    <label class="field" style="grid-column:1 / -1;"><span>Etichetă</span><input type="text" data-field="label" data-index="${idx}" value="${point.label}"></label>
                    <label class="field"><span>X (%)</span><input type="number" min="0" max="100" step="0.1" data-field="x" data-index="${idx}" value="${point.x}"></label>
                    <label class="field"><span>Y (%)</span><input type="number" min="0" max="100" step="0.1" data-field="y" data-index="${idx}" value="${point.y}"></label>
                </div>
                <div class="mannequin-admin-products" data-products-wrap="${idx}"></div>
                <div style="margin-top:8px;display:flex;justify-content:flex-end;">
                    <button type="button" class="btn btn-secondary" data-action="remove-point" data-index="${idx}">Șterge punct</button>
                </div>
            `;

            const wrap = card.querySelector(`[data-products-wrap="${idx}"]`);
            if (wrap) {
                if (products.length === 0) {
                    wrap.innerHTML = '<small style="color:#64748b;">Nu există produse active.</small>';
                } else {
                    wrap.innerHTML = products.map((product) => {
                        const productId = Number(product.id || 0);
                        const checked = point.product_ids.includes(productId) ? 'checked' : '';
                        const title = String(product.name || 'Produs');
                        const price = String(product.price_display || product.price || '');
                        return `
                            <label>
                                <input type="checkbox" data-field="product" data-index="${idx}" value="${productId}" ${checked}>
                                <span>${title} ${price !== '' ? `- ${price} lei` : ''}</span>
                            </label>
                        `;
                    }).join('');
                }
            }
            list.appendChild(card);
        });

        list.querySelectorAll('input[data-field]').forEach((input) => {
            input.addEventListener('input', () => {
                const idx = Number(input.dataset.index || -1);
                if (!Number.isInteger(idx) || idx < 0 || idx >= state.length) {
                    return;
                }
                const field = String(input.dataset.field || '');
                const point = state[idx];
                if (!point) {
                    return;
                }
                if (field === 'id' || field === 'slug' || field === 'label') {
                    const value = input.value.trim();
                    point[field] = value;
                } else if (field === 'x' || field === 'y') {
                    const numeric = Number(input.value);
                    point[field] = Math.max(0, Math.min(100, Number.isFinite(numeric) ? numeric : 0));
                    renderDots();
                } else if (field === 'product') {
                    const productId = Number(input.value || 0);
                    if (!Number.isFinite(productId) || productId <= 0) {
                        return;
                    }
                    if (input.checked) {
                        if (!point.product_ids.includes(productId)) {
                            point.product_ids.push(productId);
                        }
                    } else {
                        point.product_ids = point.product_ids.filter((id) => id !== productId);
                    }
                }
                persist();
            });
            if (input.type === 'checkbox') {
                input.addEventListener('change', () => input.dispatchEvent(new Event('input', { bubbles: true })));
            }
        });

        list.querySelectorAll('[data-action="remove-point"]').forEach((button) => {
            button.addEventListener('click', () => {
                const idx = Number(button.dataset.index || -1);
                if (!Number.isInteger(idx) || idx < 0 || idx >= state.length) {
                    return;
                }
                state.splice(idx, 1);
                persist();
                renderDots();
                renderList();
            });
        });
    };

    addBtn.addEventListener('click', () => {
        const idx = state.length;
        state.push(normalizePoint({
            id: `point-${idx + 1}`,
            slug: `punct-${idx + 1}`,
            label: `Punct ${idx + 1}`,
            x: 50,
            y: 50,
            product_ids: [],
        }, idx));
        persist();
        renderDots();
        renderList();
    });

    persist();
    renderDots();
    renderList();
})();
</script>
