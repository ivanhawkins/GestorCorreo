/**
 * Hawkins Mail v.05 – Vanilla JS frontend
 * Calls the existing Laravel API at /api/*
 */
console.log('%c📧 Hawkins Mail v.05', 'color:#667eea;font-size:15px;font-weight:bold');

/* ── State ──────────────────────────────────────────────────────── */
const S = {
    token: localStorage.getItem('token'),
    user: JSON.parse(localStorage.getItem('user') || 'null'),
    accounts: [],
    selectedAccount: null,
    messages: [],
    activeMessage: null,
    filter: 'all',
    search: '',
    page: 1,
    hasMore: true,
    syncing: false,
    editingAccountId: null,
    dateFrom: '',
    dateTo: '',
    readFilter: '',
    autoSyncTimer: null,
    categories: [],
};

/* ── Auth guard ─────────────────────────────────────────────────── */
if (!S.token) { window.location.href = '/login'; }

/* ── API helper ─────────────────────────────────────────────────── */
async function api(method, path, body = null) {
    const opts = {
        method,
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${S.token}`,
        },
    };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(`/api${path}`, opts);
    if (res.status === 401) { doLogout(); return null; }
    const text = await res.text();
    try { return { ok: res.ok, status: res.status, data: JSON.parse(text) }; }
    catch { return { ok: res.ok, status: res.status, data: text }; }
}

/* ── Toast ──────────────────────────────────────────────────────── */
function toast(msg, type = 'info') {
    const icons = { success: '✓', error: '✕', info: 'ℹ' };
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<span>${icons[type]}</span><span>${msg}</span>`;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

/* ── Logout ─────────────────────────────────────────────────────── */
function doLogout() {
    api('POST', '/auth/logout').catch(() => { });
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    window.location.href = '/login';
}

/* ── Date format ────────────────────────────────────────────────── */
function fmtDate(d) {
    const date = new Date(d);
    if (Number.isNaN(date.getTime())) return '';
    const now = new Date();

    const dayNames = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];
    const monthNames = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    const startOfWeek = (dt) => {
        const x = new Date(dt);
        const dow = x.getDay(); // 0 domingo
        const mondayOffset = dow === 0 ? -6 : 1 - dow;
        x.setHours(0, 0, 0, 0);
        x.setDate(x.getDate() + mondayOffset);
        return x;
    };

    const sameWeek = startOfWeek(date).getTime() === startOfWeek(now).getTime();
    if (sameWeek) {
        const hh = String(date.getHours()).padStart(2, '0');
        const mm = String(date.getMinutes()).padStart(2, '0');
        return `${dayNames[date.getDay()]} - ${hh}:${mm}`;
    }

    const sameMonth = date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth();
    if (sameMonth) {
        return `${date.getDate()} ${monthNames[date.getMonth()]}`;
    }

    if (date.getFullYear() !== now.getFullYear()) {
        return `${date.getDate()} ${date.getMonth() + 1} ${date.getFullYear()}`;
    }

    return `${date.getDate()} ${monthNames[date.getMonth()]}`;
}

/* ── Badge ──────────────────────────────────────────────────────── */
const BADGE_MAP = {
    'Interesantes': ['badge-interesantes', '⭐'],
    'SPAM': ['badge-spam', '🚫'],
    'EnCopia': ['badge-encopia', '📋'],
    'Servicios': ['badge-servicios', '🔔'],
};
function badge(label) {
    if (!label || !BADGE_MAP[label]) return '';
    const [cls, icon] = BADGE_MAP[label];
    return `<span class="classification-badge ${cls}">${icon} ${label}</span>`;
}

/* ── Render: sidebar user ───────────────────────────────────────── */
function renderUser() {
    const el = document.getElementById('sidebar-user');
    if (S.user) el.textContent = `👤 ${S.user.username}`;
}

/* ── Render: accounts ───────────────────────────────────────────── */
function renderAccounts() {
    const list = document.getElementById('accounts-list');
    if (!S.accounts.length) {
        list.innerHTML = '<p style="color:#666;font-size:.8rem;padding:.5rem 0">Sin cuentas. Pulsa + para añadir.</p>';
        return;
    }
    list.innerHTML = S.accounts.map(a => `
        <div class="account-item ${S.selectedAccount === a.id ? 'active' : ''}"
             data-id="${a.id}" onclick="selectAccount(${a.id})">
            <span class="account-name">${a.username || a.email_address}</span>
            <span class="account-email">${a.email_address}</span>
        </div>
    `).join('');
}

/* ── Render: folders ────────────────────────────────────────────── */
function renderFolders() {
    const host = document.getElementById('folders-list');
    const customNodes = host?.querySelectorAll('.folder-item[data-custom="1"]') || [];
    customNodes.forEach((n) => n.remove());

    const builtins = new Set(['Interesantes', 'Servicios', 'EnCopia', 'SPAM']);
    const customCategories = (S.categories || []).filter(c => c?.key && !builtins.has(c.key));
    const deletedNode = host?.querySelector('.folder-item[data-filter="deleted"]');

    customCategories.forEach((cat) => {
        const div = document.createElement('div');
        div.className = 'folder-item';
        div.dataset.filter = cat.key;
        div.dataset.custom = '1';
        div.innerHTML = `${escHtml(cat.name || cat.key)} <span class="total-count" id="count-${cat.key}"></span>`;
        if (deletedNode && deletedNode.parentNode) deletedNode.parentNode.insertBefore(div, deletedNode);
        else host?.appendChild(div);
    });

    document.querySelectorAll('.folder-item').forEach(el => {
        el.classList.toggle('active', el.dataset.filter === S.filter);
    });
    const trashBtn = document.getElementById('btn-empty-trash');
    if (trashBtn) trashBtn.style.display = S.filter === 'deleted' ? '' : 'none';
    bindFolderEvents();
}

async function loadUnreadCounts() {
    const r = await api('GET', '/messages/unread-counts');
    if (!r?.ok) return;
    const c = r.data || {};
    const ids = ['all', 'Sent', 'starred', 'Interesantes', 'Servicios', 'EnCopia', 'SPAM', 'deleted', ...(S.categories || []).map(x => x.key)];
    ids.forEach((k) => {
        const el = document.getElementById(`count-${k}`);
        if (!el) return;
        const n = Number(c[k] || c?.labels?.[k] || 0);
        el.textContent = n > 0 ? String(n) : '';
    });
}

/* ── Render: message list ───────────────────────────────────────── */
function renderMessages() {
    const container = document.getElementById('messages-container');
    if (!S.messages.length) {
        container.innerHTML = '<div class="empty-state"><p>Sin mensajes</p><p class="hint">Pulsa "Sincronizar" para descargar</p></div>';
        return;
    }
    container.innerHTML = S.messages.map(m => `
        <div class="message-item ${m.is_read ? 'read' : 'unread'} ${S.activeMessage?.id === m.id ? 'active' : ''}"
             data-id="${m.id}" draggable="true"
             ondragstart="onMessageDragStart(event,'${m.id}')"
             onclick="openMessage('${m.id}')" ondblclick="openMessageLarge('${m.id}')">
            <div class="message-from">
                ${m.is_read ? '' : '<span title="No leído">🔵</span>'}
                ${escHtml(isSentLikeMessage(m) ? (`Para: ${getPrimaryTo(m) || '(sin destinatario)'}`) : (m.from_name || m.from_email || ''))}
                ${badge(m.classification_label)}
            </div>
            <div class="message-date">${fmtDate(m.date)}</div>
            <div class="message-subject">${escHtml(m.subject || '(Sin asunto)')}</div>
            <div class="message-snippet">${escHtml(m.snippet || '')}</div>
            <div class="message-meta">
                ${isForwardedMessage(m) ? '<span title="Reenviado">↪</span>' : ''}
                ${isRepliedMessage(m) ? '<span title="Respondido">↩</span>' : ''}
                ${m.has_attachments ? '<span>📎</span>' : ''}
                <button class="btn-star" onclick="toggleStar(event,'${m.id}',${m.is_starred})"
                    title="${m.is_starred ? 'Quitar estrella' : 'Marcar'}">${m.is_starred ? '⭐' : '☆'}</button>
            </div>
        </div>
    `).join('');
}

function escHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function decodeQuotedPrintableText(input) {
    if (!input) return '';
    let s = String(input);
    s = s.replace(/=\r?\n/g, '');
    s = s.replace(/=([A-Fa-f0-9]{2})/g, (_, hex) => String.fromCharCode(parseInt(hex, 16)));
    return s;
}

function stripHtmlToText(html) {
    const el = document.createElement('div');
    el.innerHTML = String(html || '');
    return (el.textContent || el.innerText || '').trim();
}

function normalizeBodyTextForReply(rawText, rawHtml) {
    let text = String(rawText || '');
    if (/=[A-Fa-f0-9]{2}/.test(text) || text.includes('=0D=0A')) {
        text = decodeQuotedPrintableText(text);
    }
    if (/<html|<body|<table|<style/i.test(text)) {
        text = stripHtmlToText(text);
    }
    if (!text && rawHtml) {
        text = stripHtmlToText(decodeQuotedPrintableText(String(rawHtml)));
    }
    return text.trim();
}

function parseAddressList(raw) {
    if (!raw) return [];
    if (Array.isArray(raw)) return raw;
    if (typeof raw === 'string') {
        try {
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) return parsed;
        } catch (_) {}
        return raw.split(',').map(s => ({ name: '', email: s.trim() })).filter(x => x.email);
    }
    return [];
}

