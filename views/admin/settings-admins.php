<?php
$admins = is_array($admins ?? null) ? $admins : [];
$roles = is_array($roles ?? null) ? $roles : [];
$currentAdminId = (int) ($currentAdminId ?? 0);
$normalizeRoles = static function (array $admin) use ($roles): array {
    $rawRoles = $admin['roles'] ?? [];
    if (!is_array($rawRoles)) {
        $rawRoles = [];
    }
    $normalized = [];
    foreach ($rawRoles as $roleKey) {
        $key = (string) $roleKey;
        if ($key !== '' && array_key_exists($key, $roles) && !in_array($key, $normalized, true)) {
            $normalized[] = $key;
        }
    }
    if ($normalized === []) {
        $legacyRole = (string) ($admin['role'] ?? '');
        if ($legacyRole !== '' && array_key_exists($legacyRole, $roles)) {
            $normalized[] = $legacyRole;
        }
    }
    if ($normalized === [] && $roles !== []) {
        $firstRole = array_key_first($roles);
        if (is_string($firstRole) && $firstRole !== '') {
            $normalized[] = $firstRole;
        }
    }
    return $normalized;
};
?>

<section class="panel">
    <div class="section-head" style="margin-bottom:10px;">
        <div>
            <h1>Administratori</h1>
            <p style="margin:0;color:#64748b;">Gestionează conturile de admin și rolurile lor.</p>
        </div>
    </div>

    <article class="panel" style="margin:12px 0;">
        <h3 style="margin-top:0;">Adaugă administrator</h3>
        <form method="post" action="/admin/settings/admins" style="display:grid;gap:10px;max-width:620px;">
            <input type="hidden" name="action" value="create_admin">
            <div class="field">
                <label for="admin_email">Email</label>
                <input id="admin_email" type="email" name="email" required>
            </div>
            <div class="field">
                <label for="admin_password">Parolă</label>
                <input id="admin_password" type="password" name="password" minlength="8" required>
            </div>
            <div class="field">
                <label>Roluri</label>
                <div style="display:grid;gap:8px;">
                    <?php foreach ($roles as $roleKey => $roleLabel): ?>
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input
                                type="checkbox"
                                name="roles[]"
                                value="<?= htmlspecialchars((string) $roleKey, ENT_QUOTES) ?>"
                                <?= (string) $roleKey === \App\Support\Auth::ROLE_STORE ? 'checked' : '' ?>
                            >
                            <span><?= htmlspecialchars((string) $roleLabel, ENT_QUOTES) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <button class="btn" type="submit">Creează administrator</button>
            </div>
        </form>
    </article>

    <article class="panel" style="margin:12px 0;">
        <h3 style="margin-top:0;">Lista administratori</h3>
        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Email</th>
                <th>Roluri</th>
                <th>Parolă nouă</th>
                <th>Creat la</th>
                <th>Acțiuni</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($admins === []): ?>
                <tr>
                    <td colspan="6">Nu există administratori.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($admins as $admin): ?>
                    <?php
                    if (!is_array($admin)) {
                        continue;
                    }
                    $adminId = (int) ($admin['id'] ?? 0);
                    $adminRoles = $normalizeRoles($admin);
                    $isSelf = $adminId === $currentAdminId;
                    ?>
                    <tr>
                        <td><?= $adminId ?></td>
                        <td><?= htmlspecialchars((string) ($admin['email'] ?? ''), ENT_QUOTES) ?><?= $isSelf ? ' <strong style="color:#0f766e;">(tu)</strong>' : '' ?></td>
                        <td>
                            <form method="post" action="/admin/settings/admins" style="display:grid;gap:8px;">
                                <input type="hidden" name="action" value="update_role">
                                <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                            <div style="display:grid;gap:6px;">
                                    <?php foreach ($roles as $roleKey => $roleLabel): ?>
                                        <label style="display:flex;align-items:center;gap:6px;">
                                            <input
                                                type="checkbox"
                                                name="roles[]"
                                                value="<?= htmlspecialchars((string) $roleKey, ENT_QUOTES) ?>"
                                                <?= in_array((string) $roleKey, $adminRoles, true) ? 'checked' : '' ?>
                                            >
                                            <span><?= htmlspecialchars((string) $roleLabel, ENT_QUOTES) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <button class="btn btn-secondary" type="submit">Salvează roluri</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" action="/admin/settings/admins" style="display:flex;gap:8px;align-items:center;">
                                <input type="hidden" name="action" value="update_password">
                                <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                <input type="password" name="password" minlength="8" placeholder="Minim 8 caractere" required>
                                <button class="btn btn-secondary" type="submit">Actualizează</button>
                            </form>
                        </td>
                        <td><?= htmlspecialchars((string) ($admin['created_at'] ?? ''), ENT_QUOTES) ?></td>
                        <td>
                            <?php if ($isSelf): ?>
                                <span style="color:#94a3b8;font-size:12px;">Nu poți șterge contul curent</span>
                            <?php else: ?>
                                <form method="post" action="/admin/settings/admins" onsubmit="return confirm('Ștergi acest administrator?');">
                                    <input type="hidden" name="action" value="delete_admin">
                                    <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                    <button class="icon-btn danger" type="submit" title="Șterge">🗑</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </article>
</section>
