<?php
$payload = is_array($blog ?? null) ? $blog : [];
$posts = is_array($payload['posts'] ?? null) ? $payload['posts'] : (is_array($posts ?? null) ? $posts : []);
$baseUrl = trim((string) ($payload['baseUrl'] ?? ($baseUrl ?? '/blog')));
if ($baseUrl === '') {
    $baseUrl = '/blog';
}
$paginationPayload = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : (is_array($pagination ?? null) ? $pagination : []);
$currentPage = max(1, (int) ($paginationPayload['current_page'] ?? 1));
$totalPages = max(1, (int) ($paginationPayload['total_pages'] ?? 1));
$prevPageUrl = trim((string) ($paginationPayload['prev_url'] ?? ''));
$nextPageUrl = trim((string) ($paginationPayload['next_url'] ?? ''));
$pageLinks = is_array($paginationPayload['items'] ?? null) ? $paginationPayload['items'] : [];
$buildPost = static function (array $post, string $baseUrl): array {
    $title = trim((string) ($post['title'] ?? 'Articol blog'));
    $slug = trim((string) ($post['slug'] ?? ''));
    $author = trim((string) ($post['author_name'] ?? 'Bioscem'));
    $authorAvatar = trim((string) ($post['author_avatar_url'] ?? ''));
    if ($authorAvatar === '') {
        $authorAvatar = '/assets/img/product-placeholder.svg';
    }
    $minutes = max(1, (int) ($post['reading_minutes'] ?? 4));
    $date = trim((string) ($post['published_at_label'] ?? ''));
    $excerpt = trim((string) ($post['excerpt'] ?? ''));
    if ($excerpt === '') {
        $excerpt = trim((string) ($post['content_text'] ?? ''));
        if ($excerpt !== '') {
            $excerpt = mb_substr($excerpt, 0, 180) . (mb_strlen($excerpt) > 180 ? '…' : '');
        } else {
            $excerpt = 'Descoperă articolul complet pe blog.';
        }
    }
    $image = trim((string) ($post['cover_image_url'] ?? ''));
    if ($image === '') {
        $image = '/assets/img/product-placeholder.svg';
    }
    $url = $slug !== '' ? ($baseUrl . '/' . rawurlencode($slug)) : $baseUrl;
    return [
        'title' => $title,
        'author' => $author,
        'author_avatar' => $authorAvatar,
        'minutes' => $minutes,
        'date' => $date,
        'excerpt' => $excerpt,
        'image' => $image,
        'url' => $url,
    ];
};
?>
<section class="blog-listing" data-blog-posts-token="1">
    <div class="blog-listing__shell">
        <header class="blog-listing__head">
            <span class="blog-listing__eyebrow">Jurnalul Bioscem</span>
            <h2>Blog &amp; Resurse</h2>
            <p>Articole utile despre wellness, nutriție și un stil de viață echilibrat.</p>
        </header>
        <?php if ($posts === []): ?>
            <div class="blog-listing__empty">Nu există articole publicate momentan.</div>
        <?php else: ?>
            <?php
            $featured = $buildPost((array) $posts[0], $baseUrl);
            $rest = array_slice($posts, 1);
            ?>
            <article class="blog-featured">
                <a class="blog-featured__media" href="<?= htmlspecialchars((string) $featured['url'], ENT_QUOTES) ?>">
                    <img src="<?= htmlspecialchars((string) $featured['image'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) $featured['title'], ENT_QUOTES) ?>" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                </a>
                <div class="blog-featured__body">
                    <span class="blog-featured__kicker">Articol recent</span>
                    <h3 class="blog-featured__title"><a href="<?= htmlspecialchars((string) $featured['url'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $featured['title'], ENT_QUOTES) ?></a></h3>
                    <p class="blog-featured__excerpt"><?= htmlspecialchars((string) $featured['excerpt'], ENT_QUOTES) ?></p>
                    <div class="blog-featured__meta">
                        <span class="blog-featured__author">
                            <img class="blog-featured__author-avatar" src="<?= htmlspecialchars((string) $featured['author_avatar'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) $featured['author'], ENT_QUOTES) ?>" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                            <span><?= htmlspecialchars((string) $featured['author'], ENT_QUOTES) ?></span>
                        </span>
                        <?php if ((string) $featured['date'] !== ''): ?>
                            <span class="blog-featured__meta-item">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v2H2V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm15 9v8a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3v-8h20ZM7 14a1 1 0 0 0 0 2h5a1 1 0 1 0 0-2H7Z" fill="currentColor"></path>
                                </svg>
                                <span><?= htmlspecialchars((string) $featured['date'], ENT_QUOTES) ?></span>
                            </span>
                        <?php endif; ?>
                        <span class="blog-featured__meta-item">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20Zm0 4a1 1 0 0 0-1 1v5c0 .28.12.55.33.74l3.5 3a1 1 0 1 0 1.3-1.52L13 11.67V7a1 1 0 0 0-1-1Z" fill="currentColor"></path>
                            </svg>
                            <span><?= (int) $featured['minutes'] ?> min</span>
                        </span>
                    </div>
                    <a class="blog-featured__read" href="<?= htmlspecialchars((string) $featured['url'], ENT_QUOTES) ?>">Citește articolul →</a>
                </div>
            </article>

            <?php if ($rest !== []): ?>
                <div class="blog-listing__grid">
                    <?php foreach ($rest as $post): ?>
                        <?php $item = $buildPost((array) $post, $baseUrl); ?>
                        <article class="blog-card">
                            <a class="blog-card__media" href="<?= htmlspecialchars((string) $item['url'], ENT_QUOTES) ?>">
                                <img src="<?= htmlspecialchars((string) $item['image'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?>" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                            </a>
                            <div class="blog-card__body">
                                <span class="blog-card__category">Articol</span>
                                <h3 class="blog-card__title"><a href="<?= htmlspecialchars((string) $item['url'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></a></h3>
                                <p class="blog-card__excerpt"><?= htmlspecialchars((string) $item['excerpt'], ENT_QUOTES) ?></p>
                                <div class="blog-card__meta">
                                    <span class="blog-card__author">
                                        <img class="blog-card__author-avatar" src="<?= htmlspecialchars((string) $item['author_avatar'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) $item['author'], ENT_QUOTES) ?>" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                                        <span><?= htmlspecialchars((string) $item['author'], ENT_QUOTES) ?></span>
                                    </span>
                                    <?php if ((string) $item['date'] !== ''): ?>
                                        <span class="blog-card__meta-item">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v2H2V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm15 9v8a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3v-8h20ZM7 14a1 1 0 0 0 0 2h5a1 1 0 1 0 0-2H7Z" fill="currentColor"></path>
                                            </svg>
                                            <span><?= htmlspecialchars((string) $item['date'], ENT_QUOTES) ?></span>
                                        </span>
                                    <?php endif; ?>
                                    <span class="blog-card__meta-item">
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                            <path d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20Zm0 4a1 1 0 0 0-1 1v5c0 .28.12.55.33.74l3.5 3a1 1 0 1 0 1.3-1.52L13 11.67V7a1 1 0 0 0-1-1Z" fill="currentColor"></path>
                                        </svg>
                                        <span><?= (int) $item['minutes'] ?> min</span>
                                    </span>
                                </div>
                                <a class="blog-card__read" href="<?= htmlspecialchars((string) $item['url'], ENT_QUOTES) ?>">Citește articolul →</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($totalPages > 1): ?>
                <nav class="blog-listing__pagination" aria-label="Paginare articole blog">
                    <div class="blog-listing__pagination-controls">
                        <?php if ($prevPageUrl !== ''): ?>
                            <a class="blog-listing__pagination-link" href="<?= htmlspecialchars($prevPageUrl, ENT_QUOTES) ?>" aria-label="Pagina anterioară">‹</a>
                        <?php endif; ?>
                        <?php foreach ($pageLinks as $entry): ?>
                            <?php
                            $entryType = (string) ($entry['type'] ?? '');
                            if ($entryType === 'ellipsis'):
                                ?>
                                <span class="blog-listing__pagination-ellipsis">…</span>
                                <?php
                                continue;
                            endif;
                            $pageNumber = (int) ($entry['page'] ?? 0);
                            $pageUrl = trim((string) ($entry['url'] ?? ''));
                            $isCurrent = !empty($entry['is_current']);
                            if ($pageNumber <= 0):
                                continue;
                            endif;
                            ?>
                            <?php if ($isCurrent): ?>
                                <span class="blog-listing__pagination-link is-current" aria-current="page"><?= $pageNumber ?></span>
                            <?php elseif ($pageUrl !== ''): ?>
                                <a class="blog-listing__pagination-link" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES) ?>"><?= $pageNumber ?></a>
                            <?php else: ?>
                                <span class="blog-listing__pagination-link is-disabled"><?= $pageNumber ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($nextPageUrl !== ''): ?>
                            <a class="blog-listing__pagination-link" href="<?= htmlspecialchars($nextPageUrl, ENT_QUOTES) ?>" aria-label="Pagina următoare">›</a>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<style>