function getPrimaryTo(message) {
    const to = parseAddressList(message?.to_addresses);
    if (to.length) {
        if (typeof to[0] === 'string') return to[0];
        if (to[0] && typeof to[0] === 'object') return to[0].email || '';
    }
    return message?.to_email || '';
}

function isSentLikeMessage(message) {
    const from = String(message?.from_email || '').toLowerCase();
    const me = String(S.user?.username || '').toLowerCase();
    const hasTo = !!getPrimaryTo(message);
    return message?.folder === 'Sent' || (from !== '' && me !== '' && from === me && hasTo);
}

function isForwardedMessage(message) {
    if (message?.is_forwarded) return true;
    if (!isSentLikeMessage(message)) return false;
    const subject = String(message?.subject || '').trim().toLowerCase();
    const snippet = String(message?.snippet || '').toLowerCase();
    return subject.startsWith('fwd:') || subject.startsWith('fw:') || snippet.includes('mensaje reenviado');
}

function isRepliedMessage(message) {
    if (message?.is_replied) return true;
    if (!isSentLikeMessage(message)) return false;
    const subject = String(message?.subject || '').trim().toLowerCase();
    const snippet = String(message?.snippet || '').toLowerCase();
    return subject.startsWith('re:') || snippet.includes('mensaje original');
}

