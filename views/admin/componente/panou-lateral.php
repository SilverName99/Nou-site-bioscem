<?php
/**
 * Panou lateral: un rezumat sau o acțiune secundară lângă conținutul principal
 * (în Faza D devine panoul care se deschide din dreapta).
 *
 * @var string $titlu
 * @var string $continut  HTML deja pregătit
 * @var string $id        opțional
 */
?>
<aside class="adm-panou" <?= isset($id) ? 'id="' . adm_e($id) . '"' : '' ?> aria-label="<?= adm_e($titlu ?? '') ?>">
    <?php if (trim((string) ($titlu ?? '')) !== ''): ?>
        <h3 class="adm-panou__titlu"><?= adm_e($titlu) ?></h3>
    <?php endif; ?>
    <div class="adm-panou__corp"><?= $continut ?? '' ?></div>
</aside>
