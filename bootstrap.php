<?php

declare(strict_types=1);

/**
 * Sesiunea ține coșul, așa că trebuie să trăiască.
 *
 * Până acum se pornea fără nicio setare, deci lua ce zicea serverul: de obicei
 * 24 de minute de nemișcare și un cookie care moare la închiderea browserului.
 * Pe găzduire comună e și mai rău — curățenia altui site de pe aceeași mașină
 * poate mătura fișierele noastre mult mai devreme. De aici „am ieșit 5 minute
 * și mi s-a golit coșul".
 *
 * Acum: fișierele stau la noi, nu în folderul comun, iar viața lor e de 30 de
 * zile. Coșul rămâne întreg peste pauze și peste închiderea browserului.
 * Partea de administrare are limita ei de nemișcare, în `Auth` — altfel o
 * sesiune de 30 de zile ar ține un admin logat o lună.
 */
if (session_status() === PHP_SESSION_NONE) {
    $viataSesiune = 60 * 60 * 24 * 30; // 30 de zile

    $dosarSesiuni = __DIR__ . '/storage/sessions';
    if (!is_dir($dosarSesiuni)) {
        @mkdir($dosarSesiuni, 0770, true);
    }
    if (is_dir($dosarSesiuni) && is_writable($dosarSesiuni)) {
        // .htaccess-ul din rădăcină trimite deja totul în public/, dar un gard
        // în plus peste fișierele de sesiune nu strică nimănui.
        $gard = $dosarSesiuni . '/.htaccess';
        if (!is_file($gard)) {
            @file_put_contents($gard, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        ini_set('session.save_path', $dosarSesiuni);
        // Curățenia o facem noi, pe dosarul nostru: altfel fișierele s-ar
        // strânge la nesfârșit, fiindcă gc-ul serverului nu se uită aici.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '200');
    }
    ini_set('session.gc_maxlifetime', (string) $viataSesiune);
    ini_set('session.use_strict_mode', '1');

    $peHttps = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => $viataSesiune,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $peHttps,
    ]);

    session_start();
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

App\Support\Env::load(__DIR__ . '/.env');

$config = require __DIR__ . '/config/app.php';
date_default_timezone_set($config['timezone']);
