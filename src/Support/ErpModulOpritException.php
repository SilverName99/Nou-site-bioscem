<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * ERP-ul a refuzat cererea fiindcă modulul „Magazin online” e oprit acolo sau
 * se oprește.
 *
 * Nu e o pană: ERP-ul răspunde, doar că nu mai primește comenzi noi de pe
 * site. Apelantul ține comanda aici, fără să-i ardă încercările. E tot o
 * RuntimeException, ca orice `catch (Throwable)` de până acum s-o prindă la
 * fel ca înainte.
 */
final class ErpModulOpritException extends RuntimeException
{
    /** `se_opreste` sau `oprit` (constantele din ErpModul). */
    public string $stare;

    public function __construct(string $stare)
    {
        $this->stare = $stare === ErpModul::SE_OPRESTE ? ErpModul::SE_OPRESTE : ErpModul::OPRIT;
        parent::__construct(ErpModul::mesajScurt($this->stare), 403);
    }
}