function applyUiFontSize(size) {
    const allowed = new Set(['12', '13', '14', '16']);
    const normalized = allowed.has(String(size)) ? String(size) : '13';
    document.documentElement.style.fontSize = `${normalized}px`;
    localStorage.setItem('ui_font_size', normalized);
}

function buildPreviewHtml(message) {
    let html = String(message?.body_html || '');
    if (!html) return '';

    const attachments = Array.isArray(message?.attachments) ? message.attachments : [];
    const imageAttachments = attachments.filter(a => String(a?.mime_type || '').toLowerCase().startsWith('image/'));
    const firstImageUrl = imageAttachments[0]?.id ? `/api/attachments/${imageAttachments[0].id}/download` : null;

    // Resolver referencias cid:... usadas por correos HTML para imágenes inline.
    html = html.replace(/src\s*=\s*(['"])cid:([^'"]+)\1/gi, (full, quote, cidValue) => {
        const cid = String(cidValue || '').toLowerCase();
        const match = imageAttachments.find((a) => {
            const name = String(a?.filename || '').toLowerCase();
            return name && (cid.includes(name) || name.includes(cid));
        });
        const url = match?.id ? `/api/attachments/${match.id}/download` : firstImageUrl;
        if (!url) return full;
        return `src=${quote}${url}${quote}`;
    });

    return html;
}

/* ── Render: message viewer ─────────────────────────────────────── */
async function renderViewer(msg) {
    const pane = document.getElementById('detail-pane');
    const viewer = document.getElementById('message-viewer');
    pane.style.display = '';
    document.getElementById('list-pane').classList.add('split');
    viewer.innerHTML = '<div class="loading-state">Cargando…</div>';

    const r = await api('GET', `/messages/${msg.id}`);
    if (!r || !r.ok) { viewer.innerHTML = '<div class="empty-state"><p>Error al cargar</p></div>'; return; }
    const m = r.data;
    S.activeMessage = m;

    const attachments = (m.attachments || []).map(a =>
        `<a class="attachment-chip" href="/api/attachments/${a.id}/download"
            target="_blank" rel="noopener" onclick="dlAttachment(event,${a.id})">📎 ${escHtml(a.filename)}</a>`
    ).join('');

    const previewHtml = buildPreviewHtml(m);
    const normalizedText = normalizeBodyTextForReply(m.body_text, m.body_html);
    const bodyHtml = previewHtml
        ? `<div class="viewer-body-html"><iframe srcdoc="${escHtml(previewHtml)}" sandbox="allow-same-origin"></iframe></div>`
        : `<div class="viewer-body-text">${escHtml(normalizedText || '')}</div>`;

    viewer.innerHTML = `
        <div class="message-viewer-wrap">
            <div class="viewer-header">
                <div class="viewer-subject">${escHtml(m.subject || '(Sin asunto)')}</div>
                <div class="viewer-meta">
                    <div><strong>De:</strong> ${escHtml(m.from_name ? `${m.from_name} <${m.from_email}>` : m.from_email)}</div>
                    <div><strong>Para:</strong> ${escHtml(getPrimaryTo(m) || '')}</div>
                    <div><strong>Fecha:</strong> ${new Date(m.date).toLocaleString('es-ES')}</div>
                </div>
                <div class="viewer-actions">
                    <button class="btn-toolbar" onclick="replyTo('reply')">↩ Responder</button>
                    <button class="btn-toolbar" onclick="replyTo('reply_all')">↩ Resp. todos</button>
                    <button class="btn-toolbar" onclick="replyTo('forward')">↪ Reenviar</button>
                    <button class="btn-toolbar" onclick="markAsSpam('${m.id}')">🚫 Marcar SPAM</button>
                    <button class="btn-toolbar" onclick="toggleRead('${m.id}', ${m.is_read ? 'true' : 'false'})">${m.is_read ? 'Marcar no leído' : 'Marcar leído'}</button>
                    <button class="btn-toolbar" onclick="deleteMsg('${m.id}')">🗑️ Eliminar</button>
                </div>
            </div>
            <div class="viewer-body">${bodyHtml}</div>
            ${attachments ? `<div class="viewer-attachments"><h4>Adjuntos</h4>${attachments}</div>` : ''}
        </div>`;

    // Mark as read
    if (!m.is_read) {
        await api('PUT', `/messages/${m.id}/read`, { is_read: true });
        const idx = S.messages.findIndex(x => x.id === m.id);
        if (idx >= 0) S.messages[idx].is_read = true;
        renderMessages();
    }
}

/* ── Load messages ──────────────────────────────────────────────── */
async function loadMessages(reset = true) {
    if (reset) { S.page = 1; S.messages = []; S.hasMore = true; }
    if (!S.hasMore) return;

    const params = new URLSearchParams({ page: S.page, per_page: 50 });
    if (S.selectedAccount) params.set('account_id', S.selectedAccount);
    if (S.filter === 'all') params.set('folder', 'INBOX');
    else if (S.filter === 'starred') params.set('starred', '1');
    else if (S.filter === 'deleted') params.set('deleted', '1');
    else if (['Sent'].includes(S.filter)) params.set('folder', S.filter);
    else if (S.filter !== 'all') params.set('label', S.filter);
    if (S.dateFrom) params.set('date_from', S.dateFrom);
    if (S.dateTo) params.set('date_to', S.dateTo);
    if (S.readFilter !== '') params.set('is_read', S.readFilter);
    if (S.search) params.set('search', S.search);

    document.getElementById('messages-container').innerHTML = '<div class="loading-state">Cargando…</div>';
    const r = await api('GET', `/messages?${params}`);
    if (!r || !r.ok) { toast('Error al cargar mensajes', 'error'); return; }

    const data = r.data;
    const msgs = Array.isArray(data) ? data : (data.data || data.messages || []);
    S.messages = reset ? msgs : [...S.messages, ...msgs];
    S.hasMore = msgs.length === 50;
    S.page++;
    renderMessages();
    loadUnreadCounts();
}

/* ── Load accounts ──────────────────────────────────────────────── */
async function loadAccounts() {
    const r = await api('GET', '/accounts');
    if (!r || !r.ok) return;
    S.accounts = Array.isArray(r.data) ? r.data : (r.data.data || []);
    renderAccounts();
    if (!S.selectedAccount && S.accounts.length) {
        S.selectedAccount = S.accounts[0].id;
        renderAccounts();
    } else if (S.accounts.length === 0) {
        openAccountModal();
    }
}

async function loadCategories() {
    const r = await api('GET', '/categories');
    if (!r?.ok) return;
    S.categories = Array.isArray(r.data) ? r.data : [];
    renderFolders();
    loadUnreadCounts();
}

function getCustomCategoryByKey(key) {
    const builtins = new Set(['Interesantes', 'Servicios', 'EnCopia', 'SPAM']);
    return (S.categories || []).find(c => c?.key === key && !builtins.has(c.key));
}

async function createCustomFolder() {
    const name = (window.prompt('Nombre de la carpeta personalizada:', '') || '').trim();
    if (!name) return;
    const key = name
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 100) || `cat_${Date.now()}`;
    const aiInstruction = (window.prompt('Instrucción para IA (opcional):', `Correos relacionados con ${name}.`) || '').trim();

    const r = await api('POST', '/categories', {
        key,
        name,
        ai_instruction: aiInstruction || null,
        is_system: false,
    });
    if (!r?.ok) {
        toast(r?.data?.error || r?.data?.message || 'No se pudo crear la carpeta', 'error');
        return;
    }
    toast('Carpeta creada', 'success');
    await loadCategories();
}

