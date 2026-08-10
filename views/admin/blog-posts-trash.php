<section class="panel">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
            <h1>Coș postări blog</h1>
            <p>Restaurează postări șterse sau elimină-le definitiv.</p>
        </div>
        <a class="btn btn-secondary" href="/admin/blog/posts">Înapoi la postări</a>
    </div>

    <table class="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Titlu</th>
            <th>Slug</th>
            <th>Ștearsă la</th>
            <th>Acțiuni</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach (($posts ?? []) as $post): ?>
            <tr>
                <td><?= (int) ($post['id'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string) ($post['title'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($post['slug'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($post['deleted_at'] ?? ''), ENT_QUOTES) ?></td>
                <td>
                    <div style="display:flex;gap:8px;">
                        <form method="post" action="/admin/blog/posts">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="id" value="<?= (int) ($post['id'] ?? 0) ?>">
                            <button class="btn btn-secondary" type="submit">Refacere</button>
                        </form>
                        <form method="post" action="/admin/blog/posts" onsubmit="return confirm('Ștergere definitivă?');">
                            <input type="hidden" name="action" value="force_delete">
                            <input type="hidden" name="id" value="<?= (int) ($post['id'] ?? 0) ?>">
                            <button class="icon-btn danger" type="submit" title="Ștergere definitivă">🗑</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
