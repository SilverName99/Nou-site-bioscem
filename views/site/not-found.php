<?php
/**
 * Pagina 404 a magazinului.
 *
 * Stilurile stau inline: pagina asta e văzută des de clienți veniți pe
 * linkuri vechi, iar CSS-ul site-ului poate fi servit dintr-un cache vechi.
 */
$titlu404 = (string) ($titlu404 ?? 'Pagina nu a fost găsită');
$mesaj404 = (string) ($mesaj404 ?? 'Linkul pe care l-ai accesat nu mai există.');
$produseSugerate = is_array($produseSugerate ?? null) ? $produseSugerate : [];
?>
<section class="panel" style="max-width:900px;margin-left:auto;margin-right:auto;text-align:center;padding:36px 22px;">
    <p style="margin:0 0 6px;font-size:56px;line-height:1;">🔍</p>
    <h1 style="margin:0 0 12px;font-size:26px;line-height:1.25;color:#0f172a;">
        <?= htmlspecialchars($titlu404, ENT_QUOTES) ?>
    </h1>
    <p style="margin:0 auto 22px;max-width:560px;color:#475569;font-size:15px;line-height:1.6;">
        <?= htmlspecialchars($mesaj404, ENT_QUOTES) ?>
    </p>

    <form action="/magazin" method="get" style="margin:0 auto 22px;max-width:460px;display:flex;gap:8px;">
        <label for="cauta-404" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Caută produse</label>
        <input id="cauta-404" type="search" name="q" placeholder="Caută un produs…"
               style="flex:1;padding:12px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:15px;">
        <button type="submit"
                style="padding:12px 20px;border:none;border-radius:10px;background:#2f8d5b;color:#fff;font-size:15px;font-weight:700;cursor:pointer;">Caută</button>
    </form>

    <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
        <a href="/magazin" style="display:inline-block;padding:12px 22px;border-radius:10px;background:#2f8d5b;color:#fff;text-decoration:none;font-weight:700;font-size:15px;">Vezi toate produsele</a>
        <a href="/" style="display:inline-block;padding:12px 22px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;color:#334155;text-decoration:none;font-weight:600;font-size:15px;">Prima pagină</a>
        <a href="/contact" style="display:inline-block;padding:12px 22px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;color:#334155;text-decoration:none;font-weight:600;font-size:15px;">Contact</a>
    </div>

    <?php if ($produseSugerate !== []): ?>
        <div style="margin-top:34px;text-align:left;">
            <h2 style="margin:0 0 14px;font-size:17px;color:#0f172a;text-align:center;">Poate te interesează</h2>
            <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));">
                <?php foreach ($produseSugerate as $sugestie): ?>
                    <?php $slugSugestie = trim((string) ($sugestie['slug'] ?? '')); ?>
                    <?php if ($slugSugestie === '') { continue; } ?>
                    <a href="/produs/<?= rawurlencode($slugSugestie) ?>"
                       style="display:block;padding:12px 14px;border:1px solid #e2e8f0;border-radius:10px;text-decoration:none;color:#0f172a;background:#fff;">
                        <span style="display:block;font-size:14px;font-weight:600;line-height:1.4;">
                            <?= htmlspecialchars((string) ($sugestie['name'] ?? ''), ENT_QUOTES) ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
