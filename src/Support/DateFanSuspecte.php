<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Datele au venit, dar nu arată a listă întreagă. Spre deosebire de o cerere
 * picată, aici nu are sens reluarea de la pasul curent.
 */
final class DateFanSuspecte extends RuntimeException
{
}
