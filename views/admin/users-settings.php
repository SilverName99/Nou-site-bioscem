<?php
$settings = is_array($settings ?? null) ? $settings : [];
$firstNameEnabled = (string) ($settings['customer_registration_field_first_name'] ?? '1') !== '0';
$lastNameEnabled = (string) ($settings['customer_registration_field_last_name'] ?? '1') !== '0';
$emailEnabled = (string) ($settings['customer_registration_field_email'] ?? '1') !== '0';
$phoneEnabled = (string) ($settings['customer_registration_field_phone'] ?? '1') !== '0';
$birthEnabled = (string) ($settings['customer_registration_field_birth_date'] ?? '0') === '1';
$genderEnabled = (string) ($settings['customer_registration_field_gender'] ?? '0') === '1';
$passwordEnabled = (string) ($settings['customer_registration_field_password'] ?? '1') !== '0';
$passwordConfirmEnabled = (string) ($settings['customer_registration_field_password_confirm'] ?? '1') !== '0';
$googleEnabled = (string) ($settings['customer_google_auth_enabled'] ?? '0') === '1';
$googleClientId = (string) ($settings['customer_google_client_id'] ?? '');
$googleClientSecret = (string) ($settings['customer_google_client_secret'] ?? '');
$googleCallbackUrl = (string) ($googleCallbackUrl ?? '');
?>

<section class="panel">
    <div class="section-head" style="margin-bottom:10px;">
        <div>
            <h1>Setări utilizatori</h1>
            <p>Configurează câmpurile de înregistrare și paginile necesare pentru cont.</p>
        </div>
    </div>

    <article class="panel" style="margin:12px 0;">
        <h3 style="margin-top:0;">Pagini utilizatori</h3>
        <p style="margin:0 0 10px;color:#64748b;">
            Verifică și creează automat paginile: Login, Register, Contul meu, Resetare parolă.
        </p>
        <form method="post" action="/admin/users/settings">
            <input type="hidden" name="action" value="generate_pages">
            <button class="btn" type="submit">Generează pagini necesare</button>
        </form>
    </article>

    <article class="panel" style="margin:12px 0;">
        <h3 style="margin-top:0;">Login cu Google</h3>
        <p style="margin:0 0 10px;color:#64748b;">
            Configurează OAuth 2.0 pentru autentificarea clienților cu Google.
        </p>
        <form method="post" action="/admin/users/settings" class="field">
            <input type="hidden" name="action" value="save_google_auth">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="google_enabled" <?= $googleEnabled ? 'checked' : '' ?>>
                Activează login cu Google
            </label>
            <label>Google Client ID</label>
            <input type="text" name="google_client_id" value="<?= htmlspecialchars($googleClientId, ENT_QUOTES) ?>" placeholder="ex: 1234567890-abc.apps.googleusercontent.com">
            <label>Google Client Secret</label>
            <input type="password" name="google_client_secret" value="<?= htmlspecialchars($googleClientSecret, ENT_QUOTES) ?>" placeholder="ex: GOCSPX-...">
            <label>Redirect URI (de pus în Google Cloud)</label>
            <input type="text" value="<?= htmlspecialchars($googleCallbackUrl, ENT_QUOTES) ?>" readonly>
            <div>
                <button class="btn" type="submit">Salvează setările Google</button>
            </div>
        </form>
    </article>

    <article class="panel" style="margin:12px 0 0;">
        <h3 style="margin-top:0;">Câmpuri formular înregistrare</h3>
        <p style="margin:0 0 10px;color:#64748b;">Controlezi afișarea fiecărui câmp din formularul de înregistrare.</p>
        <form method="post" action="/admin/users/settings" class="field">
            <input type="hidden" name="action" value="save_fields">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="field_first_name" <?= $firstNameEnabled ? 'checked' : '' ?>>
                Prenume
            </label>
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="field_last_name" <?= $lastNameEnabled ? 'checked' : '' ?>>
                Nume
            </label>
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="field_email" <?= $emailEnabled ? 'checked' : '' ?>>
                Email
            </label>
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="field_phone" <?= $phoneEnabled ? 'checked' : '' ?>>
                Telefon
            </label>
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="field_birth_date" <?= $birthEnabled ? 'checked' : '' ?>>
                Data nașterii
            </label>
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="field_gender" <?= $genderEnabled ? 'checked' : '' ?>>
                Gen (Feminin / Masculin)
            </label>
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="field_password" <?= $passwordEnabled ? 'checked' : '' ?>>
                Parolă
            </label>
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="field_password_confirm" <?= $passwordConfirmEnabled ? 'checked' : '' ?>>
                Confirmă parola
            </label>
            <div>
                <button class="btn" type="submit">Salvează setările</button>
            </div>
        </form>
    </article>
</section>
