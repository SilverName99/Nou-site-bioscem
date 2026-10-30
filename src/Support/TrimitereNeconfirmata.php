<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Mesajul a plecat întreg spre serverul de email, dar confirmarea nu a venit
 * (conexiune căzută, timp expirat după trimiterea conținutului). Poate a fost
 * livrat, poate nu: un newsletter NU îl retrimite, ca nimeni să nu-l primească
 * de două ori; în evidență rămâne „neconfirmat”.
 */
final class TrimitereNeconfirmata extends RuntimeException
{
}
