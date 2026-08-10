<?php
$posts = is_array($posts ?? null) ? $posts : [];
$search = trim((string) ($search ?? ''));
$status = in_array((string) ($status ?? 'all'), ['all', 'published', 'draft', 'scheduled'], true) ? (string) $status : 'all';
$page = max(1, (int) ($page ?? 1));
$perPage = (int) ($perPage ?? 25);
$totalPages = max(1, (int) ($totalPages ?? 1));
$totalPosts = max(0, (int) ($totalPosts ?? 0));
$panel = in_array((string) ($panel ?? 'list'), ['list', 'import'], true) ? (string) $panel : 'list';
$perPageOptions = is_array($perPageOptions ?? null) ? $perPageOptions : [10, 25, 50, 100, 200, 500];
$buildPostsUrl = static function (array $params = []) use ($search, $status, $perPage, $panel): string {
    $query = [
        'status' => $status,
        'per_page' => $perPage,
        'panel' => $panel,
    ];
    if ($search !== '') {
        $query['q'] = $search;
    }
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
            continue;
        }
        $query[$k] = $v;
    }
    return '/admin/blog/posts' . ($query !== [] ? '?' . http_build_query($query) : '');
};
$paginationPages = [];
$windowStart = max(1, $page - 2);
$windowEnd = min($totalPages, $page + 2);
for ($i = $windowStart; $i <= $windowEnd; $i++) {
    $paginationPages[] = $i;
}
?>

