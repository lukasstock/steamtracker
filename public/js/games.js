// ── Image error handler ──
async function handleImageError(img, appId) {
    if (img.dataset.tried) {
        img.style.display = 'none';
        img.nextElementSibling.style.display = 'flex';
        return;
    }
    img.dataset.tried = '1';
    try {
        const res  = await fetch(`/games/${STEAM_ID}/${appId}/image-url`);
        const data = await res.json();
        if (data.url) { img.src = data.url; }
        else { img.style.display = 'none'; img.nextElementSibling.style.display = 'flex'; }
    } catch {
        img.style.display = 'none'; img.nextElementSibling.style.display = 'flex';
    }
}

// ── Filters ──
let currentFilter = 'all';

function setFilter(filter) {
    currentFilter = filter;
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.style.background = 'rgba(255,255,255,0.05)';
        btn.classList.remove('bg-steam-blue', 'text-white');
        btn.classList.add('text-steam-light');
    });
    const active = document.getElementById('filter-' + filter);
    active.style.background = '';
    active.classList.add('bg-steam-blue', 'text-white');
    active.classList.remove('text-steam-light');
    applyFilters();
}

function applyFilters() {
    const query = document.getElementById('search').value.toLowerCase();
    const cards = document.querySelectorAll('.game-card');
    let visible = 0;

    cards.forEach(card => {
        const status      = card.dataset.status;
        const matchSearch = card.dataset.name.includes(query);
        const matchFilter =
            currentFilter === 'all'       ||
            (currentFilter === 'playing'   && status === 'playing')   ||
            (currentFilter === 'completed' && status === 'completed') ||
            (currentFilter === 'backlog'   && (status === 'unplayed' || status === 'on_hold')) ||
            (currentFilter === 'dropped'   && status === 'dropped')   ||
            (currentFilter === 'perfect'   && card.dataset.achPerfect === '1' && parseInt(card.dataset.achTotal) > 0);

        const show = matchSearch && matchFilter;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    document.getElementById('no-results').classList.toggle('hidden', visible > 0);
}

// ── Sort ──
function sortGames(criterion) {
    const grid  = document.getElementById('game-grid');
    const cards = Array.from(grid.querySelectorAll('.game-card'));

    cards.sort((a, b) => {
        switch (criterion) {
            case 'name':            return a.dataset.name.localeCompare(b.dataset.name);
            case 'most-stars':      return Number(b.dataset.rating) - Number(a.dataset.rating);
            case 'least-stars':
                const ratingA = Number(a.dataset.rating);
                const ratingB = Number(b.dataset.rating);
                if (!ratingA) return 1;
                if (!ratingB) return -1;
                return ratingA - ratingB;
            case 'most-played':     return Number(b.dataset.playtime) - Number(a.dataset.playtime);
            case 'least-played':    return Number(a.dataset.playtime) - Number(b.dataset.playtime);
            case 'recently-played': return Number(b.dataset.recent)   - Number(a.dataset.recent);
            case 'notes-available': return b.dataset.notes.localeCompare(a.dataset.notes);
            default: return 0;
        }
    });
    cards.forEach(card => grid.appendChild(card));
}

// ── Stats ──
function updateStats() {
    const cards  = document.querySelectorAll('.game-card');
    const total  = cards.length;
    const counts = { completed: 0, playing: 0, dropped: 0, on_hold: 0, unplayed: 0 };

    cards.forEach(c => {
        const s = c.dataset.status;
        if (counts[s] !== undefined) counts[s]++;
        else counts.unplayed++;
    });

    const backlog = counts.unplayed + counts.on_hold;
    const pct     = total > 0 ? Math.round((counts.completed / total) * 1000) / 10 : 0;
    const perfect = Array.from(cards).filter(c => c.dataset.achPerfect === '1' && parseInt(c.dataset.achTotal) > 0).length;

    document.getElementById('stat-completed').textContent  = counts.completed;
    document.getElementById('stat-playing').textContent    = counts.playing;
    document.getElementById('stat-backlog').textContent    = backlog;
    document.getElementById('stat-total').textContent      = total;
    document.getElementById('stat-pct').textContent        = pct + '%';
    document.getElementById('progress-bar').style.width    = pct + '%';
    document.getElementById('stat-perfect').textContent    = perfect;
    document.getElementById('count-all').textContent       = total;
    document.getElementById('count-playing').textContent   = counts.playing;
    document.getElementById('count-completed').textContent = counts.completed;
    document.getElementById('count-backlog').textContent   = backlog;
    document.getElementById('count-dropped').textContent   = counts.dropped;
    document.getElementById('count-perfect').textContent   = perfect;
}

// ── Modal ──
let modalAppId      = null;
let modalStatus     = 'unplayed';
let modalRating     = 0;
let modalSpotlight  = false;

const STATUS_CONFIG = {
    unplayed:  { border: 'rgba(255,255,255,0.5)', bg: 'rgba(255,255,255,0.15)', color: '#c6d4df' },
    playing:   { border: 'rgba(26,159,255,0.8)',  bg: 'rgba(26,159,255,0.25)',  color: '#1a9fff' },
    completed: { border: 'rgba(164,208,7,0.8)',   bg: 'rgba(164,208,7,0.25)',   color: '#a4d007' },
    dropped:   { border: 'rgba(239,68,68,0.8)',   bg: 'rgba(239,68,68,0.25)',   color: '#f87171' },
    on_hold:   { border: 'rgba(234,179,8,0.8)',   bg: 'rgba(234,179,8,0.25)',   color: '#fbbf24' },
};

const BADGE_CONFIG = {
    unplayed:  null,
    playing:   { text: '▶ Playing',  style: 'background:#1a9fff;color:#fff;' },
    completed: { text: '✓ Done',     style: 'background:#a4d007;color:#000;' },
    dropped:   { text: '✕ Dropped',  style: 'background:rgb(239,68,68);color:#fff;' },
    on_hold:   { text: '⏸ On Hold',  style: 'background:rgb(234,179,8);color:#000;' },
};

function openModal(appId, btn) {
    // Spotlight cards are not .game-card — fall back to the library card by appId
    const card  = btn.closest('.game-card') || document.querySelector(`.game-card[data-appid="${appId}"]`);
    modalAppId  = appId;
    modalStatus = card.dataset.status;
    modalRating = parseInt(card.dataset.rating) || 0;

    const cardImg = card.querySelector('img');
    document.getElementById('modal-img').src = cardImg?.src || '';

    const nameEl = card.querySelector('.text-sm.font-semibold');
    const gameName = nameEl?.textContent.trim() || '';
    document.getElementById('modal-title').textContent = gameName;
    const metaEl = card.querySelector('.game-meta');
    document.getElementById('modal-playtime').textContent = metaEl?.textContent.replace(/·.*/, '').trim() || '';

    // HLTB
    loadHltbIntoModal(card, appId, gameName);

    // Achievements
    document.getElementById('modal-ach-section').classList.add('hidden');
    document.getElementById('modal-ach-content').innerHTML = '';
    loadAchievementsIntoModal(card, appId);

    document.getElementById('modal-notes').value = card.dataset.notes || '';

    const notesPublicEl = document.getElementById('modal-notes-public');
    if (notesPublicEl) notesPublicEl.checked = card.dataset.notesPublic === '1';

    modalSpotlight = card.dataset.spotlight === '1';
    refreshStatusPills();
    refreshStars();
    refreshSpotlightToggle();

    document.querySelectorAll('.star-btn').forEach(star => {
        star.onmouseover = () => previewStars(parseInt(star.dataset.value));
        star.onmouseout  = () => refreshStars();
        star.onclick     = () => {
            modalRating = modalRating === parseInt(star.dataset.value) ? 0 : parseInt(star.dataset.value);
            refreshStars();
        };
    });

    document.querySelectorAll('.status-pill').forEach(pill => {
        pill.onclick = () => { modalStatus = pill.dataset.value; refreshStatusPills(); };
    });

    const backdrop = document.getElementById('modal-backdrop');
    const box      = document.getElementById('modal-box');
    backdrop.classList.remove('pointer-events-none');
    backdrop.style.opacity = '1';
    box.style.transform    = 'scale(1)';
    box.style.opacity      = '1';
    document.body.style.overflow = 'hidden';
}

function closeModal(e) {
    if (e && e.target !== document.getElementById('modal-backdrop') && e.type === 'click') return;
    const backdrop = document.getElementById('modal-backdrop');
    const box      = document.getElementById('modal-box');
    backdrop.style.opacity = '0';
    box.style.transform    = 'scale(0.95)';
    box.style.opacity      = '0';
    setTimeout(() => backdrop.classList.add('pointer-events-none'), 200);
    document.body.style.overflow = '';
    modalAppId = null;
}

function refreshStatusPills() {
    document.querySelectorAll('.status-pill').forEach(pill => {
        const value  = pill.dataset.value;
        const config = STATUS_CONFIG[value];
        const active = value === modalStatus;
        pill.style.borderColor = active ? config.border : 'rgba(255,255,255,0.12)';
        pill.style.background  = active ? config.bg     : 'rgba(255,255,255,0.04)';
        pill.style.color       = active ? config.color  : '#8f98a0';
        pill.style.fontWeight  = active ? '700'         : '600';
    });
}

function previewStars(n) {
    document.querySelectorAll('.star-btn').forEach(s => {
        s.style.color = parseInt(s.dataset.value) <= n ? '#fbbf24' : '#8f98a0';
    });
}

function refreshStars() {
    document.querySelectorAll('.star-btn').forEach(s => {
        s.style.color = parseInt(s.dataset.value) <= modalRating ? '#fbbf24' : '#8f98a0';
    });
}

function toggleSpotlight() {
    modalSpotlight = !modalSpotlight;
    refreshSpotlightToggle();
}

function refreshSpotlightToggle() {
    const btn = document.getElementById('spotlight-toggle');
    if (!btn) return;
    const label = document.getElementById('spotlight-label');
    if (modalSpotlight) {
        btn.style.borderColor = 'rgba(251,191,36,0.6)';
        btn.style.background  = 'rgba(251,191,36,0.12)';
        btn.style.color       = '#fbbf24';
        label.textContent     = 'In Spotlight';
    } else {
        btn.style.borderColor = 'rgba(255,255,255,0.1)';
        btn.style.background  = 'rgba(255,255,255,0.04)';
        btn.style.color       = '#8f98a0';
        label.textContent     = 'Add to Spotlight';
    }
}

function buildSpotlightCard(appId, name, imgSrc, status, rating, notes, notesPublic) {
    const div = document.createElement('div');
    div.className = 'spotlight-card relative rounded-xl overflow-hidden';
    div.dataset.appid       = appId;
    div.dataset.status      = status;
    div.dataset.notesPublic = notesPublic ? '1' : '0';

    const escapedName  = name.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const escapedNotes = notes.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    let starsHtml = '';
    if (rating) {
        let s = '';
        for (let i = 1; i <= 5; i++) s += i <= rating ? '★' : '☆';
        starsHtml = `<div style="color:#fbbf24;font-size:0.75rem;letter-spacing:-0.05em;margin-bottom:0.375rem;">${s}</div>`;
    }

    const notesHtml = (notes && (IS_OWNER || notesPublic))
        ? `<div style="color:#8f98a0;font-size:0.7rem;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">${escapedNotes}</div>`
        : '';

    div.innerHTML = `
        <img src="${imgSrc}" alt="${escapedName}" class="w-full block">
        <div class="absolute inset-0 pointer-events-none"
             style="background:linear-gradient(to bottom, transparent 25%, rgba(0,0,0,0.95) 100%)"></div>
        <div class="absolute bottom-0 left-0 right-0 px-4 pb-4 pt-8">
            <div style="color:#fff;font-weight:700;font-size:0.875rem;line-height:1.25;margin-bottom:0.25rem;">${escapedName}</div>
            ${starsHtml}
            ${notesHtml}
        </div>
    `;
    return div;
}

function updateSpotlightSection() {
    const grid  = document.getElementById('spotlight-grid');
    const empty = document.getElementById('spotlight-empty');
    if (!grid) return;

    const spotlightCards = Array.from(document.querySelectorAll('.game-card[data-spotlight="1"]'));

    grid.innerHTML = '';
    spotlightCards.forEach(card => {
        const img = card.querySelector('img');
        const sc  = buildSpotlightCard(
            card.dataset.appid,
            card.querySelector('.text-sm.font-semibold')?.textContent.trim() || '',
            img?.src || '',
            card.dataset.status,
            parseInt(card.dataset.rating) || 0,
            card.dataset.notes || '',
            card.dataset.notesPublic === '1'
        );
        grid.appendChild(sc);
    });

    if (empty) empty.style.display = spotlightCards.length === 0 ? '' : 'none';
}

function applyCardUpdate(appId, data) {
    const card  = document.querySelector(`.game-card[data-appid="${appId}"]`);
    if (!card) return;
    const badge = card.querySelector('.status-badge');
    const meta  = card.querySelector('.game-meta');
    const stars = card.querySelector('.game-stars');

    card.dataset.status      = data.status;
    card.dataset.rating      = data.rating || 0;
    card.dataset.notes       = data.notes || '';
    card.dataset.notesPublic = data.notesPublic ? '1' : '0';
    card.dataset.spotlight   = data.spotlight ? '1' : '0';

    const bc = BADGE_CONFIG[data.status];
    if (bc) {
        badge.textContent = bc.text;
        badge.setAttribute('style', bc.style);
        badge.className = 'status-badge absolute top-2 left-2 text-xs font-bold px-2 py-0.5 rounded-md';
    } else {
        badge.textContent = '';
        badge.className = 'status-badge absolute top-2 left-2 hidden';
    }

    const playtimePart = meta.textContent.split('·')[0].trim();
    if (data.status === 'completed' && data.completedAt) {
        meta.innerHTML = `${playtimePart}<span class="text-steam-green"> · ${data.completedAt}</span>`;
    } else {
        meta.textContent = playtimePart;
    }

    if (data.rating) {
        let starStr = '';
        for (let i = 1; i <= 5; i++) starStr += i <= data.rating ? '★' : '☆';
        stars.textContent = starStr;
        stars.className = 'game-stars text-yellow-400 text-xs tracking-tighter leading-none';
    } else {
        stars.textContent = '☆☆☆☆☆';
        stars.className = 'game-stars text-xs tracking-tighter leading-none text-transparent select-none';
    }
}

async function saveModal() {
    if (!modalAppId) return;

    const saveBtn = document.getElementById('modal-save-btn');
    saveBtn.disabled = true;

    const appId      = modalAppId;
    const notes      = document.getElementById('modal-notes').value;
    const notesPublic = document.getElementById('modal-notes-public')?.checked ?? false;
    const appName    = document.querySelector(`.game-card[data-appid="${appId}"]`)?.dataset.name ?? '';
    const payload    = { status: modalStatus, rating: modalRating, notes, notes_public: notesPublic, spotlight: modalSpotlight, app_name: appName };

    // Apply changes immediately so the modal feels instant
    applyCardUpdate(appId, { status: modalStatus, rating: modalRating || null, notes, notesPublic, spotlight: modalSpotlight, completedAt: null });
    updateStats();
    applyFilters();
    updateSpotlightSection();
    closeModal();

    // Persist in the background; re-apply once done to pick up completedAt
    try {
        const res  = await fetch(`/games/${STEAM_ID}/${appId}/update`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        applyCardUpdate(appId, data);
        updateSpotlightSection();
    } finally {
        saveBtn.disabled = false;
    }
}

// ── HLTB ──
async function fetchHltb(appId, gameName) {
    const url = `/games/${STEAM_ID}/${appId}/hltb?name=${encodeURIComponent(gameName)}`;
    try {
        const res  = await fetch(url);
        const data = await res.json();
        return data.fetched ? (data.hours ?? 'n/a') : null;
    } catch {
        return null;
    }
}

function hltbLabel(hltb) {
    if (hltb === '' || hltb === null || hltb === undefined) return '…';
    if (hltb === 'n/a') return 'Not on HLTB';
    const h = parseInt(hltb);
    return isNaN(h) ? '…' : `~${h} hrs`;
}

async function loadHltbIntoModal(card, appId, gameName) {
    const el = document.getElementById('modal-hltb');
    if (!el) return;

    const cached = card.dataset.hltb;
    if (cached && cached !== '') {
        el.textContent = hltbLabel(cached);
        return;
    }

    el.textContent = '…';
    const result = await fetchHltb(appId, gameName);
    if (result !== null) {
        const val = result === 'n/a' ? 'n/a' : String(result);
        card.dataset.hltb = val;
        el.textContent = hltbLabel(val);
    } else {
        el.textContent = '—';
    }
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeSurpriseModal();
        closeModal();
    }
});

// ── Achievements ──
async function fetchAchievements(steamId, appId) {
    try {
        const res  = await fetch(`/games/${steamId}/${appId}/achievements`);
        const data = await res.json();
        return data;
    } catch {
        return null;
    }
}

async function loadAchievementsIntoModal(card, appId) {
    const section = document.getElementById('modal-ach-section');
    const content = document.getElementById('modal-ach-content');
    if (!section || !content) return;

    // Use pre-loaded data from card attributes if available
    if (card.dataset.achFetched === '1') {
        const total    = parseInt(card.dataset.achTotal);
        const unlocked = parseInt(card.dataset.achUnlocked);
        if (total === 0) return; // game has no achievements
        renderAchievementsInModal(section, content, card, appId, {
            has_achievements: true,
            unlocked, total,
            percent: Math.round((unlocked / total) * 1000) / 10,
            perfect: unlocked === total,
            rare: [],  // rare data not stored in card attributes — fetch for full detail
        });
        // Still fetch in background for rare achievements if needed
        const fresh = await fetchAchievements(STEAM_ID, appId);
        if (fresh && fresh.has_achievements) {
            renderAchievementsInModal(section, content, card, appId, fresh);
        }
        return;
    }

    // No cached data — show loading state and fetch
    section.classList.remove('hidden');
    content.innerHTML = '<span style="font-size:0.75rem;color:#8f98a0;">Loading…</span>';

    const data = await fetchAchievements(STEAM_ID, appId);
    if (!data || data.has_achievements === null) {
        section.classList.add('hidden');
        return;
    }
    if (!data.has_achievements) {
        section.classList.add('hidden');
        return;
    }

    // Update card attributes for future opens and perfect filter
    card.dataset.achFetched  = '1';
    card.dataset.achTotal    = data.total;
    card.dataset.achUnlocked = data.unlocked;
    if (data.perfect) {
        card.dataset.achPerfect = '1';
        card.querySelector('.ach-perfect-badge')?.classList.remove('hidden');
    }

    renderAchievementsInModal(section, content, card, appId, data);
}

function renderAchievementsInModal(section, content, card, appId, data) {
    const { unlocked, total, percent, perfect, rare } = data;
    const barColor  = perfect
        ? 'linear-gradient(90deg,#f59e0b,#fbbf24)'
        : 'linear-gradient(90deg,#1a9fff,#a4d007)';
    const countColor = perfect ? '#fbbf24' : '#fff';

    let html = `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:${rare && rare.length ? '10px' : '0'};">
            <div style="flex:1;height:4px;background:rgba(0,0,0,0.4);border-radius:9999px;overflow:hidden;">
                <div style="width:${percent}%;height:100%;background:${barColor};border-radius:9999px;transition:width 0.4s ease;"></div>
            </div>
            <span style="font-size:0.75rem;font-weight:700;color:${countColor};white-space:nowrap;">
                ${perfect ? '🏆 ' : ''}${unlocked} / ${total}${perfect ? ' Perfect!' : ` (${percent}%)`}
            </span>
        </div>`;

    if (rare && rare.length > 0) {
        html += `<div style="display:flex;gap:8px;">`;
        for (const r of rare) {
            const iconHtml = r.icon
                ? `<img src="${r.icon}" style="width:40px;height:40px;border-radius:6px;display:block;" alt="">`
                : `<div style="width:40px;height:40px;border-radius:6px;background:rgba(255,255,255,0.07);display:flex;align-items:center;justify-content:center;font-size:1.2rem;">🏅</div>`;
            html += `
                <div title="${r.displayName} — ${r.globalPercent}% of players have this"
                     style="display:flex;flex-direction:column;align-items:center;gap:3px;flex:1;min-width:0;cursor:default;">
                    ${iconHtml}
                    <span style="font-size:0.6rem;color:#c6d4df;text-align:center;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.3;width:100%;">${r.displayName}</span>
                    <span style="font-size:0.6rem;color:#fbbf24;font-weight:600;">${r.globalPercent}%</span>
                </div>`;
        }
        html += `</div>`;
    }

    content.innerHTML = html;
    section.classList.remove('hidden');
}

async function applyAchievementDataToCard(card, data, autoClassify = false) {
    card.dataset.achFetched = '1';
    if (!data.has_achievements) {
        card.dataset.achTotal    = '0';
        card.dataset.achUnlocked = '0';
        card.dataset.achPerfect  = '0';
        return;
    }
    card.dataset.achTotal    = String(data.total);
    card.dataset.achUnlocked = String(data.unlocked);
    card.dataset.achPerfect  = data.perfect ? '1' : '0';

    if (data.perfect) {
        card.querySelector('.ach-perfect-badge')?.classList.remove('hidden');
        // Increment the live counters without a full updateStats() call
        for (const id of ['stat-perfect', 'count-perfect']) {
            const el = document.getElementById(id);
            if (el) el.textContent = String(parseInt(el.textContent || '0') + 1);
        }

        // Auto-upgrade status to Completed for the owner when syncing
        if (autoClassify && typeof IS_OWNER !== 'undefined' && IS_OWNER) {
            const status = card.dataset.status;
            if (status === 'playing' || status === 'unplayed') {
                await autoCompleteGame(card, parseInt(card.dataset.appid));
            }
        }
    }
}

async function autoCompleteGame(card, appId) {
    try {
        const res = await fetch(`/games/${STEAM_ID}/${appId}/update`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                status:    'completed',
                rating:    parseInt(card.dataset.rating) || 0,
                notes:     card.dataset.notes || '',
                spotlight: card.dataset.spotlight === '1',
                app_name:  card.dataset.name ?? '',
            }),
        });
        if (!res.ok) return;
        const data = await res.json();
        applyCardUpdate(appId, data);
        updateStats();
    } catch {
        // Silent — not critical if this fails
    }
}

