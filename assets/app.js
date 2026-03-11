'use strict';

const API_URL      = 'api.php';
const SYNC_URL     = 'sync.php';
const SYNC_INTERVAL = window.APP_CONFIG?.syncInterval ?? 60000;

const feed         = document.getElementById('feed');
const skeletonList = document.getElementById('skeletonList');
const emptyState   = document.getElementById('emptyState');
const errorState   = document.getElementById('errorState');
const loadMoreWrap = document.getElementById('loadMoreWrap');
const loadMoreBtn  = document.getElementById('loadMoreBtn');
const syncStatus   = document.getElementById('syncStatus');
const channelName  = document.getElementById('channelName');
const sentinel     = document.getElementById('sentinel');
const searchInput  = document.getElementById('searchInput');

let currentPage   = 1;
let hasMore       = false;
let isLoading     = false;
let latestTgId    = 0;
let pollTimer     = null;
let currentSearch = '';

// ─── Утилиты ──────────────────────────────────────────────────────────────────

function formatDate(dateStr) {
    const d = new Date(dateStr);
    const now = new Date();
    const diff = (now - d) / 1000;

    if (diff < 60)    return 'только что';
    if (diff < 3600)  return Math.floor(diff / 60) + ' мин. назад';
    if (diff < 86400) return Math.floor(diff / 3600) + ' ч. назад';

    return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
        + ', '
        + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function formatViews(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1).replace('.0', '') + 'M';
    if (n >= 1000)    return (n / 1000).toFixed(1).replace('.0', '') + 'K';
    return n.toString();
}

