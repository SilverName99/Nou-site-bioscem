<?php
$viewMode = in_array((string) ($viewMode ?? 'all'), ['all', 'folders'], true) ? (string) $viewMode : 'all';
$selectedFolderId = (int) ($selectedFolderId ?? 0);
$isUnassignedFilter = ((string) ($showUnassignedOnly ?? '0')) === '1';
$unassignedCount = (int) ($unassignedCount ?? 0);
$searchQuery = trim((string) ($searchQuery ?? ''));
$searchQueryParam = $searchQuery !== '' ? '&q=' . rawurlencode($searchQuery) : '';
$currentGalleryUrl = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/admin/gallery'), PHP_URL_PATH) ?? '/admin/gallery');
$currentGalleryQuery = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?? '');
if ($currentGalleryUrl !== '/admin/gallery') {
    $currentGalleryUrl = '/admin/gallery';
    $currentGalleryQuery = '';
}
$currentGalleryBackUrl = $currentGalleryUrl . ($currentGalleryQuery !== '' ? ('?' . $currentGalleryQuery) : '');
?>

<section class="panel">
    <div class="section-head">
        <div>
            <h1>Galerie</h1>
            <p>Gestionează imagini și videoclipuri mp4.</p>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-secondary" type="button" id="open-folder-modal">+ Adaugă folder</button>
            <button class="btn" type="button" id="open-gallery-modal">+ Adaugă media</button>
        </div>
    </div>

    <div class="gallery-view-switch">
        <a href="/admin/gallery?view=all<?= $selectedFolderId > 0 ? '&folder=' . $selectedFolderId : '' ?><?= $isUnassignedFilter ? '&folder=unassigned' : '' ?><?= $searchQueryParam ?>" class="btn <?= $viewMode === 'all' ? '' : 'btn-secondary' ?>">Toate pozele</a>
        <a href="/admin/gallery?view=folders<?= $searchQueryParam ?>" class="btn <?= $viewMode === 'folders' ? '' : 'btn-secondary' ?>">Foldere</a>
        <?php if ($viewMode === 'all'): ?>
            <button type="button" class="btn btn-secondary" id="toggle-folder-shelf">↑</button>
        <?php endif; ?>
    </div>

    <form method="get" action="/admin/gallery" class="gallery-search-form">
        <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode, ENT_QUOTES) ?>">
        <?php if ($selectedFolderId > 0): ?>
            <input type="hidden" name="folder" value="<?= (int) $selectedFolderId ?>">
        <?php endif; ?>
        <input type="text" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>" placeholder="Caută fișiere în galerie...">
        <button class="btn btn-secondary" type="submit">Caută</button>
        <?php if ($searchQuery !== ''): ?>
            <a class="btn btn-secondary" href="/admin/gallery?view=<?= htmlspecialchars($viewMode, ENT_QUOTES) ?><?= $selectedFolderId > 0 ? '&folder=' . $selectedFolderId : '' ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="folder-shelf <?= $viewMode === 'folders' ? 'as-grid' : '' ?>" id="folder-shelf">
        <article class="folder-card folder-drop-target <?= $isUnassignedFilter ? 'active' : '' ?>" data-folder-id="0">
            <div class="folder-card-thumb empty">
                <span>Fără folder</span>
            </div>
            <div class="folder-card-info">
                <strong>Fără folder</strong>
                <span><?= $unassignedCount ?> fișiere</span>
            </div>
            <a class="btn btn-secondary" href="/admin/gallery?view=all&folder=unassigned<?= $searchQueryParam ?>">Deschide</a>
        </article>

        <?php foreach ($folders as $folder): ?>
            <article class="folder-card folder-drop-target <?= $selectedFolderId === (int) $folder['id'] ? 'active' : '' ?>" data-folder-id="<?= (int) $folder['id'] ?>">
                <div class="folder-card-actions">
                    <form method="post" action="/admin/gallery/folders/<?= (int) $folder['id'] ?>/delete" onsubmit="return confirm('Ștergi folderul? Fișierele vor rămâne în galerie, fără folder.');">
                        <button class="icon-btn danger" type="submit" title="Șterge folder">🗑</button>
                    </form>
                </div>
                <div class="folder-card-thumb">
                    <?php if (($folder['cover_media_type'] ?? '') === 'video' && !empty($folder['cover_url'])): ?>
                        <video muted preload="metadata">
                            <source src="<?= htmlspecialchars((string) $folder['cover_url'], ENT_QUOTES) ?>" type="video/mp4">
                        </video>
                    <?php elseif (!empty($folder['cover_url'])): ?>
                        <img src="<?= htmlspecialchars((string) $folder['cover_url'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) $folder['name'], ENT_QUOTES) ?>" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                    <?php else: ?>
                        <span>Folder</span>
                    <?php endif; ?>
                </div>
                <div class="folder-card-info">
                    <strong><?= htmlspecialchars((string) $folder['name'], ENT_QUOTES) ?></strong>
                    <span><?= (int) $folder['items_count'] ?> fișiere</span>
                </div>
                <a class="btn btn-secondary" href="/admin/gallery?view=all&folder=<?= (int) $folder['id'] ?><?= $searchQueryParam ?>">Deschide</a>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($viewMode === 'all' && ($selectedFolderId > 0 || $isUnassignedFilter)): ?>
        <div class="gallery-filter-note">
            Vezi doar fișierele din folderul selectat<?= $isUnassignedFilter ? ' (Fără folder)' : '' ?>.
            <a href="/admin/gallery?view=all<?= $searchQueryParam ?>">Arată toate</a>
        </div>
    <?php endif; ?>

    <?php if ($viewMode === 'folders' && $folders === []): ?>
        <div class="gallery-empty">
            <div class="icon">🗂</div>
            <h3>Niciun folder</h3>
            <p>Creează primul folder din butonul „+ Adaugă folder”.</p>
        </div>
    <?php elseif ($viewMode !== 'folders' && $images === []): ?>
        <div class="gallery-empty">
            <div class="icon">🖼️</div>
            <h3>Niciun fișier</h3>
            <p><?= $searchQuery !== '' ? 'Nu există rezultate pentru căutarea ta.' : 'Adaugă imagini sau clipuri mp4 în galerie.' ?></p>
        </div>
    <?php elseif ($viewMode !== 'folders'): ?>
        <form method="post" action="/admin/gallery/bulk-delete" id="gallery-bulk-form">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" id="select-all-gallery">
                    Selectează toate
                </label>
                <button class="icon-btn danger" type="submit" title="Șterge selectatele" onclick="return confirm('Ștergi fișierele selectate?');">🗑</button>
            </div>
            <div class="gallery-grid">
                <?php foreach ($images as $image): ?>
                    <?php
                    $mediaId = (int) ($image['id'] ?? 0);
                    $mediaUrl = (string) ($image['image_url'] ?? '');
                    $mediaTitle = (string) ($image['title'] ?? '');
                    $mediaAlt = (string) ($image['alt_text'] ?? '');
                    $mediaFolder = (string) ($image['folder_name'] ?? '');
                    $mediaType = (string) ($image['media_type'] ?? 'image');
                    $mediaCreatedAt = (string) ($image['created_at'] ?? '');
                    $mediaIsActive = (int) ($image['is_active'] ?? 0);
                    $fileMissing = (bool) ($image['file_missing'] ?? false);
                    ?>
                    <article
                        class="gallery-item media-draggable<?= $fileMissing ? ' gallery-item--missing' : '' ?>"
                        draggable="true"
                        data-media-id="<?= $mediaId ?>"
                        data-media-url="<?= htmlspecialchars($mediaUrl, ENT_QUOTES) ?>"
                        data-media-title="<?= htmlspecialchars($mediaTitle, ENT_QUOTES) ?>"
                        data-media-alt="<?= htmlspecialchars($mediaAlt, ENT_QUOTES) ?>"
                        data-media-folder="<?= htmlspecialchars($mediaFolder, ENT_QUOTES) ?>"
                        data-media-type="<?= htmlspecialchars($mediaType, ENT_QUOTES) ?>"
                        data-media-created="<?= htmlspecialchars($mediaCreatedAt, ENT_QUOTES) ?>"
                        data-media-active="<?= $mediaIsActive ?>"
                    >
                        <label style="padding:8px 10px;display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" name="image_ids[]" value="<?= $mediaId ?>" class="gallery-item-checkbox">
                            Selectează
                            <?php if ($fileMissing): ?>
                                <span style="margin-left:auto;background:#ef4444;color:#fff;font-size:11px;font-weight:600;padding:2px 7px;border-radius:4px;" title="Fișierul nu există pe server. Poți șterge această înregistrare sau re-încărca imaginea.">Lipsă</span>
                            <?php endif; ?>
                        </label>
                        <?php if ($mediaType === 'video'): ?>
                            <video controls preload="metadata">
                                <source src="<?= htmlspecialchars($mediaUrl, ENT_QUOTES) ?>" type="video/mp4">
                            </video>
                        <?php else: ?>
                            <img
                                src="<?= htmlspecialchars($mediaUrl, ENT_QUOTES) ?>"
                                alt="<?= htmlspecialchars($mediaAlt, ENT_QUOTES) ?>"
                                onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';"
                            >
                        <?php endif; ?>
                        <div class="info">
                            <strong><?= htmlspecialchars($mediaTitle, ENT_QUOTES) ?></strong>
                            <p><?= htmlspecialchars(($mediaAlt !== '' ? $mediaAlt : 'Fără alt text'), ENT_QUOTES) ?></p>
                            <p class="muted" style="font-size:12px;">
                                <?= htmlspecialchars(($mediaFolder !== '' ? $mediaFolder : 'Fără folder'), ENT_QUOTES) ?> • <?= htmlspecialchars((string) strtoupper($mediaType), ENT_QUOTES) ?>
                            </p>
                        </div>
                        <div style="padding:0 10px 10px;display:flex;justify-content:flex-end;gap:8px;">
                            <button
                                class="btn btn-secondary"
                                type="button"
                                data-action="show-media-edit"
                                title="Editează media"
                            >
                                Editează
                            </button>
                            <button
                                class="btn btn-secondary"
                                type="button"
                                data-action="show-media-details"
                                title="Vezi detalii"
                            >
                                Detalii
                            </button>
                            <button
                                class="icon-btn danger"
                                type="submit"
                                formaction="/admin/gallery/<?= $mediaId ?>/delete"
                                formmethod="post"
                                onclick="return confirm('Ștergi fișierul?');"
                            >
                                🗑
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </form>
    <?php endif; ?>
