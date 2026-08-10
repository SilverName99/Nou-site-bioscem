<?php
$mannequin = is_array($widget ?? null)
    ? $widget
    : (is_array($mannequin ?? null) ? $mannequin : []);
$points = is_array($mannequin['points'] ?? null) ? $mannequin['points'] : [];
$title = trim((string) ($mannequin['title'] ?? 'Recomandări pe zone'));
$emptyText = trim((string) ($mannequin['emptyText'] ?? 'Nu sunt produse pentru această categorie.'));
$emptyText = $emptyText !== '' ? $emptyText : 'Nu sunt produse pentru această categorie.';
$payload = [
    'title' => $title,
    'emptyText' => $emptyText,
    'points' => $points,
];
$json = (string) json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
);
?>
<section class="mannequin-widget" data-mannequin-widget="1">
    <div class="mannequin-widget__inner">
        <div class="mannequin-widget__layout">
            <div class="mannequin-widget__figure">
                <svg class="mannequin-widget__svg" viewBox="52 0 100 210" aria-hidden="true" preserveAspectRatio="xMidYMin meet">
                    <path class="mannequin-widget__body-fill" d="M104.265,117.959c-0.304,3.58,2.126,22.529,3.38,29.959c0.597,3.52,2.234,9.255,1.645,12.3c-0.841,4.244-1.084,9.736-0.621,12.934c0.292,1.942,1.211,10.899-0.104,14.175c-0.688,1.718-1.949,10.522-1.949,10.522c-3.285,8.294-1.431,7.886-1.431,7.886c1.017,1.248,2.759,0.098,2.759,0.098c1.327,0.846,2.246-0.201,2.246-0.201c1.139,0.943,2.467-0.116,2.467-0.116c1.431,0.743,2.758-0.627,2.758-0.627c0.822,0.414,1.023-0.109,1.023-0.109c2.466-0.158-1.376-8.05-1.376-8.05c-0.92-7.088,0.913-11.033,0.913-11.033c6.004-17.805,6.309-22.53,3.909-29.24c-0.676-1.937-0.847-2.704-0.536-3.545c0.719-1.941,0.195-9.748,1.072-12.848c1.692-5.979,3.361-21.142,4.231-28.217c1.169-9.53-4.141-22.308-4.141-22.308c-1.163-5.2,0.542-23.727,0.542-23.727c2.381,3.705,2.29,10.245,2.29,10.245c-0.378,6.859,5.541,17.342,5.541,17.342c2.844,4.332,3.921,8.442,3.921,8.747c0,1.248-0.273,4.269-0.273,4.269l0.109,2.631c0.049,0.67,0.426,2.977,0.365,4.092c-0.444,6.862,0.646,5.571,0.646,5.571c0.92,0,1.931-5.522,1.931-5.522c0,1.424-0.348,5.687,0.42,7.295c0.919,1.918,1.595-0.329,1.607-0.78c0.243-8.737,0.768-6.448,0.768-6.448c0.511,7.088,1.139,8.689,2.265,8.135c0.853-0.407,0.073-8.506,0.073-8.506c1.461,4.811,2.569,5.577,2.569,5.577c2.411,1.693,0.92-2.983,0.585-3.909c-1.784-4.92-1.839-6.625-1.839-6.625c2.229,4.421,3.909,4.257,3.909,4.257c2.174-0.694-1.9-6.954-4.287-9.953c-1.218-1.528-2.789-3.574-3.245-4.789c-0.743-2.058-1.304-8.674-1.304-8.674c-0.225-7.807-2.155-11.198-2.155-11.198c-3.3-5.282-3.921-15.135-3.921-15.135l-0.146-16.635c-1.157-11.347-9.518-11.429-9.518-11.429c-8.451-1.258-9.627-3.988-9.627-3.988c-1.79-2.576-0.767-7.514-0.767-7.514c1.485-1.208,2.058-4.415,2.058-4.415c2.466-1.891,2.345-4.658,1.206-4.628c-0.914,0.024-0.707-0.733-0.707-0.733C115.068,0.636,104.01,0,104.01,0h-1.688c0,0-11.063,0.636-9.523,13.089c0,0,0.207,0.758-0.715,0.733c-1.136-0.03-1.242,2.737,1.215,4.628c0,0,0.572,3.206,2.058,4.415c0,0,1.023,4.938-0.767,7.514c0,0-1.172,2.73-9.627,3.988c0,0-8.375,0.082-9.514,11.429l-0.158,16.635c0,0-0.609,9.853-3.922,15.135c0,0-1.921,3.392-2.143,11.198c0,0-0.563,6.616-1.303,8.674c-0.451,1.209-2.021,3.255-3.249,4.789c-2.408,2.993-6.455,9.24-4.29,9.953c0,0,1.689,0.164,3.909-4.257c0,0-0.046,1.693-1.827,6.625c-0.35,0.914-1.839,5.59,0.573,3.909c0,0,1.117-0.767,2.569-5.577c0,0-0.779,8.099,0.088,8.506c1.133,0.555,1.751-1.047,2.262-8.135c0,0,0.524-2.289,0.767,6.448c0.012,0.451,0.673,2.698,1.596,0.78c0.779-1.608,0.429-5.864,0.429-7.295c0,0,0.999,5.522,1.933,5.522c0,0,1.099,1.291,0.648-5.571c-0.073-1.121,0.32-3.422,0.369-4.092l0.106-2.631c0,0-0.274-3.014-0.274-4.269c0-0.311,1.078-4.415,3.921-8.747c0,0,5.913-10.488,5.532-17.342c0,0-0.082-6.54,2.299-10.245c0,0,1.69,18.526,0.545,23.727c0,0-5.319,12.778-4.146,22.308c0.864,7.094,2.53,22.237,4.226,28.217c0.886,3.094,0.362,10.899,1.072,12.848c0.32,0.847,0.152,1.627-0.536,3.545c-2.387,6.71-2.083,11.436,3.921,29.24c0,0,1.848,3.945,0.914,11.033c0,0-3.836,7.892-1.379,8.05c0,0,0.192,0.523,1.023,0.109c0,0,1.327,1.37,2.761,0.627c0,0,1.328,1.06,2.463,0.116c0,0,0.91,1.047,2.237,0.201c0,0,1.742,1.175,2.777-0.098c0,0,1.839,0.408-1.435-7.886c0,0-1.254-8.793-1.945-10.522c-1.318-3.275-0.387-12.251-0.106-14.175c0.453-3.216,0.21-8.695-0.618-12.934c-0.606-3.038,1.035-8.774,1.641-12.3c1.245-7.423,3.685-26.373,3.38-29.959l1.008,0.354C103.809,118.312,104.265,117.959,104.265,117.959z"></path>
                    <path class="mannequin-widget__body-stroke" d="M104.265,117.959c-0.304,3.58,2.126,22.529,3.38,29.959c0.597,3.52,2.234,9.255,1.645,12.3c-0.841,4.244-1.084,9.736-0.621,12.934c0.292,1.942,1.211,10.899-0.104,14.175c-0.688,1.718-1.949,10.522-1.949,10.522c-3.285,8.294-1.431,7.886-1.431,7.886c1.017,1.248,2.759,0.098,2.759,0.098c1.327,0.846,2.246-0.201,2.246-0.201c1.139,0.943,2.467-0.116,2.467-0.116c1.431,0.743,2.758-0.627,2.758-0.627c0.822,0.414,1.023-0.109,1.023-0.109c2.466-0.158-1.376-8.05-1.376-8.05c-0.92-7.088,0.913-11.033,0.913-11.033c6.004-17.805,6.309-22.53,3.909-29.24c-0.676-1.937-0.847-2.704-0.536-3.545c0.719-1.941,0.195-9.748,1.072-12.848c1.692-5.979,3.361-21.142,4.231-28.217c1.169-9.53-4.141-22.308-4.141-22.308c-1.163-5.2,0.542-23.727,0.542-23.727c2.381,3.705,2.29,10.245,2.29,10.245c-0.378,6.859,5.541,17.342,5.541,17.342c2.844,4.332,3.921,8.442,3.921,8.747c0,1.248-0.273,4.269-0.273,4.269l0.109,2.631c0.049,0.67,0.426,2.977,0.365,4.092c-0.444,6.862,0.646,5.571,0.646,5.571c0.92,0,1.931-5.522,1.931-5.522c0,1.424-0.348,5.687,0.42,7.295c0.919,1.918,1.595-0.329,1.607-0.78c0.243-8.737,0.768-6.448,0.768-6.448c0.511,7.088,1.139,8.689,2.265,8.135c0.853-0.407,0.073-8.506,0.073-8.506c1.461,4.811,2.569,5.577,2.569,5.577c2.411,1.693,0.92-2.983,0.585-3.909c-1.784-4.92-1.839-6.625-1.839-6.625c2.229,4.421,3.909,4.257,3.909,4.257c2.174-0.694-1.9-6.954-4.287-9.953c-1.218-1.528-2.789-3.574-3.245-4.789c-0.743-2.058-1.304-8.674-1.304-8.674c-0.225-7.807-2.155-11.198-2.155-11.198c-3.3-5.282-3.921-15.135-3.921-15.135l-0.146-16.635c-1.157-11.347-9.518-11.429-9.518-11.429c-8.451-1.258-9.627-3.988-9.627-3.988c-1.79-2.576-0.767-7.514-0.767-7.514c1.485-1.208,2.058-4.415,2.058-4.415c2.466-1.891,2.345-4.658,1.206-4.628c-0.914,0.024-0.707-0.733-0.707-0.733C115.068,0.636,104.01,0,104.01,0h-1.688c0,0-11.063,0.636-9.523,13.089c0,0,0.207,0.758-0.715,0.733c-1.136-0.03-1.242,2.737,1.215,4.628c0,0,0.572,3.206,2.058,4.415c0,0,1.023,4.938-0.767,7.514c0,0-1.172,2.73-9.627,3.988c0,0-8.375,0.082-9.514,11.429l-0.158,16.635c0,0-0.609,9.853-3.922,15.135c0,0-1.921,3.392-2.143,11.198c0,0-0.563,6.616-1.303,8.674c-0.451,1.209-2.021,3.255-3.249,4.789c-2.408,2.993-6.455,9.24-4.29,9.953c0,0,1.689,0.164,3.909-4.257c0,0-0.046,1.693-1.827,6.625c-0.35,0.914-1.839,5.59,0.573,3.909c0,0,1.117-0.767,2.569-5.577c0,0-0.779,8.099,0.088,8.506c1.133,0.555,1.751-1.047,2.262-8.135c0,0,0.524-2.289,0.767,6.448c0.012,0.451,0.673,2.698,1.596,0.78c0.779-1.608,0.429-5.864,0.429-7.295c0,0,0.999,5.522,1.933,5.522c0,0,1.099,1.291,0.648-5.571c-0.073-1.121,0.32-3.422,0.369-4.092l0.106-2.631c0,0-0.274-3.014-0.274-4.269c0-0.311,1.078-4.415,3.921-8.747c0,0,5.913-10.488,5.532-17.342c0,0-0.082-6.54,2.299-10.245c0,0,1.69,18.526,0.545,23.727c0,0-5.319,12.778-4.146,22.308c0.864,7.094,2.53,22.237,4.226,28.217c0.886,3.094,0.362,10.899,1.072,12.848c0.32,0.847,0.152,1.627-0.536,3.545c-2.387,6.71-2.083,11.436,3.921,29.24c0,0,1.848,3.945,0.914,11.033c0,0-3.836,7.892-1.379,8.05c0,0-0.192,0.523,1.023,0.109c0,0,1.327,1.37,2.761,0.627c0,0,1.328,1.06,2.463,0.116c0,0,0.91,1.047,2.237,0.201c0,0,1.742,1.175,2.777-0.098c0,0,1.839,0.408-1.435-7.886c0,0-1.254-8.793-1.945-10.522c-1.318-3.275-0.387-12.251-0.106-14.175c0.453-3.216,0.21-8.695-0.618-12.934c-0.606-3.038,1.035-8.774,1.641-12.3c1.245-7.423,3.685-26.373,3.38-29.959l1.008,0.354C103.809,118.312,104.265,117.959,104.265,117.959z"></path>
                </svg>
                <div class="mannequin-widget__dots" data-mannequin-dots="1"></div>
            </div>
            <div class="mannequin-widget__panel">
                <div class="mannequin-widget__head">
                    <h3><?= htmlspecialchars($title !== '' ? $title : 'Recomandări pe zone', ENT_QUOTES) ?></h3>
                    <button type="button" class="mannequin-widget__close" data-mannequin-close="1" aria-label="Închide">×</button>
                </div>
                <div class="mannequin-widget__list" data-mannequin-list="1">
                    <div class="mannequin-widget__empty"><?= htmlspecialchars($emptyText, ENT_QUOTES) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="mannequin-widget__backdrop" data-mannequin-backdrop="1"></div>
    <script type="application/json" class="mannequin-widget__data"><?= $json ?></script>