async function deleteSelectedFolder() {
    const category = getCustomCategoryByKey(S.filter);
    if (!category) {
        toast('Selecciona una carpeta personalizada para eliminarla', 'info');
        return;
    }
    if (!confirm(`¿Eliminar la carpeta "${category.name}"?`)) return;
    const r = await api('DELETE', `/categories/${category.id}`);
    if (!r?.ok) {
        toast(r?.data?.error || r?.data?.message || 'No se pudo eliminar la carpeta', 'error');
        return;
    }
    if (S.filter === category.key) S.filter = 'all';
    toast('Carpeta eliminada', 'success');
    await loadCategories();
    await loadMessages(true);
}

function bindFolderEvents() {
    document.querySelectorAll('.folder-item').forEach(el => {
        if (el.dataset.bound === '1') return;
        el.dataset.bound = '1';

        el.addEventListener('click', () => {
            S.filter = el.dataset.filter;
            renderAccounts();
            renderFolders();
            closeViewer();
            loadMessages();
        });

        el.addEventListener('dragover', (ev) => {
            ev.preventDefault();
            el.classList.add('drop-target');
        });
        el.addEventListener('dragleave', () => {
            el.classList.remove('drop-target');
        });
        el.addEventListener('drop', async (ev) => {
            ev.preventDefault();
            el.classList.remove('drop-target');
            const messageId = ev.dataTransfer.getData('text/plain');
            const targetFilter = el.dataset.filter;
            await setMessageFolderByDrop(messageId, targetFilter);
        });
    });
}

/* ── Select account ─────────────────────────────────────────────── */
window.selectAccount = function (id) {
    S.selectedAccount = id;
    S.filter = 'all';
    renderAccounts();
    renderFolders();
    closeViewer();
    loadMessages();
};

/* ── Open message ───────────────────────────────────────────────── */
window.openMessage = function (id) {
    S.activeMessage = S.messages.find(m => m.id === id) || { id };
    renderMessages();
    renderViewer(S.activeMessage);
};

/* ── Toggle star ────────────────────────────────────────────────── */
window.toggleStar = async function (e, id, current) {
    e.stopPropagation();
    const r = await api('PATCH', `/messages/${id}`, { is_starred: !current });
    if (r?.ok) {
        const idx = S.messages.findIndex(m => m.id === id);
        if (idx >= 0) S.messages[idx].is_starred = !current;
        renderMessages();
    }
};

window.toggleRead = async function (id, current) {
    const r = await api('PUT', `/messages/${id}/read`, { is_read: !current });
    if (!r?.ok) {
        toast('Error al actualizar lectura', 'error');
        return;
    }
    const idx = S.messages.findIndex(m => m.id === id);
    if (idx >= 0) S.messages[idx].is_read = !current;
    if (S.activeMessage?.id === id) S.activeMessage.is_read = !current;
    renderMessages();
};

async function setMessageFolderByDrop(messageId, targetFilter) {
    if (!messageId || !targetFilter || targetFilter === 'all') return;

    if (targetFilter === 'deleted') {
        return deleteMsg(messageId);
    }

    if (targetFilter === 'starred') {
        const msg = S.messages.find(m => m.id === messageId);
        return toggleStar({ stopPropagation() {} }, messageId, !!msg?.is_starred);
    }

    const allowedLabels = Array.from(new Set(['Interesantes', 'Servicios', 'EnCopia', 'SPAM', ...(S.categories || []).map(c => c.key)]));
    if (!allowedLabels.includes(targetFilter)) return;

    const r = await api('PUT', `/messages/${messageId}/classify`, { classification_label: targetFilter });
    if (!r?.ok) {
        toast('No se pudo mover el mensaje', 'error');
        return;
    }

    await loadMessages(true);
    await loadUnreadCounts();
    toast(`Mensaje movido a ${targetFilter}`, 'success');
}

