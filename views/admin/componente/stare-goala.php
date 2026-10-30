<?php
/**
 * Stare goală: ce vede omul când nu e nimic de arătat, și ce poate face.
 *
 * @var string $titlu
 * @var string $text     opțional
 * @var string $actiune  opțional, HTML (un buton sau un link)
 */
?>
<div class="adm-gol">
    <p class="adm-gol__titlu"><?= adm_e($titlu ?? '') ?></p>
    <?php if (trim((string) ($text ?? '')) !== ''): ?>
        <p class="adm-gol__text"><?= adm_e($text) ?></p>
    <?php endif; ?>
    <?php if (trim((string) ($actiune ?? '')) !== ''): ?>
        <div class="adm-gol__actiune"><?= $actiune ?></div>
    <?php endif; ?>
</div>
