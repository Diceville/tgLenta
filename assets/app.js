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

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderEntities(text, entities) {
    if (!text) return '';

    if (!entities || !entities.length) {
        // Нет entities — просто экранируем и делаем ссылки кликабельными
        return escHtml(text)
            .replace(/(https?:\/\/[^\s<>"]+)/g,
                '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
    }

    // Сортируем по offset
    const sorted = [...entities].sort((a, b) => a.offset - b.offset);

    // Работаем с массивом Unicode-символов (правильная поддержка emoji)
    const chars = [...text];
    let html = '';
    let pos  = 0;

    for (const e of sorted) {
        if (e.offset > pos) {
            html += escHtml(chars.slice(pos, e.offset).join(''));
        }
        const raw = chars.slice(e.offset, e.offset + e.length).join('');
        const esc = escHtml(raw);
        switch (e.type) {
            case 'bold':          html += `<strong>${esc}</strong>`; break;
            case 'italic':        html += `<em>${esc}</em>`; break;
            case 'underline':     html += `<u>${esc}</u>`; break;
            case 'strikethrough': html += `<s>${esc}</s>`; break;
            case 'code':          html += `<code>${esc}</code>`; break;
            case 'pre':           html += `<pre>${esc}</pre>`; break;
            case 'spoiler':       html += `<span class="spoiler">${esc}</span>`; break;
            case 'text_link': {
                // Если видимый текст сам является URL — используем его как href,
                // иначе берём e.url (текст может быть "Читать далее", href — ссылка)
                const href = /^https?:\/\//.test(raw) ? raw : (e.url || '');
                html += `<a href="${escHtml(href)}" target="_blank" rel="noopener noreferrer">${esc}</a>`;
                break;
            }
            case 'url':           html += `<a href="${esc}" target="_blank" rel="noopener noreferrer">${esc}</a>`; break;
            case 'mention':       html += `<a href="https://t.me/${escHtml(raw.slice(1))}" target="_blank" rel="noopener noreferrer">${esc}</a>`; break;
            default:              html += esc;
        }
        pos = e.offset + e.length;
    }

    if (pos < chars.length) {
        html += escHtml(chars.slice(pos).join(''));
    }

    return html;
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
        video.addEventListener('error', () => {
            const wrap = document.createElement('div');
            wrap.className = 'post-media post-media-unavailable';
            const a = document.createElement('a');
            a.href = post.tg_link;
            a.target = '_blank';
            a.rel = 'noopener';
            a.innerHTML = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z"/></svg>Открыть`;
            wrap.appendChild(a);
            video.replaceWith(wrap);
        });
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
        const rendered = renderEntities(post.text, post.entities);
        textDiv.innerHTML = rendered.replace(/\n/g, '<br>');
        textDiv.querySelectorAll('.spoiler').forEach(el => {
            el.addEventListener('click', () => el.classList.toggle('revealed'));
        });
        body.appendChild(textDiv);

        if (post.text.length > 500) {
            textDiv.classList.add('collapsed');
            const readMore = document.createElement('button');
            readMore.className = 'read-more-btn';
            readMore.textContent = 'Читать далее';
            readMore.addEventListener('click', () => {
                const collapsed = textDiv.classList.toggle('collapsed');
                readMore.textContent = collapsed ? 'Читать далее' : 'Свернуть';
            });
            body.appendChild(readMore);
        }
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

    if (post.comments_count > 0 || post.comments_count === 0) {
        const commentsBtn = document.createElement('button');
        commentsBtn.className = 'post-comments-btn';
        commentsBtn.innerHTML = `<svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Комментарии${post.comments_count ? ' ' + post.comments_count : ''}`;

        let section = null;
        let loaded  = false;

        commentsBtn.addEventListener('click', () => {
            if (!section) {
                section = document.createElement('div');
                section.className = 'comments-section';
                article.appendChild(section);
                loaded = true;
                loadComments(post.id, section);
                commentsBtn.classList.add('active');
                return;
            }
            section.hidden = !section.hidden;
            commentsBtn.classList.toggle('active', !section.hidden);
        });

        footerRight.insertBefore(commentsBtn, footerRight.firstChild);
    }

    const link = document.createElement('a');
    link.className = 'post-link';
    link.href      = post.tg_link;
    link.target    = '_blank';
    link.rel       = 'noopener noreferrer';
    link.innerHTML = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z"/></svg>Открыть`;
    footerRight.appendChild(link);

    footer.appendChild(footerRight);
    body.appendChild(footer);
    article.appendChild(body);

    return article;
}

// ─── Комментарии ──────────────────────────────────────────────────────────────

function renderComment(c) {
    const wrap = document.createElement('div');
    wrap.className = 'comment';

    const avatar = document.createElement('div');
    avatar.className = 'comment-avatar';
    const name = c.user_name || c.user_username || '?';
    avatar.textContent = [...name][0].toUpperCase();
    wrap.appendChild(avatar);

    const body = document.createElement('div');
    body.className = 'comment-body';

    const header = document.createElement('div');
    header.className = 'comment-header';

    const nameEl = document.createElement('span');
    nameEl.className = 'comment-name';
    nameEl.textContent = name;
    header.appendChild(nameEl);

    const dateEl = document.createElement('time');
    dateEl.className = 'comment-date';
    dateEl.textContent = formatDate(c.date);
    header.appendChild(dateEl);

    body.appendChild(header);

    if (c.text) {
        const textEl = document.createElement('div');
        textEl.className = 'comment-text';
        textEl.innerHTML = renderEntities(c.text, c.entities).replace(/\n/g, '<br>');
        body.appendChild(textEl);
    }

    wrap.appendChild(body);
    return wrap;
}

async function loadComments(postId, section) {
    section.innerHTML = '<div class="comments-loading">Загрузка...</div>';
    try {
        const res  = await fetch(`${API_URL}?post_id=${postId}`);
        const data = await res.json();
        section.innerHTML = '';
        if (!data.comments || data.comments.length === 0) {
            section.innerHTML = '<div class="comments-empty">Комментариев пока нет</div>';
            return;
        }
        data.comments.forEach(c => section.appendChild(renderComment(c)));
    } catch {
        section.innerHTML = '<div class="comments-empty">Не удалось загрузить комментарии</div>';
    }
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

// ─── Тема (светлая / тёмная) ──────────────────────────────────────────────────

(function initTheme() {
    const root   = document.getElementById('htmlRoot');
    const btn    = document.getElementById('themeToggle');
    if (!root || !btn) return;

    const saved = localStorage.getItem('theme');
    if (saved) root.dataset.theme = saved;

    btn.addEventListener('click', () => {
        const isDark = getComputedStyle(root).getPropertyValue('--bg').trim() === '#0e1117'
            || root.dataset.theme === 'dark'
            || (!root.dataset.theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
        const next = isDark ? 'light' : 'dark';
        root.dataset.theme = next;
        localStorage.setItem('theme', next);
    });
})();

// ─── Аватар канала ────────────────────────────────────────────────────────────

(function loadAvatar() {
    const el = document.getElementById('channelAvatar');
    if (!el) return;
    const img = document.createElement('img');
    img.alt = '';
    img.src = 'avatar.php';
    img.addEventListener('load',  () => el.replaceChildren(img));
})();

// ─── Запуск ───────────────────────────────────────────────────────────────────

fetchPosts(1).then(() => {
    pollNew();
    pollTimer = setInterval(pollNew, SYNC_INTERVAL);
});