<section class="panel">
    <div class="section-head">
        <div>
            <h1>Postări blog</h1>
            <p>Gestionează articolele pentru secțiunea Blog.</p>
        </div>
        <div style="display:flex;gap:8px;">
            <a class="btn btn-secondary" href="/admin/blog/posts/trash">Coș postări</a>
            <a class="btn" href="/admin/blog/posts/new">+ Postare nouă</a>
        </div>
    </div>

    <div class="subsection-tabs" style="margin-bottom:10px;">
        <a class="<?= $panel === 'list' ? 'active' : '' ?>" href="<?= htmlspecialchars($buildPostsUrl(['panel' => 'list', 'page' => 1]), ENT_QUOTES) ?>">Toate postările</a>
        <a class="<?= $panel === 'import' ? 'active' : '' ?>" href="<?= htmlspecialchars($buildPostsUrl(['panel' => 'import', 'page' => 1]), ENT_QUOTES) ?>">Import postări</a>
    </div>

    <?php if ($panel === 'import'): ?>
    <form method="post" action="/admin/blog/posts/import" enctype="multipart/form-data" class="users-search-row" style="margin-bottom:10px;">
        <input type="file" name="blog_posts_file" accept=".csv,.xlsx" required>
        <button class="btn" type="submit">Import postări (CSV/XLSX)</button>
        <small style="color:#64748b;">Coloane recomandate: Title, Content, Slug, Date, Status, Excerpt, Categories.</small>
    </form>
    <?php endif; ?>

    <?php if ($panel === 'list'): ?>
    <form method="get" action="/admin/blog/posts" class="admin-filters-inline" style="margin-bottom:10px;">
        <input type="hidden" name="panel" value="list">
        <div class="admin-filter-field">
            <select name="status">
            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Toate</option>
            <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Publicate</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="scheduled" <?= $status === 'scheduled' ? 'selected' : '' ?>>Programate</option>
            </select>
        </div>
        <div class="admin-filter-field admin-filter-field--search">
            <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="Caută după titlu, slug, autor...">
        </div>
        <div class="admin-filter-field">
            <select name="per_page">
            <?php foreach ($perPageOptions as $opt): ?>
                <?php $opt = (int) $opt; ?>
                <option value="<?= $opt ?>" <?= $opt === $perPage ? 'selected' : '' ?>><?= $opt ?>/pagină</option>
            <?php endforeach; ?>
            </select>
        </div>
        <div class="admin-filter-actions">
            <button class="btn" type="submit">Aplică</button>
            <a class="btn btn-secondary" href="/admin/blog/posts?panel=list">Reset</a>
        </div>
    </form>

    <div class="users-table-wrap">
        <table class="table users-table">
            <thead>
            <tr>
                <th>Titlu</th>
                <th>Autor</th>
                <th>Categorie</th>
                <th>Template</th>
                <th>Publicare</th>
                <th>Status</th>
                <th style="width:130px;">Acțiuni</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($posts === []): ?>
                <tr><td colspan="7">Nu există postări blog.</td></tr>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <?php
                    $id = (int) ($post['id'] ?? 0);
                    $slug = trim((string) ($post['slug'] ?? ''));
                    $isPublished = (int) ($post['is_published'] ?? 0) === 1;
                    $publishAt = (string) ($post['published_at'] ?? '');
                    $statusLabel = $isPublished ? 'Publicat' : 'Draft';
                    if ($isPublished && $publishAt !== '') {
                        $ts = strtotime($publishAt);
                        if ($ts !== false && $ts > time()) {
                            $statusLabel = 'Programat';
                        }
                    }
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars((string) ($post['title'] ?? 'Postare'), ENT_QUOTES) ?></strong><br>
                            <small><?= $slug !== '' ? '/blog/' . htmlspecialchars($slug, ENT_QUOTES) : '-' ?></small>
                        </td>
                        <td><?= htmlspecialchars((string) ($post['author_name'] ?? 'Fără autor'), ENT_QUOTES) ?></td>
                        <td><?php $catsLabel = trim((string) ($post['categories_label'] ?? '')); if ($catsLabel === '') { $catsLabel = trim((string) ($post['category'] ?? '')); } echo htmlspecialchars($catsLabel !== '' ? $catsLabel : '-', ENT_QUOTES); ?></td>
                        <td><?= htmlspecialchars((string) ($post['template_name'] ?? 'Implicit'), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($publishAt !== '' ? date('d.m.Y H:i', strtotime($publishAt) ?: time()) : '-', ENT_QUOTES) ?></td>
                        <td><span class="status-pill <?= $isPublished ? 'ok' : 'off' ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></span></td>
                        <td style="display:flex;gap:8px;">
                            <a class="icon-btn" href="/admin/blog/posts/editor?post=<?= $id ?>" title="Editează">✎</a>
                            <form method="post" action="/admin/blog/posts" onsubmit="return confirm('Dorești să duplici această postare?');">
                                <input type="hidden" name="action" value="duplicate">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button class="icon-btn" type="submit" title="Duplică">⧉</button>
                            </form>
                            <form method="post" action="/admin/blog/posts" onsubmit="return confirm('Muți postarea în coș?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button class="icon-btn danger" type="submit" title="Șterge">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="users-search-row" style="margin-top:10px;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <small style="color:#64748b;">Total: <?= $totalPosts ?> postări</small>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <a class="btn btn-secondary" href="<?= htmlspecialchars($buildPostsUrl(['page' => max(1, $page - 1)]), ENT_QUOTES) ?>" <?= $page <= 1 ? 'style="pointer-events:none;opacity:.6;"' : '' ?>>‹ Anterior</a>
                <?php foreach ($paginationPages as $p): ?>
                    <a class="btn <?= $p === $page ? '' : 'btn-secondary' ?>" href="<?= htmlspecialchars($buildPostsUrl(['page' => $p]), ENT_QUOTES) ?>"><?= $p ?></a>
                <?php endforeach; ?>
                <a class="btn btn-secondary" href="<?= htmlspecialchars($buildPostsUrl(['page' => min($totalPages, $page + 1)]), ENT_QUOTES) ?>" <?= $page >= $totalPages ? 'style="pointer-events:none;opacity:.6;"' : '' ?>>Următor ›</a>
            </div>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</section>