let achSyncRunning = false;

async function startAchievementSync() {
    if (achSyncRunning) return;

    const btn = document.getElementById('ach-sync-btn');
    if (!btn) return;

    const unfetched = Array.from(document.querySelectorAll('.game-card[data-ach-fetched="0"]'));

    if (unfetched.length === 0) {
        btn.textContent = '✓ All synced';
        btn.style.color = '#a4d007';
        setTimeout(() => btn.remove(), 2000);
        return;
    }

    achSyncRunning   = true;
    btn.disabled     = true;
    btn.style.cursor = 'default';
    btn.onmouseover  = null;
    btn.onmouseout   = null;

    // Played games first — more likely to have achievements
    unfetched.sort((a, b) => parseInt(b.dataset.playtime) - parseInt(a.dataset.playtime));

    const total     = unfetched.length;
    let   done      = 0;
    const batchSize = 5;

    btn.textContent = `Syncing… 0 / ${total}`;

    for (let i = 0; i < unfetched.length; i += batchSize) {
        const batch = unfetched.slice(i, i + batchSize);
        await Promise.all(batch.map(async card => {
            const data = await fetchAchievements(STEAM_ID, parseInt(card.dataset.appid));
            if (data && data.has_achievements !== null) {
                await applyAchievementDataToCard(card, data, true); // autoClassify = true
            }
            btn.textContent = `Syncing… ${++done} / ${total}`;
        }));
        if (i + batchSize < unfetched.length) {
            await new Promise(r => setTimeout(r, 400));
        }
    }

    achSyncRunning  = false;
    btn.textContent = '✓ Done';
    btn.style.color = '#a4d007';
    setTimeout(() => btn.remove(), 3000);
}

