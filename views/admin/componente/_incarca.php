<?php
/**
 * Componentele de admin (vocabularul din planul Fazei D, WP-31): antet de
 * pagină, bară de pași, tabel, panou lateral, mesaj, stare goală.
 *
 * Fiecare componentă e un fișier mic din acest dosar și primește doar ce i se
 * dă explicit (fără variabile din pagina care o cheamă). Stilul e în
 * public/assets/css/admin-componente.css, cu clase „adm-…”; Faza D schimbă
 * doar acel fișier (aspectul), nu și paginile.
 *
 * Folosire:  admin_componenta('mesaj', ['tip' => 'eroare', 'titlu' => '…']);
 */
if (!function_exists('admin_componenta')) {
    function admin_componenta(string $nume, array $date = []): void
    {
        if (!preg_match('/^[a-z-]+$/', $nume)) {
            return;
        }
        $fisier = __DIR__ . '/' . $nume . '.php';
        if (!is_file($fisier)) {
            return;
        }
        (static function (string $__fisier, array $date): void {
            extract($date, EXTR_SKIP);
            include $__fisier;
        })($fisier, $date);
    }

    /** Escapare scurtă pentru componente. */
    function adm_e(mixed $valoare): string
    {
        return htmlspecialchars((string) $valoare, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