function linkify(text) {
    if (!text) return '';
    const safe = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    return safe.replace(
        /(https?:\/\/[^\s<>"]+)/g,
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
    );
}

function setSyncStatus(msg, type = '') {
    syncStatus.textContent = msg;
    syncStatus.className   = 'sync-status ' + type;
}

// ─── Лайтбокс ─────────────────────────────────────────────────────────────────

let lbImages = [];
let lbIndex  = 0;

const lightbox = (() => {
    const el = document.createElement('div');
    el.id = 'lightbox';
    el.className = 'lightbox';
    el.innerHTML = `
        <button class="lb-close" aria-label="Закрыть">&#x2715;</button>
        <button class="lb-nav lb-prev" aria-label="Назад">&#8249;</button>
        <img class="lb-img" src="" alt="">
        <button class="lb-nav lb-next" aria-label="Вперёд">&#8250;</button>
        <div class="lb-counter"></div>
    `;
    document.body.appendChild(el);
    return el;
})();

const lbImg     = lightbox.querySelector('.lb-img');
const lbCounter = lightbox.querySelector('.lb-counter');
const lbPrev    = lightbox.querySelector('.lb-prev');
const lbNext    = lightbox.querySelector('.lb-next');

function lbShow() {
    lbImg.src = lbImages[lbIndex];
    lbCounter.textContent = lbImages.length > 1 ? `${lbIndex + 1} / ${lbImages.length}` : '';
    lbPrev.hidden = lbImages.length <= 1;
    lbNext.hidden = lbImages.length <= 1;
}

function openLightbox(images, index = 0) {
    lbImages = images;
    lbIndex  = index;
    lbShow();
    lightbox.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    lightbox.classList.remove('active');
    document.body.style.overflow = '';
    lbImg.src = '';
}

lightbox.querySelector('.lb-close').addEventListener('click', closeLightbox);
lbPrev.addEventListener('click', () => { lbIndex = (lbIndex - 1 + lbImages.length) % lbImages.length; lbShow(); });
lbNext.addEventListener('click', () => { lbIndex = (lbIndex + 1) % lbImages.length; lbShow(); });
lightbox.addEventListener('click', e => { if (e.target === lightbox) closeLightbox(); });
document.addEventListener('keydown', e => {
    if (!lightbox.classList.contains('active')) return;
    if (e.key === 'Escape')      closeLightbox();
    if (e.key === 'ArrowLeft')  { lbIndex = (lbIndex - 1 + lbImages.length) % lbImages.length; lbShow(); }
    if (e.key === 'ArrowRight') { lbIndex = (lbIndex + 1) % lbImages.length; lbShow(); }
});

// ─── Клики по фото (делегирование) ────────────────────────────────────────────

feed.addEventListener('click', e => {
    // Клик по фото в альбоме
    const albumItem = e.target.closest('.album-item');
    if (albumItem) {
        const album = albumItem.closest('.album');
        const files = album._files;
        const items = Array.from(album.querySelectorAll('.album-item'));
        openLightbox(files, items.indexOf(albumItem));
        return;
    }
    // Клик по одиночному фото
    if (e.target.matches('img.post-media')) {
        const files = e.target._files;
        if (files) openLightbox(files, 0);
    }
});

// ─── Рендер альбома ───────────────────────────────────────────────────────────

function renderAlbum(files) {
    const MAX_VISIBLE = 4;
    const visible = files.slice(0, MAX_VISIBLE);
    const extra   = files.length - MAX_VISIBLE;

    const div = document.createElement('div');
    div.className = `album album-${visible.length}`;
    div._files = files; // все URL для лайтбокса

    visible.forEach((url, i) => {
        const item = document.createElement('div');
        item.className = 'album-item';

        const img = document.createElement('img');
        img.src     = url;
        img.alt     = '';
        img.loading = 'lazy';
        item.appendChild(img);

        // Оверлей "+N" на последнем видимом, если есть скрытые
        if (i === visible.length - 1 && extra > 0) {
            const more = document.createElement('div');
            more.className   = 'album-more';
            more.textContent = `+${extra}`;
            item.appendChild(more);
        }

        div.appendChild(item);
    });

    return div;
}

// ─── Рендер поста ─────────────────────────────────────────────────────────────

function renderPost(post) {
    const article = document.createElement('article');
    article.className = 'post' + (post.media_type === 'none' ? ' text-only' : '');
    article.dataset.tgId = post.tg_id;

    let mediaEl = null;
    const files = post.media_files;

    if (post.media_type === 'photo' && files && files.length > 1) {
        // Альбом из нескольких фото
        mediaEl = renderAlbum(files);

    } else if (post.media_type === 'photo' && post.media_url) {
        // Одиночное фото с лайтбоксом
        const img = document.createElement('img');
        img.className = 'post-media';
        img.src       = post.media_url;
        img.alt       = 'Изображение';
        img.loading   = 'lazy';
        img._files    = files || [post.media_url];
        mediaEl = img;

    } else if (post.media_type === 'video' && post.media_url) {
        const video = document.createElement('video');
        video.className = 'post-media';
        video.controls  = true;
        video.preload   = 'none';
        if (post.thumb_url) video.poster = post.thumb_url;
        video.src = post.media_url;
        mediaEl = video;

    } else if (post.media_type === 'animation' && post.media_url) {
        const video = document.createElement('video');
        video.className = 'post-media';
        video.autoplay  = true;
        video.loop      = true;
        video.muted     = true;
        video.playsInline = true;
        video.src = post.media_url;
        mediaEl = video;

    } else if (post.media_type !== 'none') {
        const ph = document.createElement('div');
        ph.className   = 'post-media-placeholder';
        ph.textContent = `[${post.media_type}]`;
        mediaEl = ph;
    }

    if (mediaEl) article.appendChild(mediaEl);

    const body = document.createElement('div');
    body.className = 'post-body';
    if (post.text) {
        const textDiv = document.createElement('div');
        textDiv.className = 'post-text';
        textDiv.innerHTML = linkify(post.text);
        body.appendChild(textDiv);
    }
    const footer = document.createElement('div');
    footer.className = 'post-footer';

    const time = document.createElement('time');
    time.className   = 'post-date';
    time.dateTime    = post.date;
    time.textContent = formatDate(post.date);
    footer.appendChild(time);

    const footerRight = document.createElement('div');
    footerRight.className = 'post-footer-right';

    if (post.views) {
        const views = document.createElement('span');
        views.className   = 'post-views';
        views.textContent = '👁 ' + formatViews(post.views);
        footerRight.appendChild(views);
    }

    const link = document.createElement('a');
    link.className = 'post-link';
    link.href      = post.tg_link;
    link.target    = '_blank';
    link.rel       = 'noopener noreferrer';
    link.textContent = 'Открыть в Telegram';
    footerRight.appendChild(link);

    footer.appendChild(footerRight);
    body.appendChild(footer);
    article.appendChild(body);

    return article;
}

// ─── Загрузка постов ──────────────────────────────────────────────────────────

async function fetchPosts(page = 1) {
    if (isLoading) return;
    isLoading = true;

    if (page === 1) {
        skeletonList.hidden = false;
        emptyState.hidden   = true;
        errorState.hidden   = true;
        loadMoreWrap.hidden = true;
    } else {
        loadMoreBtn.disabled    = true;
        loadMoreBtn.textContent = 'Загрузка...';
    }

    try {
        const params = `page=${page}&limit=20` + (currentSearch ? `&search=${encodeURIComponent(currentSearch)}` : '');
        const res  = await fetch(`${API_URL}?${params}`);
        const data = await res.json();

        if (page === 1) {
            skeletonList.hidden = true;
            feed.querySelectorAll('.post').forEach(el => el.remove());
        }

        if (!data.posts || data.posts.length === 0) {
            if (page === 1) emptyState.hidden = false;
            return;
        }

        const fragment = document.createDocumentFragment();
        data.posts.forEach(post => {
            if (post.tg_id > latestTgId) latestTgId = post.tg_id;
            try {
                const card = renderPost(post);
                if (card) fragment.appendChild(card);
            } catch (err) {
                console.warn('renderPost error for tg_id', post.tg_id, err);
            }
        });
        feed.appendChild(fragment);

        currentPage = page;
        hasMore     = data.has_more;
        loadMoreWrap.hidden = !hasMore;


    } catch (e) {
        if (page === 1) {
            skeletonList.hidden = true;
            errorState.hidden   = false;
        }
        console.error('fetchPosts error:', e);
    } finally {
        isLoading = false;
        loadMoreBtn.disabled    = false;
        loadMoreBtn.textContent = 'Загрузить ещё';
    }
}

// ─── Polling новых постов ─────────────────────────────────────────────────────

async function pollNew() {
    if (latestTgId === 0) return;

    setSyncStatus('Синхронизация...', 'syncing');

    try {
        fetch(SYNC_URL).catch(() => {});

        const res  = await fetch(`${API_URL}?since_id=${latestTgId}`);
        const data = await res.json();

        if (data.posts && data.posts.length > 0) {
            const firstCard = feed.querySelector('.post');
            data.posts.forEach(post => {
                if (post.tg_id > latestTgId) latestTgId = post.tg_id;
                const card = renderPost(post);
                feed.insertBefore(card, firstCard);
            });
            setSyncStatus(`+${data.posts.length} новых`, '');
        } else {
            setSyncStatus('', '');
        }

    } catch (e) {
        setSyncStatus('Ошибка обновления', 'error');
        console.error('pollNew error:', e);
    }
}

// ─── Load More ────────────────────────────────────────────────────────────────

loadMoreBtn.addEventListener('click', () => {
    if (hasMore && !isLoading) {
        fetchPosts(currentPage + 1);
    }
});

const observer = new IntersectionObserver(entries => {
    if (entries[0].isIntersecting && hasMore && !isLoading) {
        fetchPosts(currentPage + 1);
    }
}, { rootMargin: '200px' });

observer.observe(sentinel);

// ─── Поиск ────────────────────────────────────────────────────────────────────

let searchTimer = null;

searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        const q = searchInput.value.trim();
        if (q === currentSearch) return;
        currentSearch = q;

        // В режиме поиска останавливаем polling
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }

        fetchPosts(1).then(() => {
            // Возобновляем polling только вне поиска
            if (!currentSearch) {
                pollNew();
                pollTimer = setInterval(pollNew, SYNC_INTERVAL);
            }
        });
    }, 400);
});

// ─── Запуск ───────────────────────────────────────────────────────────────────

fetchPosts(1).then(() => {
    pollNew();
    pollTimer = setInterval(pollNew, SYNC_INTERVAL);
});