// Show perfect badges on page load for pre-cached cards
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.game-card[data-ach-perfect="1"]').forEach(card => {
        if (parseInt(card.dataset.achTotal) > 0) {
            card.querySelector('.ach-perfect-badge')?.classList.remove('hidden');
        }
    });

    // Hide the sync button if there's nothing left to sync
    const unfetched = document.querySelectorAll('.game-card[data-ach-fetched="0"]');
    if (unfetched.length === 0) {
        document.getElementById('ach-sync-btn')?.remove();
    }
});

// ── Surprise Me ──
const SURPRISE_EXCLUDE = [
    /\bpublic\s*test\b/,
    /\bpublic\s*beta\b/,
    /\bplaytest\b/,
    /\bdedicated\s*server\b/,
    /\bsoundtrack\b/,
    /\bsdk\b/,
    /\btest\s*server\b/,
    /\s+demo$/,
    / - demo\b/,
    /\s+beta$/,
    / - beta\b/,
];

function isSurpriseExcluded(lowercaseName) {
    return SURPRISE_EXCLUDE.some(p => p.test(lowercaseName));
}

function updateSliderLabel(value) {
    const label = document.getElementById('surprise-hours-label');
    const v = parseInt(value);
    if (v === 0)        label.textContent = 'Unplayed only';
    else if (v >= 100)  label.textContent = 'Any playtime';
    else                label.textContent = `≤ ${v} hrs`;
}

