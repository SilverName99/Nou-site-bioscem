<?php
/**
 * Bara de pași a unui flux (de exemplu Conținut → Destinatari → Verificare →
 * Trimite). Pașii sunt butoane; JS-ul paginii le leagă prin `data-pas`.
 *
 * @var list<array{nr:int, eticheta:string}> $pasi
 * @var int    $curent
 * @var string $dreapta  opțional, HTML (de exemplu starea)
 * @var string $id       opțional
 */
$curent = (int) ($curent ?? 1);
?>
<nav class="adm-pasi" <?= isset($id) ? 'id="' . adm_e($id) . '"' : '' ?> aria-label="Pașii">
    <ol class="adm-pasi__lista">
        <?php foreach ((array) ($pasi ?? []) as $pas): ?>
            <?php $nr = (int) ($pas['nr'] ?? 0); ?>
            <li class="adm-pasi__element">
                <button type="button" class="adm-pasi__pas<?= $nr === $curent ? ' este-curent' : '' ?><?= $nr < $curent ? ' este-gata' : '' ?>"
                        data-pas="<?= $nr ?>" <?= $nr === $curent ? 'aria-current="step"' : '' ?>>
                    <span class="adm-pasi__nr" aria-hidden="true"><?= $nr ?></span>
                    <span class="adm-pasi__eticheta"><?= adm_e($pas['eticheta'] ?? '') ?></span>
                </button>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php if (trim((string) ($dreapta ?? '')) !== ''): ?>
        <div class="adm-pasi__dreapta"><?= $dreapta ?></div>
    <?php endif; ?>
</nav>
