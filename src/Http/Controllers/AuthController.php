<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Auth;
use App\Support\Database;
use App\Support\View;
use PDO;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            header('Location: /admin');
            return;
        }

        View::render('admin/login', ['title' => 'Autentificare admin'], 'admin/layout');
    }

    public function login(): void
    {
        $config = require __DIR__ . '/../../../config/app.php';
        $db = Database::connection($config['db']);

        if (!$db instanceof PDO) {
            View::render(
                'admin/login',
                ['title' => 'Autentificare admin', 'error' => 'Conexiunea la baza de date lipsește.'],
                'admin/layout'
            );
            return;
        }

        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            View::render(
                'admin/login',
                ['title' => 'Autentificare admin', 'error' => 'Completează email și parolă.'],
                'admin/layout'
            );
            return;
        }

        if (!Auth::attempt($db, $email, $password)) {
            View::render(
                'admin/login',
                ['title' => 'Autentificare admin', 'error' => 'Date de autentificare invalide.'],
                'admin/layout'
            );
            return;
        }

        header('Location: ' . Auth::defaultPathForRole());
    }

    public function logout(): void
    {
        Auth::logout();
        header('Location: /admin/login');
    }
}
