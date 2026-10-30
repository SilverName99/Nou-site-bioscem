<?php
/**
 * Tabel simplu: antet + rânduri. Celulele vin deja ca HTML (escapat de cine
 * cheamă), ca să poată conține butoane sau bife.
 *
 * @var list<string> $coloane     etichetele coloanelor (text simplu)
 * @var list<array{celule: list<string>, atribute?: string}> $randuri
 * @var string $gol               HTML arătat când nu sunt rânduri (de obicei o stare goală)
 * @var string $id                opțional
 * @var string $legenda           opțional, pentru cititoarele de ecran
 */
$randuri = (array) ($randuri ?? []);
?>
<?php if ($randuri === []): ?>
    <?= $gol ?? '' ?>
<?php else: ?>
    <div class="adm-tabel__cadru">
        <table class="adm-tabel" <?= isset($id) ? 'id="' . adm_e($id) . '"' : '' ?>>
            <?php if (trim((string) ($legenda ?? '')) !== ''): ?>
                <caption class="adm-ascuns"><?= adm_e($legenda) ?></caption>
            <?php endif; ?>
            <thead>
                <tr>
                    <?php foreach ((array) ($coloane ?? []) as $coloana): ?>
                        <th scope="col"><?= adm_e($coloana) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($randuri as $rand): ?>
                    <tr <?= (string) ($rand['atribute'] ?? '') ?>>
                        <?php foreach ((array) ($rand['celule'] ?? []) as $celula): ?>
                            <td><?= $celula ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
