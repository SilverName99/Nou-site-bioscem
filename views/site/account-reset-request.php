<section class="panel account-reset-panel">
    <h1 style="margin-top:0;">Resetare parolă</h1>
    <p>Introdu adresa de email a contului. Îți trimitem un link pentru setarea unei parole noi.</p>

    <form method="post" action="/contul-meu/resetare-parola" class="field" style="max-width:480px;">
        <label>Email cont</label>
        <input type="email" name="email" required>
        <button class="btn" type="submit">Trimite link de resetare</button>
    </form>

    <p style="margin-top:14px;">
        <a href="/login">Înapoi la autentificare</a>
    </p>
</section>

