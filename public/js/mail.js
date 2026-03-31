/**
 * Hawkins Mail v.05 – Vanilla JS frontend
 * Calls the existing Laravel API at /api/*
 */
console.log('%c📧 Hawkins Mail v.05', 'color:#667eea;font-size:15px;font-weight:bold');

/* ── State ──────────────────────────────────────────────────────── */
const S = {
    token:           localStorage.getItem('token'),
    user:            JSON.parse(localStorage.getItem('user') || 'null'),
    accounts:        [],
    selectedAccount: null,
    messages:        [],
    activeMessage:   null,
    filter:          'all',
    search:          '',
    page:            1,
    hasMore:         true,
    syncing:         false,
    editingAccountId: null,
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
    api('POST', '/auth/logout').catch(() => {});
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    window.location.href = '/login';
}

/* ── Date format ────────────────────────────────────────────────── */
function fmtDate(d) {
    const date = new Date(d), now = new Date();
    const diff = Math.floor((now - date) / 86400000);
    if (diff === 0) return date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
    if (diff === 1) return 'Ayer';
    if (diff < 7) return date.toLocaleDateString('es-ES', { weekday: 'short' });
    return date.toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
}

/* ── Badge ──────────────────────────────────────────────────────── */
const BADGE_MAP = {
    'Interesantes': ['badge-interesantes', '⭐'],
    'SPAM':         ['badge-spam',         '🚫'],
    'EnCopia':      ['badge-encopia',      '📋'],
    'Servicios':    ['badge-servicios',    '🔔'],
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
            <span class="account-name">${a.name || ''}</span>
            <span class="account-email">${a.email}</span>
        </div>
    `).join('');
}

/* ── Render: folders ────────────────────────────────────────────── */
function renderFolders() {
    document.querySelectorAll('.folder-item').forEach(el => {
        el.classList.toggle('active', el.dataset.filter === S.filter);
    });
    const trashBtn = document.getElementById('btn-empty-trash');
    if (trashBtn) trashBtn.style.display = S.filter === 'deleted' ? '' : 'none';
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
             data-id="${m.id}" onclick="openMessage('${m.id}')">
            <div class="message-from">
                ${escHtml(m.from_name || m.from_email || '')}
                ${badge(m.classification_label)}
            </div>
            <div class="message-date">${fmtDate(m.date)}</div>
            <div class="message-subject">${escHtml(m.subject || '(Sin asunto)')}</div>
            <div class="message-snippet">${escHtml(m.snippet || '')}</div>
            <div class="message-meta">
                ${m.has_attachments ? '<span>📎</span>' : ''}
                <button class="btn-star" onclick="toggleStar(event,'${m.id}',${m.is_starred})"
                    title="${m.is_starred ? 'Quitar estrella' : 'Marcar'}">${m.is_starred ? '⭐' : '☆'}</button>
            </div>
        </div>
    `).join('');
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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

    const attachments = (m.attachments || []).map(a =>
        `<a class="attachment-chip" href="/api/attachments/${a.id}/download"
            target="_blank" rel="noopener" onclick="dlAttachment(event,${a.id})">📎 ${escHtml(a.filename)}</a>`
    ).join('');

    const bodyHtml = m.body_html
        ? `<div class="viewer-body-html"><iframe srcdoc="${escHtml(m.body_html)}" sandbox="allow-same-origin"></iframe></div>`
        : `<div class="viewer-body-text">${escHtml(m.body_text || '')}</div>`;

    viewer.innerHTML = `
        <div class="message-viewer-wrap">
            <div class="viewer-header">
                <div class="viewer-subject">${escHtml(m.subject || '(Sin asunto)')}</div>
                <div class="viewer-meta">
                    <div><strong>De:</strong> ${escHtml(m.from_name ? `${m.from_name} <${m.from_email}>` : m.from_email)}</div>
                    <div><strong>Para:</strong> ${escHtml(m.to_email || '')}</div>
                    <div><strong>Fecha:</strong> ${new Date(m.date).toLocaleString('es-ES')}</div>
                </div>
                <div class="viewer-actions">
                    <button class="btn-toolbar" onclick="replyTo('reply')">↩ Responder</button>
                    <button class="btn-toolbar" onclick="replyTo('reply_all')">↩ Resp. todos</button>
                    <button class="btn-toolbar" onclick="replyTo('forward')">↪ Reenviar</button>
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
    if (S.filter === 'starred') params.set('starred', '1');
    else if (S.filter === 'deleted') params.set('deleted', '1');
    else if (S.filter !== 'all') params.set('label', S.filter);
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
    }
}

/* ── Select account ─────────────────────────────────────────────── */
window.selectAccount = function(id) {
    S.selectedAccount = id;
    S.filter = 'all';
    renderAccounts();
    renderFolders();
    closeViewer();
    loadMessages();
};

/* ── Open message ───────────────────────────────────────────────── */
window.openMessage = function(id) {
    S.activeMessage = S.messages.find(m => m.id === id) || { id };
    renderMessages();
    renderViewer(S.activeMessage);
};

/* ── Toggle star ────────────────────────────────────────────────── */
window.toggleStar = async function(e, id, current) {
    e.stopPropagation();
    const r = await api('PATCH', `/messages/${id}`, { is_starred: !current });
    if (r?.ok) {
        const idx = S.messages.findIndex(m => m.id === id);
        if (idx >= 0) S.messages[idx].is_starred = !current;
        renderMessages();
    }
};

/* ── Delete message ─────────────────────────────────────────────── */
window.deleteMsg = async function(id) {
    const r = await api('DELETE', `/messages/${id}`);
    if (r?.ok) {
        S.messages = S.messages.filter(m => m.id !== id);
        closeViewer();
        renderMessages();
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
window.dlAttachment = async function(e, id) {
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
                } catch {}
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
        setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
    }
}

function updateSyncStatus(ev, el) {
    const phase = ev.phase || '';
    const msg   = ev.message || '';
    const cur   = ev.current ?? 0;
    const tot   = ev.total   ?? 0;
    const pct   = tot > 0 ? Math.round((cur / tot) * 100) : 0;
    el.innerHTML = `
        <div>${phase ? `[${phase}] ` : ''}${escHtml(msg)}</div>
        ${tot > 0 ? `<div>${cur}/${tot}</div>
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
    sel.innerHTML = S.accounts.map(a => `<option value="${a.id}">${a.email}</option>`).join('');
    if (S.selectedAccount) sel.value = S.selectedAccount;

    // Pre-fill fields
    const to      = document.getElementById('compose-to');
    const subject = document.getElementById('compose-subject');
    const body    = document.getElementById('compose-body');

    to.value = ''; subject.value = ''; body.value = '';

    if (originalMsg && mode !== 'new') {
        if (mode === 'reply') to.value = originalMsg.from_email || '';
        if (mode === 'reply_all') to.value = [originalMsg.from_email, originalMsg.to_email].filter(Boolean).join(', ');
        if (mode === 'forward') to.value = '';
        subject.value = (mode === 'forward' ? 'Fwd: ' : 'Re: ') + (originalMsg.subject || '');
        body.value = mode === 'forward'
            ? `\n\n-------- Mensaje reenviado --------\nDe: ${originalMsg.from_email}\nFecha: ${new Date(originalMsg.date).toLocaleString('es-ES')}\nAsunto: ${originalMsg.subject}\n\n${originalMsg.body_text || ''}`
            : `\n\n> ${(originalMsg.body_text || '').split('\n').join('\n> ')}`;
    }

    document.getElementById('modal-compose').style.display = 'flex';
    to.focus();
}

window.replyTo = function(mode) {
    openCompose(mode, S.activeMessage);
};

async function sendEmail() {
    const accountId = parseInt(document.getElementById('compose-from').value);
    const to        = document.getElementById('compose-to').value.trim();
    const cc        = document.getElementById('compose-cc').value.trim();
    const subject   = document.getElementById('compose-subject').value.trim();
    const body      = document.getElementById('compose-body').value;

    if (!to || !subject) { toast('Destinatario y asunto obligatorios', 'error'); return; }

    const btn = document.getElementById('btn-send');
    btn.disabled = true; btn.textContent = 'Enviando…';

    const payload = { account_id: accountId, to, subject, body_text: body };
    if (cc) payload.cc = cc;
    if (_composeContext?.originalMsg?.id) payload.reply_to_message_id = _composeContext.originalMsg.id;

    const r = await api('POST', '/send', payload);
    btn.disabled = false; btn.textContent = '📤 Enviar';

    if (r?.ok) {
        toast('Mensaje enviado', 'success');
        document.getElementById('modal-compose').style.display = 'none';
    } else {
        toast(r?.data?.message || 'Error al enviar', 'error');
    }
}

/* ── Account modal ──────────────────────────────────────────────── */
function openAccountModal(acc = null) {
    S.editingAccountId = acc?.id || null;
    document.getElementById('account-modal-title').textContent = acc ? 'Editar cuenta' : 'Añadir cuenta';
    document.getElementById('acc-name').value       = acc?.name || '';
    document.getElementById('acc-email').value      = acc?.email || '';
    document.getElementById('acc-password').value   = '';
    document.getElementById('acc-imap-host').value  = acc?.imap_host || '';
    document.getElementById('acc-imap-port').value  = acc?.imap_port || 993;
    document.getElementById('acc-imap-ssl').value   = acc?.imap_ssl ? '1' : '0';
    document.getElementById('acc-smtp-host').value  = acc?.smtp_host || '';
    document.getElementById('acc-smtp-port').value  = acc?.smtp_port || 587;
    document.getElementById('acc-smtp-ssl').value   = acc?.smtp_ssl ? '1' : '0';
    document.getElementById('modal-account').style.display = 'flex';
}

async function saveAccount() {
    const body = {
        name:      document.getElementById('acc-name').value.trim(),
        email:     document.getElementById('acc-email').value.trim(),
        password:  document.getElementById('acc-password').value,
        imap_host: document.getElementById('acc-imap-host').value.trim(),
        imap_port: parseInt(document.getElementById('acc-imap-port').value),
        imap_ssl:  document.getElementById('acc-imap-ssl').value === '1',
        smtp_host: document.getElementById('acc-smtp-host').value.trim(),
        smtp_port: parseInt(document.getElementById('acc-smtp-port').value),
        smtp_ssl:  document.getElementById('acc-smtp-ssl').value === '1',
    };
    if (!body.email) { toast('El email es obligatorio', 'error'); return; }

    const btn = document.getElementById('btn-save-account');
    btn.disabled = true; btn.textContent = 'Guardando…';

    const r = S.editingAccountId
        ? await api('PUT', `/accounts/${S.editingAccountId}`, body)
        : await api('POST', '/accounts', body);

    btn.disabled = false; btn.textContent = 'Guardar';

    if (r?.ok) {
        toast(S.editingAccountId ? 'Cuenta actualizada' : 'Cuenta añadida', 'success');
        document.getElementById('modal-account').style.display = 'none';
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
        toast('Todos marcados como leídos', 'success');
    } else {
        toast('Error', 'error');
    }
}

/* ── Event listeners ────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', async () => {
    renderUser();

    // Auth check
    const me = await api('GET', '/auth/me');
    if (!me || !me.ok) { doLogout(); return; }

    if (me.data?.is_admin) {
        const adminBtn = document.getElementById('btn-admin');
        if (adminBtn) adminBtn.style.display = '';
    }

    await loadAccounts();
    await loadMessages();

    // Sidebar buttons
    document.getElementById('btn-sync').addEventListener('click', doSync);
    document.getElementById('btn-compose-sidebar').addEventListener('click', () => openCompose('new'));
    document.getElementById('btn-compose').addEventListener('click', () => openCompose('new'));
    document.getElementById('btn-logout').addEventListener('click', doLogout);
    document.getElementById('btn-add-account').addEventListener('click', () => openAccountModal());
    document.getElementById('btn-mark-read').addEventListener('click', markAllRead);

    // Settings button → re-use account modal for now
    document.getElementById('btn-settings').addEventListener('click', () => {
        if (S.accounts.length) openAccountModal(S.accounts.find(a => a.id === S.selectedAccount) || S.accounts[0]);
        else openAccountModal();
    });

    // Folder filters
    document.querySelectorAll('.folder-item').forEach(el => {
        el.addEventListener('click', () => {
            S.filter = el.dataset.filter;
            S.selectedAccount = null;
            renderAccounts();
            renderFolders();
            closeViewer();
            loadMessages();
        });
    });

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
});
