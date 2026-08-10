<section class="panel">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
            <h1>Pagini</h1>
            <p>Administrezi pagini HTML custom, cu URL controlat prin slug.</p>
        </div>
        <div style="display:flex;gap:8px;">
            <a class="btn btn-secondary" href="/admin/pages/trash">Coș pagini</a>
            <a class="btn" href="/admin/pages/new">Pagină nouă</a>
        </div>
    </div>

    <table class="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Titlu</th>
            <th>Slug</th>
            <th>Status</th>
            <th>URL</th>
            <th>Acțiune</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($pages as $page): ?>
            <tr>
                <td><?= (int) $page['id'] ?></td>
                <td><?= htmlspecialchars((string) $page['title'], ENT_QUOTES) ?></td>
                <?php $isHomePage = trim((string) ($page['slug'] ?? '')) === ''; ?>
                <td><?= $isHomePage ? 'home (root /)' : htmlspecialchars((string) $page['slug'], ENT_QUOTES) ?></td>
                <td><?= ((int) $page['is_published'] === 1) ? 'Publicată' : 'Draft' ?></td>
                <?php $pageUrl = $isHomePage ? '/' : ('/' . ltrim((string) $page['slug'], '/')); ?>
                <td><a href="<?= $pageUrl ?>" target="_blank"><?= $isHomePage ? '/' : ('/' . htmlspecialchars((string) $page['slug'], ENT_QUOTES)) ?></a></td>
                <td>
                    <div style="display:flex;gap:8px;">
                        <a class="btn btn-secondary" href="/admin/pages/<?= (int) $page['id'] ?>/edit">Editează</a>
                        <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/delete" onsubmit="return confirm('Muți pagina în coș?');">
                            <button class="icon-btn danger" type="submit" title="Șterge">🗑</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
