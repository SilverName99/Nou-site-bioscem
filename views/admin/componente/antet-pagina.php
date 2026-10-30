<?php
/**
 * Antetul unei pagini (sau al unui pas): titlu, o propoziție sub el, butoanele
 * din dreapta și ajutorul ascuns în spatele lui „?”.
 *
 * @var string $titlu
 * @var string $subtitlu   opțional, text simplu
 * @var array  $ajutor     opțional, paragrafe (text simplu) arătate la „?”
 * @var string $actiuni    opțional, HTML deja pregătit (butoane)
 * @var string $id         opțional, prefix pentru id-uri
 */
$id = (string) ($id ?? ('antet-' . substr(md5((string) ($titlu ?? '')), 0, 6)));
$ajutor = array_values(array_filter((array) ($ajutor ?? []), fn($p) => trim((string) $p) !== ''));
?>
<header class="adm-antet" id="<?= adm_e($id) ?>">
    <div class="adm-antet__text">
        <h2 class="adm-antet__titlu">
            <?= adm_e($titlu ?? '') ?>
            <?php if ($ajutor !== []): ?>
                <button type="button" class="adm-ajutor__buton" aria-expanded="false" aria-controls="<?= adm_e($id) ?>-ajutor" title="Ajutor: ce fac aici?">?</button>
            <?php endif; ?>
        </h2>
        <?php if (trim((string) ($subtitlu ?? '')) !== ''): ?>
            <p class="adm-antet__subtitlu"><?= adm_e($subtitlu) ?></p>
        <?php endif; ?>
    </div>
    <?php if (trim((string) ($actiuni ?? '')) !== ''): ?>
        <div class="adm-antet__actiuni"><?= $actiuni ?></div>
    <?php endif; ?>
</header>
<?php if ($ajutor !== []): ?>
    <div class="adm-ajutor" id="<?= adm_e($id) ?>-ajutor" hidden>
        <?php foreach ($ajutor as $paragraf): ?>
            <p><?= adm_e($paragraf) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
