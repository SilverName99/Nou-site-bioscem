<?php
/**
 * Un mesaj (banner): informare, reușită, avertisment sau eroare.
 *
 * @var string $tip      info | succes | avertisment | eroare
 * @var string $titlu
 * @var string $text     opțional, text simplu
 * @var string $actiuni  opțional, HTML (butoane)
 * @var string $id       opțional
 */
$tip = in_array($tip ?? 'info', ['info', 'succes', 'avertisment', 'eroare'], true) ? $tip : 'info';
?>
<div class="adm-mesaj adm-mesaj--<?= adm_e($tip) ?>" <?= isset($id) ? 'id="' . adm_e($id) . '"' : '' ?> role="<?= $tip === 'eroare' ? 'alert' : 'status' ?>">
    <div class="adm-mesaj__corp">
        <?php if (trim((string) ($titlu ?? '')) !== ''): ?>
            <strong class="adm-mesaj__titlu"><?= adm_e($titlu) ?></strong>
        <?php endif; ?>
        <?php if (trim((string) ($text ?? '')) !== ''): ?>
            <p class="adm-mesaj__text"><?= adm_e($text) ?></p>
        <?php endif; ?>
    </div>
    <?php if (trim((string) ($actiuni ?? '')) !== ''): ?>
        <div class="adm-mesaj__actiuni"><?= $actiuni ?></div>
    <?php endif; ?>
</div>
