<section class="panel" style="max-width:460px;margin:40px auto;">
    <h1>Login admin</h1>
    <?php if (!empty($error)): ?>
        <p style="color:#b91c1c;"><?= htmlspecialchars((string) $error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/login" class="field">
        <label>Email</label>
        <input type="email" name="email" required>
        <label>Parolă</label>
        <input type="password" name="password" required>
        <button class="btn" type="submit">Autentificare</button>
    </form>
</section>
