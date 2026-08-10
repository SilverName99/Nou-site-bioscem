<section class="panel">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
            <h1>Coș produse</h1>
            <p>Refaci produsele șterse sau le elimini definitiv.</p>
        </div>
        <a class="btn btn-secondary" href="/admin/products">Înapoi la produse</a>
    </div>

    <table class="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Produs</th>
            <th>SKU</th>
            <th>Categorie</th>
            <th>Șters la</th>
            <th>Acțiuni</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($products as $product): ?>
            <tr>
                <td><?= (int) $product['id'] ?></td>
                <td><?= htmlspecialchars((string) $product['name'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($product['sku'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($product['category'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($product['deleted_at'] ?? ''), ENT_QUOTES) ?></td>
                <td>
                    <div style="display:flex;gap:8px;">
                        <form method="post" action="/admin/products/<?= (int) $product['id'] ?>/restore">
                            <button class="btn btn-secondary" type="submit">Refacere</button>
                        </form>
                        <form method="post" action="/admin/products/<?= (int) $product['id'] ?>/force-delete" onsubmit="return confirm('Ștergere definitivă produs?');">
                            <button class="icon-btn danger" type="submit" title="Ștergere definitivă">🗑</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
