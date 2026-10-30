<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Adresa e pe lista „nu trimite” (abonat șters din admin). Nu se poate adăuga
 * din admin sau din import; doar persoana se poate reabona singură, din
 * formularul de pe site, cu confirmare prin email.
 */
final class AdresaSuprimata extends RuntimeException
{
}