window.markAsSpam = async function (id) {
    await setMessageFolderByDrop(id, 'SPAM');
};

window.onMessageDragStart = function (e, id) {
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', id);
};

/* ── Delete message ─────────────────────────────────────────────── */
window.deleteMsg = async function (id) {
    const r = await api('DELETE', `/messages/${id}`);
    if (r?.ok) {
        S.messages = S.messages.filter(m => m.id !== id);
        closeViewer();
        renderMessages();
        loadUnreadCounts();
        toast('Mensaje eliminado', 'success');
    } else {
        toast('Error al eliminar', 'error');
    }
};

/* ── Close viewer ───────────────────────────────────────────────── */
function closeViewer() {
    S.activeMessage = null;
    document.getElementById('detail-pane').style.display = 'none';
    document.getElementById('list-pane').classList.remove('split');
}

/* ── Download attachment ────────────────────────────────────────── */
window.dlAttachment = async function (e, id) {
    e.preventDefault();
    const r = await fetch(`/api/attachments/${id}/download`, {
        headers: { 'Authorization': `Bearer ${S.token}` }
    });
    if (!r.ok) { toast('Error al descargar adjunto', 'error'); return; }
    const blob = await r.blob();
    const cd = r.headers.get('Content-Disposition') || '';
    const fname = (cd.match(/filename="?([^"]+)"?/) || [])[1] || `adjunto_${id}`;
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = fname; a.click();
    URL.revokeObjectURL(url);
};

