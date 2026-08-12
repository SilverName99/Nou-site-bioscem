<?php
/**
 * Pagina văzută de vizitatori cât timp site-ul e în mentenanță.
 * Nu folosește layout-ul obișnuit: trebuie să se încarce chiar dacă
 * restul site-ului e în lucru.
 *
 * @var string $titlu
 * @var string $mesaj
 * @var string $email
 * @var string $telefon
 * @var string $logo
 */
?><!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($titlu, ENT_QUOTES) ?></title>
    <?php if ($logo !== ''): ?>
        <link rel="icon" href="<?= htmlspecialchars($logo, ENT_QUOTES) ?>">
    <?php endif; ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: linear-gradient(160deg, #f0fdf9 0%, #f8fafc 55%, #fefce8 100%);
            color: #0f172a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            line-height: 1.6;
        }
        .card {
            width: 100%;
            max-width: 520px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }
        .emblema {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: #ecfdf5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
        }
        h1 { margin: 0 0 12px; font-size: 26px; line-height: 1.25; }
        p { margin: 0 0 8px; color: #475569; }
        .contact {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            font-size: 15px;
        }
        .contact a { color: #0f766e; text-decoration: none; font-weight: 600; }
        .contact a:hover { text-decoration: underline; }
        @media (max-width: 480px) {
            .card { padding: 32px 20px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="emblema" aria-hidden="true">🌿</div>
        <h1><?= htmlspecialchars($titlu, ENT_QUOTES) ?></h1>
        <p><?= nl2br(htmlspecialchars($mesaj, ENT_QUOTES)) ?></p>

        <?php if ($email !== '' || $telefon !== ''): ?>
            <div class="contact">
                <p style="margin-bottom:6px;">Pentru comenzi sau întrebări, ne găsești aici:</p>
                <?php if ($telefon !== ''): ?>
                    <div><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $telefon) ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($telefon, ENT_QUOTES) ?></a></div>
                <?php endif; ?>
                <?php if ($email !== ''): ?>
                    <div><a href="mailto:<?= htmlspecialchars($email, ENT_QUOTES) ?>"><?= htmlspecialchars($email, ENT_QUOTES) ?></a></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
