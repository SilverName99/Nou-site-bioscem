<?php
/**
 * Data nașterii: zi, lună și an, fiecare din lista lui.
 *
 * Calendarul nativ (`input type="date"`) pornește din luna curentă, așa că
 * până la un an de naștere se dă scroll prin zeci de luni — pe telefon, prin
 * roți care se învârt la nesfârșit. Aici anul se alege dintr-o listă care
 * începe cu cei mai probabili (adulți, deci de acum înapoi), iar ziua și luna
 * dintr-o listă scurtă.
 *
 * Nu are nevoie de JavaScript: cele trei câmpuri pleacă separat la server, iar
 * acolo se compun la loc (vezi `dataNasteriiDinPost` din SiteController).
 *
 * Parametru: $birthDateValue — data existentă, „aaaa-ll-zz" sau gol.
 */
$bdValoare = trim((string) ($birthDateValue ?? ''));
$bdZi = '';
$bdLuna = '';
$bdAn = '';
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bdValoare, $bdParti) === 1) {
    $bdAn = $bdParti[1];
    $bdLuna = $bdParti[2];
    $bdZi = $bdParti[3];
}
$bdLuni = [
    '01' => 'ianuarie', '02' => 'februarie', '03' => 'martie',
    '04' => 'aprilie', '05' => 'mai', '06' => 'iunie',
    '07' => 'iulie', '08' => 'august', '09' => 'septembrie',
    '10' => 'octombrie', '11' => 'noiembrie', '12' => 'decembrie',
];
$bdAnCurent = (int) date('Y');
?>
<div class="bv-birthdate">
    <select name="birth_day" aria-label="Ziua nașterii">
        <option value="">Zi</option>
        <?php for ($z = 1; $z <= 31; $z++): ?>
            <?php $zi = str_pad((string) $z, 2, '0', STR_PAD_LEFT); ?>
            <option value="<?= $zi ?>" <?= $bdZi === $zi ? 'selected' : '' ?>><?= $z ?></option>
        <?php endfor; ?>
    </select>
    <select name="birth_month" aria-label="Luna nașterii">
        <option value="">Luna</option>
        <?php foreach ($bdLuni as $bdCod => $bdNume): ?>
            <option value="<?= $bdCod ?>" <?= $bdLuna === $bdCod ? 'selected' : '' ?>><?= $bdNume ?></option>
        <?php endforeach; ?>
    </select>
    <select name="birth_year" aria-label="Anul nașterii">
        <option value="">An</option>
        <?php for ($a = $bdAnCurent; $a >= $bdAnCurent - 100; $a--): ?>
            <option value="<?= $a ?>" <?= $bdAn === (string) $a ? 'selected' : '' ?>><?= $a ?></option>
        <?php endfor; ?>
    </select>
</div>