</section>

<div class="modal-overlay" id="gallery-modal">
    <div class="modal-card gallery-modal-card">
        <div class="modal-head">
            <h3>Adaugă media</h3>
            <button type="button" class="icon-btn" id="close-gallery-modal">✕</button>
        </div>
        <form method="post" action="/admin/gallery" class="form-grid" enctype="multipart/form-data" id="gallery-form">
            <div class="field" style="grid-column:1/-1;">
                <label>Titlu *</label>
                <input type="text" name="title" required>
            </div>
            <div class="field">
                <label>Folder</label>
                <select name="folder_id">
                    <option value="">Fără folder</option>
                    <?php foreach ($folders as $folder): ?>
                        <option value="<?= (int) $folder['id'] ?>"><?= htmlspecialchars((string) $folder['name'], ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Alt text</label>
                <input type="text" name="alt_text">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label class="upload-dropzone" for="gallery-image-file" id="gallery-dropzone">
                    <input type="file" id="gallery-image-file" name="image_file" accept="image/*,video/mp4" hidden>
                    <span class="icon">⤴</span>
                    <strong>Upload media (imagine/mp4)</strong>
                    <small>Click pentru alegere sau drag & drop</small>
                    <em id="gallery-file-name">Niciun fișier selectat</em>
                </label>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>...sau URL media (imagine/mp4)</label>
                <input type="text" name="image_url" placeholder="/assets/img/product-placeholder.svg">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="is_active" value="1" checked>
                    Activă
                </label>
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;">
                <button class="btn" type="submit">Salvează media</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="folder-modal">
    <div class="modal-card" style="max-width:480px;">
        <div class="modal-head">
            <h3>Adaugă folder</h3>
            <button type="button" class="icon-btn" id="close-folder-modal">✕</button>
        </div>
        <form method="post" action="/admin/gallery/folders" class="form-grid" id="folder-form">
            <div class="field" style="grid-column:1/-1;">
                <label>Nume folder *</label>
                <input type="text" name="name" required placeholder="ex: Campanie primăvară">
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;">
                <button class="btn" type="submit">Creează folder</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="gallery-detail-modal">
    <div class="modal-card gallery-detail-modal-card">
        <div class="modal-head">
            <h3>Detalii media</h3>
            <button type="button" class="icon-btn" id="close-gallery-detail-modal">✕</button>
        </div>
        <div class="gallery-detail-modal-grid">
            <div class="gallery-detail-preview" id="gallery-detail-preview"></div>
            <div class="gallery-detail-meta">
                <h4 id="gallery-detail-title">Media</h4>
                <dl>
                    <dt>ID</dt>
                    <dd id="gallery-detail-id">-</dd>
                    <dt>Tip</dt>
                    <dd id="gallery-detail-type">-</dd>
                    <dt>Folder</dt>
                    <dd id="gallery-detail-folder">-</dd>
                    <dt>Alt text</dt>
                    <dd id="gallery-detail-alt">-</dd>
                    <dt>Status</dt>
                    <dd id="gallery-detail-status">-</dd>
                    <dt>Creat la</dt>
                    <dd id="gallery-detail-created">-</dd>
                    <dt>Link imagine</dt>
                    <dd>
                        <a id="gallery-detail-link" href="#" target="_blank" rel="noopener">Deschide fișierul</a>
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="gallery-edit-modal">
    <div class="modal-card" style="max-width:620px;">
        <div class="modal-head">
            <h3>Editează media</h3>
            <button type="button" class="icon-btn" id="close-gallery-edit-modal">✕</button>
        </div>
        <form method="post" action="/admin/gallery/0/update" class="form-grid" id="gallery-edit-form">
            <input type="hidden" name="back_url" value="<?= htmlspecialchars($currentGalleryBackUrl, ENT_QUOTES) ?>">
            <div class="field" style="grid-column:1/-1;">
                <label>Titlu *</label>
                <input type="text" name="title" id="gallery-edit-title" required>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Alt text</label>
                <input type="text" name="alt_text" id="gallery-edit-alt">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Status imagine</label>
                <select name="is_active" id="gallery-edit-status">
                    <option value="1">Activă</option>
                    <option value="0">Inactivă</option>
                </select>
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                <button class="btn btn-secondary" type="button" id="cancel-gallery-edit">Anulează</button>
                <button class="btn" type="submit">Salvează modificările</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const mediaModal = document.getElementById('gallery-modal');
    const openMediaBtn = document.getElementById('open-gallery-modal');
    const closeMediaBtn = document.getElementById('close-gallery-modal');
    const folderModal = document.getElementById('folder-modal');
    const openFolderBtn = document.getElementById('open-folder-modal');
    const closeFolderBtn = document.getElementById('close-folder-modal');
    const form = document.getElementById('gallery-form');
    const titleInput = form?.querySelector('input[name="title"]');
    const fileInput = document.getElementById('gallery-image-file');
    const fileName = document.getElementById('gallery-file-name');
    const dropzone = document.getElementById('gallery-dropzone');
    const folderShelf = document.getElementById('folder-shelf');
    const toggleFolderShelf = document.getElementById('toggle-folder-shelf');
    const selectAll = document.getElementById('select-all-gallery');
    const itemChecks = document.querySelectorAll('.gallery-item-checkbox');
    const draggableMedia = document.querySelectorAll('.media-draggable');
    let draggedMediaId = '';
    let dragScrollRaf = 0;
    let dragScrollDirection = 0;
    const folderTargets = document.querySelectorAll('.folder-drop-target');
    const detailModal = document.getElementById('gallery-detail-modal');
    const closeDetailModalBtn = document.getElementById('close-gallery-detail-modal');
    const detailPreview = document.getElementById('gallery-detail-preview');
    const detailTitle = document.getElementById('gallery-detail-title');
    const detailId = document.getElementById('gallery-detail-id');
    const detailType = document.getElementById('gallery-detail-type');
    const detailFolder = document.getElementById('gallery-detail-folder');
    const detailAlt = document.getElementById('gallery-detail-alt');
    const detailStatus = document.getElementById('gallery-detail-status');
    const detailCreated = document.getElementById('gallery-detail-created');
    const detailLink = document.getElementById('gallery-detail-link');
    const editModal = document.getElementById('gallery-edit-modal');
    const editForm = document.getElementById('gallery-edit-form');
    const closeEditModalBtn = document.getElementById('close-gallery-edit-modal');
    const cancelEditBtn = document.getElementById('cancel-gallery-edit');
    const editTitle = document.getElementById('gallery-edit-title');
    const editAlt = document.getElementById('gallery-edit-alt');
    const editStatus = document.getElementById('gallery-edit-status');

    openMediaBtn?.addEventListener('click', () => mediaModal.classList.add('open'));
    closeMediaBtn?.addEventListener('click', () => mediaModal.classList.remove('open'));
    mediaModal?.addEventListener('click', (e) => { if (e.target === mediaModal) mediaModal.classList.remove('open'); });

    openFolderBtn?.addEventListener('click', () => folderModal.classList.add('open'));
    closeFolderBtn?.addEventListener('click', () => folderModal.classList.remove('open'));
    folderModal?.addEventListener('click', (e) => { if (e.target === folderModal) folderModal.classList.remove('open'); });

    const slugifyFileBase = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9\s_-]/g, ' ')
        .trim()
        .replace(/[\s_-]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .toLowerCase();
    const titleFromFilename = (filename) => {
        const raw = String(filename || '').trim();
        if (raw === '') return '';
        const dot = raw.lastIndexOf('.');
        const base = dot > 0 ? raw.slice(0, dot) : raw;
        return base.replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
    };
    const syncTitleFromFile = (file) => {
        if (!(file instanceof File) || !(titleInput instanceof HTMLInputElement)) {
            return;
        }
        if (titleInput.value.trim() !== '') {
            return;
        }
        const suggested = titleFromFilename(file.name);
        if (suggested !== '') {
            titleInput.value = suggested;
        }
    };
    fileInput?.addEventListener('change', () => {
        const selectedFile = fileInput.files?.[0];
        fileName.textContent = selectedFile?.name || 'Niciun fișier selectat';
        if (selectedFile) {
            syncTitleFromFile(selectedFile);
        }
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
        dropzone?.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropzone.classList.add('drag-over');
        });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
        dropzone?.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropzone.classList.remove('drag-over');
        });
    });

    dropzone?.addEventListener('drop', (e) => {
        if (!e.dataTransfer?.files?.length) return;
        fileInput.files = e.dataTransfer.files;
        const selectedFile = e.dataTransfer.files[0];
        fileName.textContent = selectedFile.name;
        syncTitleFromFile(selectedFile);
    });

    form?.addEventListener('submit', () => {
        mediaModal.classList.remove('open');
    });

    if (toggleFolderShelf && folderShelf) {
        const key = 'admin_gallery_folder_shelf_hidden';
        const isHidden = window.localStorage.getItem(key) === '1';
        if (isHidden) {
            folderShelf.classList.add('is-collapsed');
            toggleFolderShelf.textContent = '↓';
        }
        toggleFolderShelf.addEventListener('click', () => {
            folderShelf.classList.toggle('is-collapsed');
            const hidden = folderShelf.classList.contains('is-collapsed');
            toggleFolderShelf.textContent = hidden ? '↓' : '↑';
            window.localStorage.setItem(key, hidden ? '1' : '0');
        });
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            itemChecks.forEach((el) => { el.checked = selectAll.checked; });
        });
    }

    const stopDragAutoScroll = () => {
        dragScrollDirection = 0;
        if (dragScrollRaf !== 0) {
            window.cancelAnimationFrame(dragScrollRaf);
            dragScrollRaf = 0;
        }
    };

    const startDragAutoScroll = () => {
        if (dragScrollRaf !== 0) {
            return;
        }
        const tick = () => {
            if (dragScrollDirection !== 0) {
                window.scrollBy(0, dragScrollDirection * 16);
            }
            dragScrollRaf = window.requestAnimationFrame(tick);
        };
        dragScrollRaf = window.requestAnimationFrame(tick);
    };

    const updateDragAutoScroll = (clientY) => {
        if (!draggedMediaId) {
            stopDragAutoScroll();
            return;
        }
        const viewportHeight = window.innerHeight || 0;
        const edgeThreshold = Math.max(70, Math.round(viewportHeight * 0.14));
        let nextDirection = 0;
        if (clientY < edgeThreshold) {
            nextDirection = -1;
        } else if (clientY > viewportHeight - edgeThreshold) {
            nextDirection = 1;
        }
        dragScrollDirection = nextDirection;
        if (dragScrollDirection === 0) {
            stopDragAutoScroll();
            return;
        }
        startDragAutoScroll();
    };

    document.addEventListener('dragover', (event) => {
        updateDragAutoScroll(event.clientY);
    });
    document.addEventListener('drop', stopDragAutoScroll);

    draggableMedia.forEach((card) => {
        card.addEventListener('dragstart', (event) => {
            const mediaId = String(card.dataset.mediaId || '').trim();
            draggedMediaId = mediaId;
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                try {
                    event.dataTransfer.setData('text/plain', mediaId);
                } catch (_) {
                }
            }
            card.classList.add('dragging');
        });
        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
            draggedMediaId = '';
            stopDragAutoScroll();
        });
    });

    const moveMediaToFolder = async (mediaId, folderId) => {
        const body = new URLSearchParams({
            media_id: String(mediaId),
            folder_id: String(folderId),
        });

        const response = await fetch('/admin/gallery/move-folder', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            body,
        });

        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'Nu s-a putut muta fișierul.');
        }
    };

    folderTargets.forEach((target) => {
        ['dragenter', 'dragover'].forEach((eventName) => {
            target.addEventListener(eventName, (event) => {
                event.preventDefault();
                target.classList.add('drag-over');
            });
        });

        ['dragleave', 'drop'].forEach((eventName) => {
            target.addEventListener(eventName, (event) => {
                event.preventDefault();
                target.classList.remove('drag-over');
            });
        });

        target.addEventListener('drop', async (event) => {
            const mediaId = String(event.dataTransfer?.getData('text/plain') || '').trim() || draggedMediaId;
            if (!mediaId) return;
            const folderId = Number(target.dataset.folderId || 0);
            try {
                await moveMediaToFolder(mediaId, folderId);
                window.location.reload();
            } catch (err) {
                alert(err instanceof Error ? err.message : 'Eroare la mutarea fișierului.');
            }
        });
    });

    const openDetailModal = (card) => {
        if (
            !(card instanceof HTMLElement)
            || !(detailModal instanceof HTMLElement)
            || !(detailPreview instanceof HTMLElement)
            || !(detailTitle instanceof HTMLElement)
            || !(detailId instanceof HTMLElement)
            || !(detailType instanceof HTMLElement)
            || !(detailFolder instanceof HTMLElement)
            || !(detailAlt instanceof HTMLElement)
            || !(detailStatus instanceof HTMLElement)
            || !(detailCreated instanceof HTMLElement)
            || !(detailLink instanceof HTMLAnchorElement)
        ) {
            return;
        }
        const mediaId = String(card.dataset.mediaId || '').trim();
        const mediaUrl = String(card.dataset.mediaUrl || '').trim();
        const mediaType = String(card.dataset.mediaType || 'image').trim().toLowerCase();
        const mediaTitle = String(card.dataset.mediaTitle || 'Media').trim();
        const mediaAlt = String(card.dataset.mediaAlt || '').trim();
        const mediaFolder = String(card.dataset.mediaFolder || '').trim();
        const mediaCreated = String(card.dataset.mediaCreated || '').trim();
        const mediaActive = String(card.dataset.mediaActive || '0') === '1';

        detailTitle.textContent = mediaTitle !== '' ? mediaTitle : 'Media';
        detailId.textContent = mediaId !== '' ? mediaId : '-';
        detailType.textContent = mediaType.toUpperCase();
        detailFolder.textContent = mediaFolder !== '' ? mediaFolder : 'Fără folder';
        detailAlt.textContent = mediaAlt !== '' ? mediaAlt : 'Fără alt text';
        detailStatus.textContent = mediaActive ? 'Activă' : 'Inactivă';
        detailCreated.textContent = mediaCreated !== '' ? mediaCreated : '-';
        detailLink.href = mediaUrl !== '' ? mediaUrl : '#';
        detailLink.textContent = mediaUrl !== '' ? mediaUrl : 'Fișier indisponibil';

        detailPreview.innerHTML = '';
        if (mediaType === 'video') {
            const video = document.createElement('video');
            video.controls = true;
            video.preload = 'metadata';
            video.src = mediaUrl;
            detailPreview.appendChild(video);
        } else {
            const image = document.createElement('img');
            image.src = mediaUrl;
            image.alt = mediaAlt || mediaTitle || 'Imagine galerie';
            image.onerror = () => {
                image.onerror = null;
                image.src = '/assets/img/product-placeholder.svg';
            };
            detailPreview.appendChild(image);
        }

        detailModal.classList.add('open');
    };

    const openEditModal = (card) => {
        if (
            !(card instanceof HTMLElement)
            || !(editModal instanceof HTMLElement)
            || !(editForm instanceof HTMLFormElement)
            || !(editTitle instanceof HTMLInputElement)
            || !(editAlt instanceof HTMLInputElement)
            || !(editStatus instanceof HTMLSelectElement)
        ) {
            return;
        }

        const mediaId = Number(card.dataset.mediaId || 0);
        if (!Number.isFinite(mediaId) || mediaId <= 0) {
            return;
        }
        const mediaTitle = String(card.dataset.mediaTitle || '').trim();
        const mediaAlt = String(card.dataset.mediaAlt || '').trim();
        const mediaActive = String(card.dataset.mediaActive || '0') === '1' ? '1' : '0';

        editForm.action = `/admin/gallery/${mediaId}/update`;
        editTitle.value = mediaTitle;
        editAlt.value = mediaAlt;
        editStatus.value = mediaActive;
        editModal.classList.add('open');
    };

    closeDetailModalBtn?.addEventListener('click', () => detailModal?.classList.remove('open'));
    detailModal?.addEventListener('click', (event) => {
        if (event.target === detailModal) {
            detailModal.classList.remove('open');
        }
    });
    const closeEditModal = () => {
        editModal?.classList.remove('open');
    };
    closeEditModalBtn?.addEventListener('click', closeEditModal);
    cancelEditBtn?.addEventListener('click', closeEditModal);
    editModal?.addEventListener('click', (event) => {
        if (event.target === editModal) {
            closeEditModal();
        }
    });
    editForm?.addEventListener('submit', () => closeEditModal());

    const galleryGrid = document.querySelector('.gallery-grid');
    galleryGrid?.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        const detailsButton = target.closest('[data-action="show-media-details"]');
        if (detailsButton instanceof HTMLElement) {
            const detailsCard = detailsButton.closest('.gallery-item');
            if (detailsCard instanceof HTMLElement) {
                openDetailModal(detailsCard);
            }
            return;
        }
        const editButton = target.closest('[data-action="show-media-edit"]');
        if (editButton instanceof HTMLElement) {
            const editCard = editButton.closest('.gallery-item');
            if (editCard instanceof HTMLElement) {
                openEditModal(editCard);
            }
            return;
        }
        if (target.closest('button, input, label, form, a')) {
            return;
        }
        const card = target.closest('.gallery-item');
        if (!(card instanceof HTMLElement)) {
            return;
        }
        openDetailModal(card);
    });
})();
</script>
