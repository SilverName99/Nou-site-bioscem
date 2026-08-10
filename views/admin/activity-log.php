<?php
/** @var array $entries */
/** @var string $actionFilter */
$knownActions = array_unique(array_column($entries, 'action'));
sort($knownActions);

$actionLabels = [
    'loyalty_settings_save' => 'Setări puncte salvate',
];
$label = static fn(string $a): string => $actionLabels[$a] ?? $a;
?>
<div class="panel" style="margin:0 0 24px;">
    <h2 style="margin-top:0;">Jurnal activitate admin</h2>
    <p style="color:#6b7280;margin:0 0 16px;">Vizibil doar pentru Administrator General. Înregistrează modificările critice din panou.</p>

    <form method="get" action="/admin/activity-log" style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
        <select name="action" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
            <option value="">Toate acțiunile</option>
            <?php foreach ($knownActions as $act): ?>
                <option value="<?= htmlspecialchars($act, ENT_QUOTES) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label($act), ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-secondary" type="submit">Filtrează</button>
        <?php if ($actionFilter !== ''): ?>
            <a class="btn btn-secondary" href="/admin/activity-log">Reset</a>
        <?php endif; ?>
    </form>

    <?php if ($entries === []): ?>
        <p style="color:#6b7280;">Nu există înregistrări de activitate încă.</p>
    <?php else: ?>
    <div class="users-table-wrap">
        <table class="table users-table" style="font-size:13px;">
            <thead>
            <tr>
                <th>Data</th>
                <th>Admin</th>
                <th>Acțiune</th>
                <th>Detalii modificări</th>
                <th>IP</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <?php
                $ctx = [];
                if (!empty($entry['context_json'])) {
                    $decoded = json_decode((string) $entry['context_json'], true);
                    if (is_array($decoded)) {
                        $ctx = $decoded;
                    }
                }
                $action = (string) ($entry['action'] ?? '');
                $changes = is_array($ctx['changes'] ?? null) ? $ctx['changes'] : [];
                ?>
                <tr>
                    <td style="white-space:nowrap;"><?= htmlspecialchars((string) ($entry['created_at'] ?? ''), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars((string) ($entry['admin_email'] ?? '—'), ENT_QUOTES) ?></td>
                    <td><span class="status-pill"><?= htmlspecialchars($label($action), ENT_QUOTES) ?></span></td>
                    <td>
                        <?php if ($changes !== []): ?>
                            <ul style="margin:0;padding:0 0 0 16px;list-style:disc;">
                            <?php foreach ($changes as $key => $diff): ?>
                                <?php
                                $from = htmlspecialchars((string) ($diff['from'] ?? ''), ENT_QUOTES);
                                $to   = htmlspecialchars((string) ($diff['to'] ?? ''), ENT_QUOTES);
                                ?>
                                <li>
                                    <code><?= htmlspecialchars($key, ENT_QUOTES) ?></code>:
                                    <span style="color:#991b1b;"><?= $from ?></span>
                                    →
                                    <span style="color:#166534;"><?= $to ?></span>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                        <?php elseif ($ctx !== []): ?>
                            <em style="color:#6b7280;">Fără modificări față de valorile anterioare</em>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars((string) ($entry['ip'] ?? ''), ENT_QUOTES) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
