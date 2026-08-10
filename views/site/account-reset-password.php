<?php
$token = (string) ($token ?? '');
$email = (string) ($email ?? '');
?>
<section class="panel account-reset-panel">
    <h1 style="margin-top:0;">Setează parolă nouă</h1>
    <p>Cont: <strong><?= htmlspecialchars($email, ENT_QUOTES) ?></strong></p>

    <form method="post" action="/contul-meu/resetare-parola/<?= urlencode($token) ?>" class="field" style="max-width:480px;">
        <label>Parolă nouă</label>
        <input type="password" name="password" minlength="8" required>
        <label>Confirmă parola</label>
        <input type="password" name="password_confirm" minlength="8" required>
        <button class="btn" type="submit">Salvează parola</button>
    </form>

    <p style="margin-top:14px;">
        <a href="/login">Înapoi la autentificare</a>
    </p>
</section>