.blog-listing{padding:22px 0 34px;}
.blog-listing__shell{max-width:1180px;margin:0 auto;padding:0;border:none;border-radius:0;background:transparent;}
.blog-listing__head{margin:0 0 16px;}
.blog-listing__eyebrow{display:inline-flex;margin:0 0 10px;font-family:"DM Sans",Arial,sans-serif;font-size:12px;font-weight:400;text-transform:uppercase;letter-spacing:.16em;color:#0f7b53;}
.blog-listing__head h2{margin:0;font-family:"Playfair Display",Georgia,serif;font-size:36px;line-height:1.06;font-weight:700;color:#0f172a;}
.blog-listing__head p{margin:10px 0 0;font-family:"DM Sans",Arial,sans-serif;color:#64748b;font-size:18px;line-height:1.6;font-weight:400;}
.blog-featured{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:0;border:1px solid #dbe5dd;overflow:hidden;background:#fff;margin:0 0 18px;}
.blog-featured__media{display:block;background:#f2f8ef;min-height:280px;overflow:hidden;}
.blog-featured__media img{width:100%;height:100%;object-fit:cover;display:block;}
.blog-featured__body{padding:20px 22px 18px;display:flex;flex-direction:column;gap:10px;justify-content:center;}
.blog-featured__kicker{display:inline-flex;align-self:flex-start;padding:5px 10px;border-radius:999px;background:#e7f3e8;color:#0f7b53;font-family:"DM Sans",Arial,sans-serif;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;}
.blog-featured__title{margin:0;font-family:"Playfair Display",Georgia,serif;font-size:30px;line-height:1.25;font-weight:700;color:#0f172a;}
.blog-featured__title a{color:inherit;text-decoration:none;transition:color .22s ease;}
.blog-featured__excerpt{margin:0;color:#64748b;line-height:1.68;font-size:16px;font-family:"DM Sans",Arial,sans-serif;font-weight:400;}
.blog-featured__meta{display:flex;align-items:center;gap:12px;flex-wrap:wrap;color:#64748b;font-size:12px;font-family:"DM Sans",Arial,sans-serif;font-weight:400;}
.blog-featured__author{display:inline-flex;align-items:center;gap:8px;color:#0f172a;font-size:14px;font-weight:600;}
.blog-featured__author-avatar{width:24px;height:24px;border-radius:999px;object-fit:cover;display:block;flex:0 0 24px;background:#e2e8f0;}
.blog-featured__meta-item{display:inline-flex;align-items:center;gap:5px;color:#64748b;font-size:12px;font-weight:400;}
.blog-featured__meta-item svg{width:13px;height:13px;display:block;color:#94a3b8;}
.blog-featured__read{font-family:"DM Sans",Arial,sans-serif;font-size:14px;font-weight:600;color:#0f7b53;text-decoration:none;}
.blog-listing__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;}
.blog-card{border:1px solid #e2e8f0;overflow:hidden;background:#fff;display:grid;grid-template-rows:auto 1fr;}
.site-shell .blog-listing article.blog-featured{border-radius:24px !important;overflow:hidden;}
.site-shell .blog-listing article.blog-card{border-radius:20px !important;overflow:hidden;}
.blog-card__media{display:block;aspect-ratio:16/10;background:#f8fafc;overflow:hidden;}
.blog-card__media img,.blog-featured__media img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .45s ease;}
.blog-card__body{padding:14px 14px 16px;display:grid;gap:10px;}
.blog-card__category{display:inline-flex;align-self:flex-start;font-family:"DM Sans",Arial,sans-serif;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#0f7b53;}
.blog-card__meta{display:flex;gap:12px;align-items:center;flex-wrap:wrap;font-size:12px;color:#64748b;font-family:"DM Sans",Arial,sans-serif;border-top:1px solid #e8edf2;padding-top:10px;margin-top:2px;}
.blog-card__author{display:inline-flex;align-items:center;gap:6px;color:#0f172a;font-size:12px;font-weight:500;}
.blog-card__author-avatar{width:18px;height:18px;border-radius:999px;object-fit:cover;display:block;flex:0 0 18px;background:#e2e8f0;}
.blog-card__meta-item{display:inline-flex;align-items:center;gap:5px;color:#64748b;font-size:12px;font-weight:400;}
.blog-card__meta-item svg{width:13px;height:13px;display:block;color:#94a3b8;}
.blog-card__title{margin:0;font-family:"Playfair Display",Georgia,serif;font-size:18px;line-height:1.3;font-weight:600;color:#0f172a;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.blog-card__title a{color:inherit;text-decoration:none;transition:color .22s ease;}
.blog-card__excerpt{margin:0;font-family:"DM Sans",Arial,sans-serif;font-size:14px;line-height:1.65;font-weight:400;color:#64748b;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.blog-card__read{font-family:"DM Sans",Arial,sans-serif;font-size:14px;font-weight:600;color:#0f7b53;text-decoration:none;}
.blog-featured:hover .blog-featured__media img,.blog-featured:focus-within .blog-featured__media img{transform:scale(1.05);}
.blog-card:hover .blog-card__media img,.blog-card:focus-within .blog-card__media img{transform:scale(1.07);}
.blog-featured:hover .blog-featured__title a,.blog-featured:focus-within .blog-featured__title a,.blog-card:hover .blog-card__title a,.blog-card:focus-within .blog-card__title a{color:#0f7b53;}
.blog-listing__pagination{margin-top:20px;display:flex;justify-content:center;}
.blog-listing__pagination-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:center;}
.blog-listing__pagination-link{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border:1px solid #dbe5dd;border-radius:999px;background:#fff;color:#0f7b53;text-decoration:none;font-family:"DM Sans",Arial,sans-serif;font-size:13px;font-weight:600;transition:background .2s ease,color .2s ease,border-color .2s ease;}
.blog-listing__pagination-link:hover{background:#0f7b53;color:#fff;border-color:#0f7b53;}
.blog-listing__pagination-link.is-current{background:#0f7b53;color:#fff;border-color:#0f7b53;pointer-events:none;}
.blog-listing__pagination-link.is-disabled{opacity:.45;pointer-events:none;}
.blog-listing__pagination-ellipsis{display:inline-flex;align-items:center;justify-content:center;min-width:24px;color:#64748b;font-family:"DM Sans",Arial,sans-serif;font-size:15px;font-weight:700;}
.blog-listing.is-loading .blog-listing__shell{opacity:.72;transform:translateY(6px);transition:opacity .22s ease,transform .22s ease;pointer-events:none;}
.blog-listing__empty{padding:14px;border:1px dashed #cbd5e1;border-radius:12px;background:#fff;}
@media (min-width:1024px){.blog-featured__title{font-size:30px;}}
@media (min-width:768px){.blog-listing__head h2{font-size:48px;}}
@media (min-width:1200px){.blog-listing__head h2{font-size:60px;}}
@media (max-width:1020px){.blog-listing__grid{grid-template-columns:repeat(2,minmax(0,1fr));}.blog-featured{grid-template-columns:1fr;}.blog-featured__media{min-height:220px;}}
@media (max-width:680px){.blog-listing{padding:14px 0 24px;}.blog-listing__shell{padding:0 12px;border-radius:0;}.blog-listing__head h2{font-size:36px;}.blog-listing__head p{font-size:18px;}.blog-featured__title{font-size:30px;}.blog-listing__grid{grid-template-columns:1fr;}}
</style>

<script>
(() => {
    const paginationHandlerKey = '__bioBlogTokenPaginationHandler';
    if (window[paginationHandlerKey] && typeof window[paginationHandlerKey] === 'function') {
        return;
    }

    const listingSelector = '.blog-listing[data-blog-posts-token="1"]';

    const getListing = () => document.querySelector(listingSelector);

    const isSameOriginUrl = (href) => {
        try {
            const url = new URL(href, window.location.href);
            return url.origin === window.location.origin;
        } catch (_error) {
            return false;
        }
    };

    const parseListingFromHtml = (html) => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        return doc.querySelector(listingSelector);
    };

    const setLoadingState = (listing, isLoading) => {
        if (!(listing instanceof HTMLElement)) {
            return;
        }
        listing.classList.toggle('is-loading', isLoading);
        listing.dataset.loading = isLoading ? '1' : '0';
    };

    const animateListingIn = (listing) => {
        if (!(listing instanceof HTMLElement)) {
            return;
        }
        listing.style.opacity = '0';
        listing.style.transform = 'translateY(22px)';
        listing.style.transition = 'opacity .28s ease, transform .34s cubic-bezier(.22,.61,.36,1)';
        requestAnimationFrame(() => {
            listing.style.opacity = '1';
            listing.style.transform = 'translateY(0)';
            window.setTimeout(() => {
                listing.style.transition = '';
                listing.style.opacity = '';
                listing.style.transform = '';
            }, 360);
        });
    };

    const smoothScrollToListing = (listing) => {
        if (!(listing instanceof HTMLElement)) {
            return;
        }
        const top = window.scrollY + listing.getBoundingClientRect().top - 18;
        window.scrollTo({
            top: Math.max(0, top),
            behavior: 'smooth',
        });
    };

    const navigateWithoutReload = async (href, pushState) => {
        const currentListing = getListing();
        if (!(currentListing instanceof HTMLElement)) {
            window.location.href = href;
            return;
        }
        if (currentListing.dataset.loading === '1') {
            return;
        }

        setLoadingState(currentListing, true);
        try {
            const response = await fetch(href, {
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) {
                throw new Error('Eroare la încărcare paginare.');
            }
            const html = await response.text();
            const nextListing = parseListingFromHtml(html);
            if (!(nextListing instanceof HTMLElement)) {
                throw new Error('Nu am găsit secțiunea blog în răspuns.');
            }

            currentListing.replaceWith(nextListing);
            animateListingIn(nextListing);
            smoothScrollToListing(nextListing);
            if (pushState) {
                window.history.pushState({ blogTokenPagination: true }, '', href);
            }
        } catch (_error) {
            window.location.href = href;
        } finally {
            const listingAfter = getListing();
            setLoadingState(listingAfter, false);
        }
    };

    const handlePaginationClick = (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const link = target.closest('.blog-listing__pagination a.blog-listing__pagination-link');
        if (!(link instanceof HTMLAnchorElement)) {
            return;
        }
        if (!isSameOriginUrl(link.href)) {
            return;
        }
        event.preventDefault();
        navigateWithoutReload(link.href, true);
    };
    window[paginationHandlerKey] = handlePaginationClick;
    document.addEventListener('click', handlePaginationClick);

    window.addEventListener('popstate', () => {
        navigateWithoutReload(window.location.href, false);
    });
})();
</script>