async function surpriseMe() {
    const btn            = document.getElementById('surprise-btn');
    const includeCompleted = document.getElementById('surprise-include-completed').checked;
    const maxHours         = parseInt(document.getElementById('surprise-hours-slider').value);
    const hltbEnabled      = document.getElementById('surprise-hltb-enabled').checked;
    const maxHltbHours     = parseInt(document.getElementById('surprise-hltb-slider').value);

    // First pass: apply non-HLTB filters
    const preEligible = Array.from(document.querySelectorAll('.game-card')).filter(card => {
        const status      = card.dataset.status;
        const playtimeHrs = (parseInt(card.dataset.playtime) || 0) / 60;
        if (isSurpriseExcluded(card.dataset.name))      return false;
        if (!includeCompleted && status === 'completed') return false;
        if (maxHours === 0  && playtimeHrs > 0)         return false;
        if (maxHours < 100  && playtimeHrs > maxHours)  return false;
        return true;
    });

    // If HLTB filter is active, ensure every eligible card has data loaded
    if (hltbEnabled && maxHltbHours < 200) {
        const needsFetch = preEligible.filter(card => card.dataset.hltb === '');
        if (needsFetch.length > 0) {
            const origHtml   = btn.innerHTML;
            btn.disabled     = true;

            let done = 0;
            const total = needsFetch.length;
            btn.textContent = `Loading HLTB… 0 / ${total}`;

            // Fetch in parallel batches of 5 to avoid hammering the server
            for (let i = 0; i < needsFetch.length; i += 5) {
                await Promise.all(
                    needsFetch.slice(i, i + 5).map(async card => {
                        const appId    = card.dataset.appid;
                        const name     = card.querySelector('.text-sm.font-semibold')?.textContent.trim() || '';
                        const result   = await fetchHltb(appId, name);
                        if (result !== null) {
                            card.dataset.hltb = result === 'n/a' ? 'n/a' : String(result);
                        } else {
                            card.dataset.hltb = 'n/a';
                        }
                        btn.textContent = `Loading HLTB… ${++done} / ${total}`;
                    })
                );
            }

            btn.disabled  = false;
            btn.innerHTML = origHtml;
        }
    }

    // Second pass: apply HLTB filter now that data is loaded
    const eligible = preEligible.filter(card => {
        if (hltbEnabled && maxHltbHours < 200) {
            const hltb = parseInt(card.dataset.hltb);
            if (!isNaN(hltb) && hltb > 0 && hltb > maxHltbHours) return false;
        }
        return true;
    });

    if (eligible.length === 0) {
        const orig = btn.innerHTML;
        btn.textContent = 'No games match!';
        setTimeout(() => btn.innerHTML = orig, 2000);
        return;
    }

    // Fisher-Yates shuffle, then take up to 3
    for (let i = eligible.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [eligible[i], eligible[j]] = [eligible[j], eligible[i]];
    }
    const picks = eligible.slice(0, 3);

    buildSurpriseCards(picks);
    openSurpriseModal();
}