/* ── Sync ───────────────────────────────────────────────────────── */
async function doSync() {
    if (S.syncing) return;
    S.syncing = true;
    const btn = document.getElementById('btn-sync');
    btn.disabled = true;
    btn.textContent = '⏳ Sincronizando…';

    const statusEl = document.getElementById('sync-status');
    statusEl.style.display = '';
    statusEl.innerHTML = 'Iniciando sincronización…';

    try {
        const accId = S.selectedAccount;
        const body = accId ? { account_id: accId } : {};

        const res = await fetch('/api/sync/stream', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${S.token}` },
            body: JSON.stringify(body)
        });

        const reader = res.body.getReader();
        const dec = new TextDecoder();
        let buf = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            buf += dec.decode(value, { stream: true });
            const lines = buf.split('\n');
            buf = lines.pop();
            for (const line of lines) {
                if (!line.startsWith('data:')) continue;
                try {
                    const ev = JSON.parse(line.slice(5).trim());
                    updateSyncStatus(ev, statusEl);
                    if (ev.status === 'error') {
                        throw new Error(ev.error || ev.message || 'Error desconocido al sincronizar');
                    }
                } catch (e) {
                    if (!(e instanceof SyntaxError)) {
                        throw e;
                    }
                }
            }
        }

        toast('Sincronización completada', 'success');
        loadMessages(true);
    } catch (ex) {
        toast('Error en sincronización: ' + ex.message, 'error');
    } finally {
        S.syncing = false;
        btn.disabled = false;
        btn.textContent = '🔄 Sincronizar';
        setTimeout(() => { statusEl.style.display = 'none'; }, 5000);
    }
}

function updateSyncStatus(ev, el) {
    const phase = ev.phase || '';
    const msg = ev.message || ev.error || '';
    const cur = ev.current ?? 0;
    const tot = ev.total ?? 0;
    const pct = tot > 0 ? Math.round((cur / tot) * 100) : 0;
    el.innerHTML = `
        <div>${phase ? `[${phase}] ` : ''}${escHtml(msg)}</div>
        ${tot > 0 ? `<div>${cur}/${tot} completados (${pct}%)</div>
        <div class="sync-bar"><div class="sync-bar-fill" style="width:${pct}%"></div></div>` : ''}
    `;
}

/* ── Compose ────────────────────────────────────────────────────── */
let _composeContext = null;

function openCompose(mode = 'new', originalMsg = null) {
    _composeContext = { mode, originalMsg };
    document.getElementById('compose-title').textContent =
        mode === 'reply' ? 'Responder' : mode === 'reply_all' ? 'Resp. a todos' : mode === 'forward' ? 'Reenviar' : 'Redactar';

    // Populate from accounts
    const sel = document.getElementById('compose-from');
    sel.innerHTML = S.accounts.map(a => `<option value="${a.id}">${a.email_address}</option>`).join('');
    if (S.selectedAccount) sel.value = S.selectedAccount;

    // Pre-fill fields
    const to = document.getElementById('compose-to');
    const subject = document.getElementById('compose-subject');
    const body = document.getElementById('compose-body');
    const aiInstruction = document.getElementById('compose-ai-instruction');
    const files = document.getElementById('compose-files');

    to.value = ''; subject.value = ''; body.value = '';
    if (aiInstruction) aiInstruction.value = '';
    if (files) files.value = '';

    if (originalMsg && mode !== 'new') {
        if (mode === 'reply') to.value = originalMsg.from_email || '';
        if (mode === 'reply_all') to.value = [originalMsg.from_email, originalMsg.to_email].filter(Boolean).join(', ');
        if (mode === 'forward') to.value = '';
        const cleanOriginal = normalizeBodyTextForReply(originalMsg.body_text, originalMsg.body_html);
        subject.value = (mode === 'forward' ? 'Fwd: ' : 'Re: ') + (originalMsg.subject || '');
        body.value = mode === 'forward'
            ? `\n\n-------- Mensaje reenviado --------\nDe: ${originalMsg.from_email}\nFecha: ${new Date(originalMsg.date).toLocaleString('es-ES')}\nAsunto: ${originalMsg.subject}\n\n${cleanOriginal}`
            : `\n\n> ${cleanOriginal.split('\n').join('\n> ')}`;
    }

    document.getElementById('modal-compose').style.display = 'flex';
    to.focus();
}

window.replyTo = function (mode) {
    openCompose(mode, S.activeMessage);
};

async function generateComposeWithAI() {
    const instruction = document.getElementById('compose-ai-instruction').value.trim();
    if (!instruction) {
        toast('Escribe una instrucción para IA', 'error');
        return;
    }

    const original = _composeContext?.originalMsg || S.activeMessage || {};
    const selectedAccount = S.accounts.find(a => a.id == document.getElementById('compose-from').value) || {};
    const ownerProfile = selectedAccount.owner_profile || '';
    const btn = document.getElementById('btn-generate-compose-ai');
    btn.disabled = true;
    btn.textContent = 'Generando…';

    const r = await api('POST', '/ai/generate_reply', {
        original_from_name: original.from_name || '',
        original_from_email: original.from_email || document.getElementById('compose-to').value.trim(),
        original_subject: original.subject || document.getElementById('compose-subject').value.trim(),
        original_body: original.body_text || '',
        user_instruction: instruction,
        owner_profile: ownerProfile || 'Responde de forma breve, clara y profesional.',
    });

    btn.disabled = false;
    btn.textContent = 'Generar con IA';

    if (r?.ok && r.data?.reply_body) {
        document.getElementById('compose-body').value = r.data.reply_body;
        toast('Borrador generado con IA', 'success');
    } else {
        toast(r?.data?.error || 'No se pudo generar el borrador', 'error');
    }
}

window.openMessageLarge = async function (id) {
    const r = await api('GET', `/messages/${id}`);
    if (!r?.ok) {
        toast('No se pudo abrir el mensaje', 'error');
        return;
    }
    const m = r.data;
    S.activeMessage = m;
    const attachments = (m.attachments || []).map(a =>
        `<a class="attachment-chip" href="/api/attachments/${a.id}/download"
            target="_blank" rel="noopener" onclick="dlAttachment(event,${a.id})">📎 ${escHtml(a.filename)}</a>`
    ).join('');
    const previewHtml = buildPreviewHtml(m);
    const normalizedText = normalizeBodyTextForReply(m.body_text, m.body_html);
    const body = previewHtml
        ? `<div class="viewer-body-html"><iframe srcdoc="${escHtml(previewHtml)}" sandbox="allow-same-origin"></iframe></div>`
        : `<div class="viewer-body-text">${escHtml(normalizedText || '')}</div>`;
    document.getElementById('message-large-content').innerHTML = `
        <div class="message-viewer-wrap">
            <div class="viewer-subject">${escHtml(m.subject || '(Sin asunto)')}</div>
            <div class="viewer-meta">
                <div><strong>De:</strong> ${escHtml(m.from_name ? `${m.from_name} <${m.from_email}>` : (m.from_email || ''))}</div>
            <div><strong>Para:</strong> ${escHtml(getPrimaryTo(m) || '')}</div>
                <div><strong>Fecha:</strong> ${m.date ? new Date(m.date).toLocaleString('es-ES') : ''}</div>
            </div>
            <div class="viewer-actions">
                <button class="btn-toolbar" onclick="replyTo('reply')">↩ Responder</button>
                <button class="btn-toolbar" onclick="replyTo('reply_all')">↩ Resp. todos</button>
                <button class="btn-toolbar" onclick="replyTo('forward')">↪ Reenviar</button>
                <button class="btn-toolbar" onclick="markAsSpam('${m.id}')">🚫 Marcar SPAM</button>
                <button class="btn-toolbar" onclick="toggleRead('${m.id}', ${m.is_read ? 'true' : 'false'})">${m.is_read ? 'Marcar no leído' : 'Marcar leído'}</button>
                <button class="btn-toolbar" onclick="deleteMsg('${m.id}')">🗑️ Eliminar</button>
            </div>
            <div class="viewer-body" style="margin-top:1rem">${body}</div>
            ${attachments ? `<div class="viewer-attachments"><h4>Adjuntos</h4>${attachments}</div>` : ''}
        </div>
    `;
    document.getElementById('modal-message-large').style.display = 'flex';
};

async function refreshAiHealth() {
    const dot = document.getElementById('ai-health-dot');
    if (!dot) return;
    const r = await api('GET', '/ai/status');
    const up = !!(r?.ok && r.data?.available);
    dot.style.background = up ? '#16a34a' : '#dc2626';
    dot.title = up ? 'IA operativa' : ('IA no disponible: ' + (r?.data?.reason || 'sin detalle'));
}

async function sendEmail() {
    const accountId = parseInt(document.getElementById('compose-from').value);
    const to = document.getElementById('compose-to').value.trim();
    const cc = document.getElementById('compose-cc').value.trim();
    const subject = document.getElementById('compose-subject').value.trim();
    const body = document.getElementById('compose-body').value;
    const files = Array.from(document.getElementById('compose-files').files || []);

    if (!to || !subject) { toast('Destinatario y asunto obligatorios', 'error'); return; }

    const btn = document.getElementById('btn-send');
    btn.disabled = true; btn.textContent = 'Enviando…';

    const payload = { account_id: accountId, to, subject, body_text: body };
    payload.compose_mode = _composeContext?.mode || 'new';
    if (cc) payload.cc = cc;
    if (_composeContext?.originalMsg?.id) payload.reply_to_message_id = _composeContext.originalMsg.id;
    if (files.length) {
        payload.attachments = await Promise.all(files.map(async (f) => ({
            name: f.name,
            mime_type: f.type || 'application/octet-stream',
            content_base64: await fileToBase64(f),
        })));
    }

    const r = await api('POST', '/send', payload);
    btn.disabled = false; btn.textContent = '📤 Enviar';

    if (r?.ok) {
        toast('Mensaje enviado', 'success');
        document.getElementById('modal-compose').style.display = 'none';
        document.getElementById('compose-files').value = '';
        await doSync();
    } else {
        toast(r?.data?.message || 'Error al enviar', 'error');
    }
}

function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const fr = new FileReader();
        fr.onload = () => {
            const res = String(fr.result || '');
            const base64 = res.includes(',') ? res.split(',')[1] : res;
            resolve(base64);
        };
        fr.onerror = reject;
        fr.readAsDataURL(file);
    });
}

/* ── Account modal ──────────────────────────────────────────────── */
function openAccountModal(acc = null) {
    S.editingAccountId = acc?.id || null;
    document.getElementById('account-modal-title').textContent = acc ? 'Editar cuenta' : 'Añadir cuenta';
    document.getElementById('acc-name').value = acc?.username || '';
    const platformEmail = S.user?.username || '';
    document.getElementById('acc-email').value = platformEmail || acc?.email_address || '';
    document.getElementById('acc-email').readOnly = true;
    document.getElementById('acc-email').title = 'Se usa automáticamente el email del registro en la plataforma';
    const pwd = document.getElementById('acc-password');
    pwd.value = '';
    pwd.readOnly = true;
    pwd.disabled = true;
    pwd.placeholder = 'Se usa automáticamente la contraseña del registro';
    document.getElementById('acc-imap-host').value = acc?.imap_host || 'pop.ionos.es';
    document.getElementById('acc-imap-port').value = acc?.imap_port || 995;
    document.getElementById('acc-imap-ssl').value = acc?.imap_ssl ? '1' : '0';
    document.getElementById('acc-smtp-host').value = acc?.smtp_host || 'smtp.ionos.es';
    document.getElementById('acc-smtp-port').value = acc?.smtp_port || 465;
    document.getElementById('acc-smtp-ssl').value = acc?.smtp_ssl ? '1' : '0';
    document.getElementById('acc-owner-profile').value = acc?.owner_profile || '';
    document.getElementById('acc-custom-classification-prompt').value = acc?.custom_classification_prompt || '';
    document.getElementById('acc-signature-html').value = acc?.signature_html || '';
    const currentFont = localStorage.getItem('ui_font_size') || '13';
    const fontSel = document.getElementById('acc-font-size');
    if (fontSel) fontSel.value = currentFont;
    document.getElementById('modal-account').style.display = 'flex';
}

async function saveAccount() {
    const emailStr = (S.user?.username || document.getElementById('acc-email').value || '').trim();
    const imapHost = document.getElementById('acc-imap-host').value.trim();
    const imapPort = parseInt(document.getElementById('acc-imap-port').value);
    const rawPassword = '';
    const tempPlatformPassword = sessionStorage.getItem('platform_password_temp') || '';
    const inferredProtocol = (imapHost.toLowerCase().startsWith('pop.') || [110, 965, 995].includes(imapPort)) ? 'pop3' : 'imap';
    const body = {
        name: document.getElementById('acc-name').value.trim(),
        email_address: emailStr,
        username: emailStr, // Generalmente se usa el email como usuario en IMAP
        imap_host: imapHost,
        imap_port: imapPort,
        smtp_host: document.getElementById('acc-smtp-host').value.trim(),
        smtp_port: parseInt(document.getElementById('acc-smtp-port').value),
        ssl_verify: document.getElementById('acc-imap-ssl').value === '1',
        protocol: inferredProtocol,
        owner_profile: document.getElementById('acc-owner-profile').value.trim(),
        custom_classification_prompt: document.getElementById('acc-custom-classification-prompt').value.trim(),
        signature_html: document.getElementById('acc-signature-html').value,
    };
    if (!S.editingAccountId && tempPlatformPassword) {
        // Primera configuración: usar automáticamente la misma contraseña del registro/login.
        body.password = tempPlatformPassword;
    } else if (!S.editingAccountId) {
        toast('Para la primera configuración debes iniciar sesión de nuevo o escribir la contraseña manualmente.', 'error');
        return;
    }
    if (!body.email_address) { toast('El email es obligatorio', 'error'); return; }

    const btn = document.getElementById('btn-save-account');
    btn.disabled = true; btn.textContent = 'Guardando…';

    const r = S.editingAccountId
        ? await api('PUT', `/accounts/${S.editingAccountId}`, body)
        : await api('POST', '/accounts', body);

    btn.disabled = false; btn.textContent = 'Guardar';

    if (r?.ok) {
        const fontSel = document.getElementById('acc-font-size');
        if (fontSel) applyUiFontSize(fontSel.value);
        toast(S.editingAccountId ? 'Cuenta actualizada' : 'Cuenta añadida', 'success');
        document.getElementById('modal-account').style.display = 'none';
        if (!S.editingAccountId) {
            // Solo se necesita para la primera configuración automática.
            sessionStorage.removeItem('platform_password_temp');
        }
        await loadAccounts();
        loadMessages(true);
    } else {
        const msg = r?.data?.message || Object.values(r?.data?.errors || {})[0]?.[0] || 'Error al guardar';
        toast(msg, 'error');
    }
}

/* ── Mark all read ──────────────────────────────────────────────── */
async function markAllRead() {
    const params = {};
    if (S.selectedAccount) params.account_id = S.selectedAccount;
    const r = await api('PATCH', '/messages/mark-all-read', params);
    if (r?.ok) {
        S.messages.forEach(m => m.is_read = true);
        renderMessages();
        loadUnreadCounts();
        toast('Todos marcados como leídos', 'success');
    } else {
        toast('Error', 'error');
    }
}

/* ── Event listeners ────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', async () => {
    applyUiFontSize(localStorage.getItem('ui_font_size') || '13');
    renderUser();

    // Auth check
    const me = await api('GET', '/auth/me');
    if (!me || !me.ok) { doLogout(); return; }

    await loadAccounts();
    await loadCategories();
    await loadMessages();
    await loadUnreadCounts();
    await refreshAiHealth();
    // Sincronizar automáticamente al entrar.
    doSync();

    // Sidebar buttons
    document.getElementById('btn-sync').addEventListener('click', doSync);
    document.getElementById('btn-compose-sidebar').addEventListener('click', () => openCompose('new'));
    document.getElementById('btn-compose').addEventListener('click', () => openCompose('new'));
    document.getElementById('btn-logout').addEventListener('click', doLogout);
    document.getElementById('btn-add-account').addEventListener('click', () => openAccountModal());
    document.getElementById('btn-add-folder').addEventListener('click', createCustomFolder);
    document.getElementById('btn-delete-folder').addEventListener('click', deleteSelectedFolder);
    document.getElementById('btn-mark-read').addEventListener('click', markAllRead);
    document.getElementById('filter-date-from').addEventListener('change', (e) => { S.dateFrom = e.target.value; loadMessages(); });
    document.getElementById('filter-date-to').addEventListener('change', (e) => { S.dateTo = e.target.value; loadMessages(); });
    document.getElementById('filter-read').addEventListener('change', (e) => { S.readFilter = e.target.value; loadMessages(); });
    document.getElementById('btn-clear-filters').addEventListener('click', () => {
        S.dateFrom = '';
        S.dateTo = '';
        S.readFilter = '';
        document.getElementById('filter-date-from').value = '';
        document.getElementById('filter-date-to').value = '';
        document.getElementById('filter-read').value = '';
        loadMessages();
    });

    // Settings button → re-use account modal for now
    document.getElementById('btn-settings').addEventListener('click', () => {
        if (S.accounts.length) openAccountModal(S.accounts.find(a => a.id === S.selectedAccount) || S.accounts[0]);
        else openAccountModal();
    });

    // Folder filters
    bindFolderEvents();

    // Search (debounced)
    let searchTimer;
    document.getElementById('search-input').addEventListener('input', e => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            S.search = e.target.value.trim();
            loadMessages();
        }, 400);
    });

    // Compose modal
    document.getElementById('btn-close-compose').addEventListener('click', () => {
        document.getElementById('modal-compose').style.display = 'none';
    });
    document.getElementById('btn-cancel-compose').addEventListener('click', () => {
        document.getElementById('modal-compose').style.display = 'none';
    });
    document.getElementById('btn-send').addEventListener('click', sendEmail);
    document.getElementById('btn-generate-compose-ai').addEventListener('click', generateComposeWithAI);
    document.getElementById('btn-close-message-large').addEventListener('click', () => {
        document.getElementById('modal-message-large').style.display = 'none';
    });

    // Account modal
    document.getElementById('btn-close-account').addEventListener('click', () => {
        document.getElementById('modal-account').style.display = 'none';
    });
    document.getElementById('btn-cancel-account').addEventListener('click', () => {
        document.getElementById('modal-account').style.display = 'none';
    });
    document.getElementById('btn-save-account').addEventListener('click', saveAccount);

    // Close modals on overlay click
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) overlay.style.display = 'none';
        });
    });

    // Empty trash
    document.getElementById('btn-empty-trash').addEventListener('click', async () => {
        if (!confirm('¿Vaciar papelera? Los mensajes se eliminarán permanentemente.')) return;
        const params = S.selectedAccount ? `?account_id=${S.selectedAccount}` : '';
        const r = await api('DELETE', `/messages/trash${params}`);
        if (r?.ok) { toast('Papelera vaciada', 'success'); loadMessages(true); }
        else toast('Error al vaciar papelera', 'error');
    });

    // Infinite scroll
    document.getElementById('list-pane').addEventListener('scroll', e => {
        const el = e.target;
        if (el.scrollTop + el.clientHeight >= el.scrollHeight - 100) {
            loadMessages(false);
        }
    });

    // Auto-sync cada minuto (si no hay una sync en curso, doSync ya lo evita).
    if (S.autoSyncTimer) clearInterval(S.autoSyncTimer);
    S.autoSyncTimer = setInterval(() => {
        if (document.visibilityState === 'visible') {
            doSync();
        }
    }, 60000);
});
