<?php
/**
 * Casetele avertismentelor (ex. produs refrigerat) și bifele obligatorii, deasupra
 * butonului de plasare. O casetă pe avertisment distinct (titlu + text); fiecare
 * bifă stă sub ultima casetă pe care o confirmă. Inclus de checkout-form.php și
 * dat de /api/cart/avertismente (sincronizarea după o schimbare a coșului pe /checkout).
 *
 * @var array $avertismente  App\Support\AvertismentProduse::pentruCos (nevid)
 * @var string $instanceId
 * @var bool $previewMode
 */
$avertismente = is_array($avertismente ?? null) ? $avertismente : [];
$instanceId = (string) ($instanceId ?? 'checkout-live');
$previewMode = (bool) ($previewMode ?? false);
?>
<?php foreach ((array) ($avertismente['avertismente'] ?? []) as $indexAvertisment => $avertisment): ?>
    <div role="note" data-avertisment-produs
         style="margin:0 0 10px;padding:13px 14px;border:1px solid #fca5a5;border-left:4px solid #dc2626;border-radius:8px;background:#fef2f2;color:#7f1d1d;font-size:13px;line-height:1.55;">
        <?php if (trim((string) $avertisment['titlu']) !== ''): ?>
            <strong style="display:block;margin-bottom:2px;font-size:14px;line-height:1.35;"><?= htmlspecialchars((string) $avertisment['titlu'], ENT_QUOTES) ?></strong>
        <?php endif; ?>
        <span style="display:block;margin-bottom:8px;font-size:12px;color:#991b1b;">Pentru: <?= htmlspecialchars(implode(', ', $avertisment['produse']), ENT_QUOTES) ?></span>
        <div style="max-height:45vh;overflow-y:auto;overflow-wrap:anywhere;color:#450a0a;">
            <?= \App\Support\AvertismentProduse::paragrafeHtml((string) $avertisment['text'], 'margin:0 0 8px;') ?>
        </div>
    </div>
    <?php foreach ((array) ($avertismente['bife'] ?? []) as $bifaAvertisment): ?>
        <?php if ((int) $bifaAvertisment['dupa'] !== (int) $indexAvertisment) { continue; } ?>
        <label class="bv-checkout-v3__terms" style="color:#7f1d1d;font-weight:700;">
            <input
                type="checkbox"
                name="<?= \App\Support\AvertismentProduse::CAMP_BIFA ?>[<?= htmlspecialchars((string) $bifaAvertisment['cheie'], ENT_QUOTES) ?>]"
                value="1"
                form="<?= htmlspecialchars($instanceId, ENT_QUOTES) ?>-form"
                <?= $previewMode ? '' : 'required' ?>
                data-avertisment-bifa
            >
            <span><?= htmlspecialchars((string) $bifaAvertisment['eticheta'], ENT_QUOTES) ?></span>
        </label>
    <?php endforeach; ?>
<?php endforeach; ?>