</section>

<style>
.mannequin-widget{width:100%;background:#fff;border-radius:16px;padding:16px 0 26px;margin:0;}
.mannequin-widget__inner{width:min(80%,1400px);margin:0 auto;}
.mannequin-widget__layout{display:grid;grid-template-columns:260px 1fr;gap:28px;align-items:start;min-height:630px;}
.mannequin-widget__figure{position:relative;width:260px;aspect-ratio:100 / 210;height:auto;margin:0 auto;}
.mannequin-widget__svg{width:100%;height:100%;display:block;filter:drop-shadow(0 10px 26px rgba(52,106,80,.15));}
.mannequin-widget__body-fill{fill:rgba(202,237,220,.24);}
.mannequin-widget__body-stroke{fill:none;stroke:#9fd1b6;stroke-width:.8;}
.mannequin-widget__dot{position:absolute;left:var(--x);top:var(--y);transform:translate(-50%,-50%);border:0;background:transparent;cursor:pointer;padding:0;z-index:5;}
.mannequin-widget__dot-core{width:16px;height:16px;border-radius:999px;display:block;background:#ecfff4;border:2px solid #90cfad;box-shadow:0 0 0 0 rgba(90,184,130,.55);animation:mannequinPulse 2s infinite;margin:0 auto;}
.mannequin-widget__dot.is-active .mannequin-widget__dot-core{background:#d0ffe3;border-color:#2f9a64;}
@keyframes mannequinPulse{0%{box-shadow:0 0 0 0 rgba(90,184,130,.55);}70%{box-shadow:0 0 0 14px rgba(90,184,130,0);}100%{box-shadow:0 0 0 0 rgba(90,184,130,0);}}
.mannequin-widget__panel{
    background:transparent;
    border:0;
    border-radius:0;
    min-height:220px;
    height:auto;
    max-height:none;
    overflow:visible;
    padding-right:0;
}
.mannequin-widget__head{padding:6px 0 12px;border-bottom:0;position:relative;}
.mannequin-widget__head h3{margin:0;font:700 22px/1.15 "Playfair Display",Georgia,serif;color:#20362a;}
.mannequin-widget__close{display:none !important;border:0;background:transparent;font-size:26px;line-height:1;color:#5f7568;cursor:pointer;position:absolute;right:10px;top:8px;}
.mannequin-widget__list{padding:10px 10px 14px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-content:start;max-height:none;overflow:visible;transition:opacity .18s ease,transform .18s ease;}
.mannequin-widget__list.is-clamped{overflow-y:auto;}
.mannequin-widget__list.is-clamped::-webkit-scrollbar{width:8px;}
.mannequin-widget__list.is-clamped::-webkit-scrollbar-thumb{background:#c8dbd0;border-radius:999px;}
.mannequin-widget__list.is-clamped::-webkit-scrollbar-track{background:transparent;}
.mannequin-widget__list.is-swapping{opacity:.18;transform:translateY(4px);}
.mannequin-widget__empty{color:#7b8e83;font:500 14px/1.45 "DM Sans",Arial,sans-serif;padding:16px;grid-column:1 / -1;}
.mannequin-widget article.mannequin-widget__card{
    border:1px solid #b9ddcb;
    border-radius:16px !important;
    overflow:hidden;
    box-sizing:border-box;
    padding:12px;
    display:grid;
    grid-template-columns:78px 1fr;
    gap:12px;
    align-items:start;
    background:#fff;
}
.mannequin-widget__card img{width:78px;height:78px;border-radius:12px;object-fit:cover;background:#f4f6f5;}
.mannequin-widget__card h4{margin:0 0 4px;font:700 32px/1.2 "Playfair Display",Georgia,serif;color:#22382d;}
.mannequin-widget__desc{
    margin:0 0 8px;
    display:inline-block;
    max-width:100%;
    padding:6px 10px;
    border-radius:10px;
    border:1px solid #d6e9de;
    background:#f5faf7;
    font:500 12px/1.35 "DM Sans",Arial,sans-serif;
    color:#60786a;
}
.mannequin-widget__rating{display:flex;align-items:center;gap:6px;margin:0 0 6px;}
.mannequin-widget__stars{display:inline-flex;gap:1px;font-size:16px;line-height:1;color:#d7e2db;}
.mannequin-widget__stars .is-active{color:#f4b425;}
.mannequin-widget__reviews{font:600 12px/1 "DM Sans",Arial,sans-serif;color:#6d7e74;}
.mannequin-widget__price{display:flex;align-items:baseline;gap:6px;flex-wrap:wrap;margin:0 0 8px;}
.mannequin-widget__price-sm{font:700 12px/1.2 "DM Sans",Arial,sans-serif;color:#173d2d;}
.mannequin-widget__price-old{font:500 11px/1.2 "DM Sans",Arial,sans-serif;color:#8aa093;text-decoration:line-through;}
.mannequin-widget__actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.mannequin-widget__btn{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:8px 12px;font:700 12px/1 "DM Sans",Arial,sans-serif;text-decoration:none;cursor:pointer;border:1px solid transparent;}
.mannequin-widget__btn--secondary{background:#fff;border-color:#cfe3d8;color:#22553f;}
.mannequin-widget__btn--primary{background:#1f8b57;color:#fff;}
.mannequin-widget__cart-form{margin:0;}
.mannequin-widget__meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.mannequin-widget__badge{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;background:#ecf4ef;color:#4b6156;font:700 12px/1 "DM Sans",Arial,sans-serif;}
.mannequin-widget__backdrop{position:fixed;inset:0;background:rgba(10,20,15,.34);opacity:0;pointer-events:none;transition:opacity .25s ease;z-index:39;}
.mannequin-widget__card.is-enter{animation:mannequinCardEnter .34s cubic-bezier(.22,.61,.36,1) both;animation-delay:calc(var(--i,0) * 55ms);}
.mannequin-widget__empty.is-enter{animation:mannequinCardEnter .28s ease both;}
@keyframes mannequinCardEnter{from{opacity:0;transform:translateY(10px) scale(.985);filter:blur(1px);}to{opacity:1;transform:translateY(0) scale(1);filter:blur(0);}}
@media (max-width:900px){
    .mannequin-widget{padding:10px 0;border-radius:12px;}
    .mannequin-widget__inner{width:100%;padding:0 10px;}
    .mannequin-widget__layout{grid-template-columns:1fr;min-height:auto;}
    .mannequin-widget__figure{width:min(86vw,320px);aspect-ratio:100 / 210;height:auto;max-height:min(78vh,680px);}
    .mannequin-widget__panel{position:fixed;left:50%;top:50%;width:min(92vw,430px);height:auto;min-height:0;max-height:min(78vh,640px);border-radius:16px;border:0;background:#fff;z-index:40;overflow:auto;box-shadow:0 24px 60px rgba(0,0,0,.26);opacity:0;pointer-events:none;transform:translate(-50%,-46%) scale(.96);transition:opacity .24s ease,transform .24s ease;}
    .mannequin-widget__panel.is-open{opacity:1;pointer-events:auto;transform:translate(-50%,-50%) scale(1);}
    .mannequin-widget__head{position:sticky;top:0;background:#fff;z-index:2;padding:12px;}
    .mannequin-widget__head h3{font-size:18px;}
    .mannequin-widget__card{grid-template-columns:66px 1fr;}
    .mannequin-widget__card img{width:66px;height:66px;}
    .mannequin-widget__card h4{font-size:18px;}
    .mannequin-widget__desc{font-size:12px;border-radius:9px;padding:6px 9px;}
    .mannequin-widget__close{display:block !important;}
    .mannequin-widget__list{grid-template-columns:1fr;}
    .mannequin-widget__backdrop.is-open{opacity:1;pointer-events:auto;}
}
</style>

<script>
(() => {
    const script = document.currentScript;
    const root = script?.previousElementSibling?.previousElementSibling;
    if (!(root instanceof HTMLElement) || !root.matches('[data-mannequin-widget="1"]')) {
        return;
    }
    const dataNode = root.querySelector('.mannequin-widget__data');
    const dotsWrap = root.querySelector('[data-mannequin-dots="1"]');
    const list = root.querySelector('[data-mannequin-list="1"]');
    const panel = root.querySelector('.mannequin-widget__panel');
    const backdrop = root.querySelector('[data-mannequin-backdrop="1"]');
    const closeBtn = root.querySelector('[data-mannequin-close="1"]');
    if (!(dataNode instanceof HTMLElement) || !(dotsWrap instanceof HTMLElement) || !(list instanceof HTMLElement) || !(panel instanceof HTMLElement)) {
        return;
    }

    let parsed = {};
    try {
        parsed = JSON.parse(dataNode.textContent || '{}');
    } catch {
        parsed = {};
    }
    const points = Array.isArray(parsed.points) ? parsed.points : [];
    const emptyText = String(parsed.emptyText || 'Nu sunt produse pentru această categorie.');
    const defaultTitle = String(parsed.title || 'Recomandări pe zone');
    const titleEl = root.querySelector('.mannequin-widget__head h3');
    const headEl = root.querySelector('.mannequin-widget__head');
    const isMobile = () => window.matchMedia('(max-width: 900px)').matches;
    let swapTimer = 0;
    let renderToken = 0;
    const resetDesktopListClamp = () => {
        list.style.maxHeight = '';
        list.style.overflowY = '';
        list.style.paddingRight = '';
        list.classList.remove('is-clamped');
    };
    const applyDesktopListClamp = () => {
        if (isMobile()) {
            resetDesktopListClamp();
            return;
        }
        const cards = Array.from(list.querySelectorAll('.mannequin-widget__card'));
        if (cards.length <= 4) {
            resetDesktopListClamp();
            return;
        }
        let maxBottom = 0;
        cards.slice(0, 4).forEach((card) => {
            maxBottom = Math.max(maxBottom, card.offsetTop + card.offsetHeight);
        });
        const styles = window.getComputedStyle(list);
        const paddingBottom = Number.parseFloat(styles.paddingBottom || '0') || 0;
        const maxHeight = Math.ceil(maxBottom + paddingBottom + 52);
        if (!Number.isFinite(maxHeight) || maxHeight <= 0) {
            resetDesktopListClamp();
            return;
        }
        list.style.maxHeight = `${maxHeight}px`;
        list.style.overflowY = 'auto';
        list.style.paddingRight = '6px';
        list.classList.add('is-clamped');
    };
    const scheduleDesktopListClamp = () => {
        if (isMobile()) {
            resetDesktopListClamp();
            return;
        }
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                applyDesktopListClamp();
            });
        });
    };

    const openPopup = () => {
        if (!isMobile()) return;
        panel.classList.add('is-open');
        backdrop?.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    };
    const closePopup = () => {
        panel.classList.remove('is-open');
        backdrop?.classList.remove('is-open');
        document.body.style.overflow = '';
    };
    const toCurrency = (value) => {
        const amount = Number(value || 0);
        return Number.isFinite(amount) ? amount.toFixed(2) + ' lei' : '0.00 lei';
    };
    const renderStars = (average) => {
        const rounded = Math.max(0, Math.min(5, Math.round(Number(average || 0))));
        let html = '';
        for (let i = 0; i < 5; i += 1) {
            html += `<span class="${i < rounded ? 'is-active' : ''}">★</span>`;
        }
        return html;
    };

    const renderList = (products) => {
        const token = ++renderToken;
        if (headEl instanceof HTMLElement) {
            const badge = headEl.querySelector('[data-mannequin-count="1"]');
            const countText = `${products.length} produse`;
            if (badge instanceof HTMLElement) {
                badge.textContent = countText;
            } else {
                const badgeEl = document.createElement('span');
                badgeEl.className = 'mannequin-widget__badge';
                badgeEl.setAttribute('data-mannequin-count', '1');
                badgeEl.textContent = countText;
                let meta = headEl.querySelector('.mannequin-widget__meta');
                if (!(meta instanceof HTMLElement)) {
                    meta = document.createElement('div');
                    meta.className = 'mannequin-widget__meta';
                    const titleNode = headEl.querySelector('h3');
                    if (titleNode instanceof HTMLElement) {
                        meta.appendChild(titleNode);
                    }
                    headEl.insertBefore(meta, headEl.firstChild);
                }
                meta.appendChild(badgeEl);
            }
        }
        const html = products.length === 0
            ? `<div class="mannequin-widget__empty">${emptyText}</div>`
            : products.map((p) => `
                <article class="mannequin-widget__card">
                    <img src="${p.image_url || '/assets/img/product-placeholder.svg'}" alt="${p.name || 'Produs'}" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                    <div>
                        <h4>${p.name || 'Produs'}</h4>
                        <p class="mannequin-widget__desc">${p.short_description || ''}</p>
                        <div class="mannequin-widget__rating">
                            <span class="mannequin-widget__stars">${renderStars(p.reviews_average)}</span>
                            <span class="mannequin-widget__reviews">(${Number(p.reviews_count || 0)})</span>
                        </div>
                        <div class="mannequin-widget__price">
                            ${(Number(p.regular_price || 0) > Number(p.price || 0))
                                ? `<span class="mannequin-widget__price-old">${toCurrency(p.regular_price)}</span>`
                                : ''}
                            <span class="mannequin-widget__price-sm">${toCurrency(p.price)}</span>
                        </div>
                        <div class="mannequin-widget__actions">
                            <a class="mannequin-widget__btn mannequin-widget__btn--secondary" href="${p.url || '/magazin'}">Detalii</a>
                            ${(Number(p.out_of_stock || 0) === 1)
                                ? '<span class="mannequin-widget__btn mannequin-widget__btn--secondary" style="opacity:.8;cursor:default;pointer-events:none;">Stoc epuizat</span>'
                                : `<form class="mannequin-widget__cart-form" method="post" action="${p.cart_add_url || '/cos'}"><button class="mannequin-widget__btn mannequin-widget__btn--primary" type="submit">Adaugă în coș</button></form>`
                            }
                        </div>
                    </div>
                </article>
            `).join('');

        if (!isMobile()) {
            list.classList.add('is-swapping');
            clearTimeout(swapTimer);
            swapTimer = window.setTimeout(() => {
                if (token !== renderToken) return;
                list.innerHTML = html;
                const cards = Array.from(list.querySelectorAll('.mannequin-widget__card'));
                const empty = list.querySelector('.mannequin-widget__empty');
                cards.forEach((card, index) => {
                    card.style.setProperty('--i', String(index));
                    card.classList.add('is-enter');
                });
                if (empty) empty.classList.add('is-enter');
                scheduleDesktopListClamp();
                requestAnimationFrame(() => list.classList.remove('is-swapping'));
            }, 140);
        } else {
            list.innerHTML = html;
            resetDesktopListClamp();
        }
    };

    const setActive = (id) => {
        dotsWrap.querySelectorAll('.mannequin-widget__dot').forEach((el) => {
            el.classList.toggle('is-active', el.getAttribute('data-point-id') === id);
        });
    };

    const renderPoint = (point, openOnMobile = true) => {
        if (!point || typeof point !== 'object') return;
        const products = Array.isArray(point.products) ? point.products : [];
        renderList(products);
        if (!isMobile()) {
            panel.scrollTop = 0;
        }
        if (titleEl instanceof HTMLElement) {
            const pointLabel = String(point.label || '').trim();
            titleEl.textContent = pointLabel !== '' ? pointLabel : defaultTitle;
        }
        setActive(String(point.id || ''));
        if (openOnMobile) openPopup();
    };

    points.forEach((point, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'mannequin-widget__dot';
        button.setAttribute('data-point-id', String(point.id || ('point-' + (index + 1))));
        const x = Math.max(0, Math.min(100, Number(point.x || 50)));
        const y = Math.max(0, Math.min(100, Number(point.y || 50)));
        button.style.setProperty('--x', x + '%');
        button.style.setProperty('--y', y + '%');
        button.setAttribute('aria-label', String(point.label || ('Punct ' + (index + 1))));
        button.innerHTML = '<span class="mannequin-widget__dot-core"></span>';
        button.addEventListener('click', () => renderPoint(point, true));
        dotsWrap.appendChild(button);
    });

    backdrop?.addEventListener('click', closePopup);
    closeBtn?.addEventListener('click', closePopup);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closePopup();
    });

    if (points.length > 0) {
        renderPoint(points[0], false);
    }

    const mq = window.matchMedia('(max-width: 900px)');
    const onChange = (event) => {
        if (!event.matches) closePopup();
        scheduleDesktopListClamp();
    };
    if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', onChange);
    } else if (typeof mq.addListener === 'function') {
        mq.addListener(onChange);
    }
    window.addEventListener('resize', () => {
        if (!isMobile()) {
            scheduleDesktopListClamp();
        }
    }, { passive: true });
})();
</script>