function buildSurpriseCards(picks) {
    const STATUS_BADGES = {
        playing:   { text: '▶ Playing',  color: '#1a9fff', bg: 'rgba(26,159,255,0.18)'  },
        completed: { text: '✓ Done',     color: '#a4d007', bg: 'rgba(164,208,7,0.18)'   },
        dropped:   { text: '✕ Dropped',  color: '#f87171', bg: 'rgba(239,68,68,0.18)'   },
        on_hold:   { text: '⏸ On Hold',  color: '#fbbf24', bg: 'rgba(234,179,8,0.18)'   },
    };

    const grid = document.getElementById('surprise-card-grid');
    grid.innerHTML = '';

    picks.forEach(card => {
        const appId        = card.dataset.appid;
        const name         = card.querySelector('.text-sm.font-semibold')?.textContent.trim() || '';
        const imgSrc       = card.querySelector('img')?.src || '';
        const status       = card.dataset.status;
        const playtimeHrs  = Math.round((parseInt(card.dataset.playtime) || 0) / 60);
        const rating       = parseInt(card.dataset.rating) || 0;

        const badge = STATUS_BADGES[status];
        const escapedName = name.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

        const badgeHtml = badge
            ? `<div style="display:inline-block;padding:3px 9px;border-radius:6px;font-size:0.7rem;font-weight:700;color:${badge.color};background:${badge.bg};margin-bottom:0.5rem;">${badge.text}</div>`
            : '';

        let starsHtml = '';
        if (rating) {
            let s = '';
            for (let i = 1; i <= 5; i++) s += i <= rating ? '★' : '☆';
            starsHtml = `<div style="color:#fbbf24;font-size:0.8rem;letter-spacing:-0.05em;margin-top:0.3rem;">${s}</div>`;
        }

        const playtimeText = playtimeHrs > 0 ? `${playtimeHrs} hrs played` : 'Never played';
        const hltbRaw      = card.dataset.hltb;
        const hltbElId     = `stc-hltb-${appId}`;

        const sc = document.createElement('div');
        sc.className = 'surprise-tinder-card';
        sc.innerHTML = `
            <div class="stc-img">
                <img src="${imgSrc}" alt="${escapedName}" onerror="this.style.display='none'">
            </div>
            <div class="stc-info">
                <div style="font-size:0.95rem;font-weight:800;color:#fff;line-height:1.3;margin-bottom:0.4rem;">${escapedName}</div>
                ${badgeHtml}
                ${starsHtml}
                <div style="color:#8f98a0;font-size:0.72rem;margin-top:0.35rem;">${playtimeText}</div>
                <div id="${hltbElId}" style="color:#1a9fff;font-size:0.72rem;margin-top:0.2rem;min-height:1em;"></div>
            </div>
        `;

        // Populate HLTB — use cached value or fetch now
        const hltbEl = sc.querySelector(`#${hltbElId}`);
        if (hltbRaw && hltbRaw !== '') {
            hltbEl.textContent = hltbRaw === 'n/a' ? '' : `~${parseInt(hltbRaw)} hrs to beat`;
        } else {
            hltbEl.textContent = '…';
            fetchHltb(appId, name).then(result => {
                if (result !== null) {
                    const val = result === 'n/a' ? 'n/a' : String(result);
                    card.dataset.hltb = val;
                    hltbEl.textContent = val === 'n/a' ? '' : `~${parseInt(val)} hrs to beat`;
                } else {
                    hltbEl.textContent = '';
                }
            });
        }

        sc.addEventListener('click', () => {
            closeSurpriseModal();
            const gameCard = document.querySelector(`.game-card[data-appid="${appId}"]`);
            if (!gameCard) return;
            const editBtn = gameCard.querySelector('button[onclick^="openModal"]');
            if (editBtn) {
                editBtn.click();
            } else {
                gameCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        grid.appendChild(sc);
    });
}

function openSurpriseModal() {
    const modal = document.getElementById('surprise-modal');
    const box   = document.getElementById('surprise-modal-box');
    modal.classList.remove('pointer-events-none');
    modal.style.opacity      = '1';
    box.style.transform      = 'scale(1)';
    box.style.opacity        = '1';
}

function closeSurpriseModal() {
    const modal = document.getElementById('surprise-modal');
    const box   = document.getElementById('surprise-modal-box');
    if (!modal || modal.classList.contains('pointer-events-none')) return;
    modal.style.opacity = '0';
    box.style.transform = 'scale(0.95)';
    box.style.opacity   = '0';
    setTimeout(() => modal.classList.add('pointer-events-none'), 200);
}

async function rollAgain() { await surpriseMe(); }

function toggleHltbFilter(enabled) {
    const row = document.getElementById('hltb-filter-row');
    if (enabled) {
        row.classList.remove('hidden');
    } else {
        row.classList.add('hidden');
    }
}

function updateHltbLabel(value) {
    const label = document.getElementById('surprise-hltb-label');
    const v = parseInt(value);
    label.textContent = v >= 200 ? 'No limit' : `≤ ${v} hrs`;
}
