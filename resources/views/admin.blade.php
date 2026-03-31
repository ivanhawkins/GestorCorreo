@extends('layouts.app')
@section('title', 'Administración — Hawkins Mail')

@section('content')

<!-- AUTH SCREEN (login + register) — visible when not authenticated -->
<div class="login-page" id="auth-screen" style="display:none">
    <div class="login-card" style="max-width:420px">
        <div class="login-logo">
            <div class="login-logo-mark">H</div>
            <div class="login-logo-text">
                Hawkins Mail
                <span>Panel de administración</span>
            </div>
        </div>

        <!-- Tabs login / register -->
        <div class="auth-tabs" id="auth-tabs">
            <button class="auth-tab active" data-tab="login">Iniciar sesión</button>
            <button class="auth-tab" data-tab="register">Registrarse</button>
        </div>

        <!-- LOGIN FORM -->
        <form id="login-form" class="auth-form">
            <div id="login-error" class="login-error" style="display:none"></div>
            <div class="form-group">
                <label for="login-username">Usuario</label>
                <input type="text" id="login-username" autocomplete="username" required placeholder="nombre de usuario">
            </div>
            <div class="form-group">
                <label for="login-password">Contraseña</label>
                <input type="password" id="login-password" autocomplete="current-password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn-login">Entrar</button>
        </form>

        <!-- REGISTER FORM -->
        <form id="register-form" class="auth-form" style="display:none">
            <div id="register-info" class="admin-info" style="display:none"></div>
            <div id="register-error" class="login-error" style="display:none"></div>
            <div class="form-group">
                <label for="reg-username">Usuario</label>
                <input type="text" id="reg-username" autocomplete="username" required placeholder="nombre de usuario">
            </div>
            <div class="form-group">
                <label for="reg-password">Contraseña</label>
                <input type="password" id="reg-password" autocomplete="new-password" required placeholder="mínimo 6 caracteres">
            </div>
            <button type="submit" class="btn-login">Crear cuenta</button>
        </form>
    </div>
</div>

<!-- ADMIN PANEL — visible when authenticated as admin -->
<div class="admin-layout" id="admin-panel" style="display:none">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo-row">
                <div class="sidebar-brand">
                    <div class="sidebar-brand-mark">H</div>
                    <div class="sidebar-brand-name">
                        Hawkins Mail
                        <span class="version">Administración</span>
                    </div>
                </div>
            </div>
            <div class="sidebar-user" id="sidebar-user"></div>
        </div>

        <div class="admin-nav">
            <a class="admin-nav-item active" data-tab="users" href="#">Usuarios</a>
            <a class="admin-nav-item" data-tab="accounts" href="#">Cuentas de correo</a>
            <a class="admin-nav-item" data-tab="ai" href="#">Configuración IA</a>
        </div>

        <div class="sidebar-footer">
            <a href="/" class="btn-logout" style="display:block;text-align:left;text-decoration:none;">&#8592; Volver al correo</a>
            <button class="btn-logout" id="btn-logout" style="margin-top:.4rem">Cerrar sesión</button>
        </div>
    </aside>

    <!-- MAIN -->
    <div class="admin-main">

        <!-- TAB: Usuarios -->
        <div class="admin-tab active" id="tab-users">
            <div class="admin-header">
                <h1>Usuarios</h1>
                <button class="btn-primary" id="btn-show-create-user">Nuevo usuario</button>
            </div>

            <!-- Formulario nuevo usuario -->
            <div class="admin-card" id="create-user-form" style="display:none;margin-bottom:1.5rem">
                <h3 class="admin-card-title">Crear usuario</h3>
                <div class="form-row">
                    <div class="form-group flex-2">
                        <label>Usuario</label>
                        <input type="text" id="new-username" class="form-control" placeholder="nombre de usuario">
                    </div>
                    <div class="form-group flex-2">
                        <label>Contraseña</label>
                        <input type="password" id="new-password" class="form-control" placeholder="mínimo 6 caracteres">
                    </div>
                    <div class="form-group flex-1" style="justify-content:flex-end;display:flex;flex-direction:column">
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;margin-bottom:.5rem">
                            <input type="checkbox" id="new-is-admin"> Admin
                        </label>
                        <button class="btn-primary" id="btn-create-user">Crear</button>
                    </div>
                </div>
                <div id="create-user-error" class="admin-error" style="display:none"></div>
            </div>

            <!-- Lista usuarios activos -->
            <div class="admin-card" style="margin-bottom:1.5rem">
                <h3 class="admin-card-title">Usuarios activos</h3>
                <div id="users-list"><div class="loading-state">Cargando…</div></div>
            </div>

            <!-- Lista usuarios eliminados -->
            <div class="admin-card">
                <h3 class="admin-card-title">Usuarios eliminados</h3>
                <div id="deleted-users-list"><div class="loading-state">Cargando…</div></div>
            </div>
        </div>

        <!-- TAB: Cuentas de correo -->
        <div class="admin-tab" id="tab-accounts" style="display:none">
            <div class="admin-header">
                <h1>Cuentas de correo</h1>
            </div>
            <div class="admin-card">
                <div id="all-accounts-list"><div class="loading-state">Cargando…</div></div>
            </div>
        </div>

        <!-- TAB: IA -->
        <div class="admin-tab" id="tab-ai" style="display:none">
            <div class="admin-header">
                <h1>Configuración IA</h1>
            </div>
            <div class="admin-card" style="max-width:600px">
                <h3 class="admin-card-title">Proveedor de IA</h3>
                <div class="form-group">
                    <label>URL de la API</label>
                    <input type="text" id="ai-url" class="form-control" placeholder="https://api.openai.com/v1">
                </div>
                <div class="form-group">
                    <label>API Key</label>
                    <input type="password" id="ai-key" class="form-control" placeholder="sk-…">
                </div>
                <div class="form-group">
                    <label>Modelo principal</label>
                    <input type="text" id="ai-primary-model" class="form-control" placeholder="gpt-4o">
                </div>
                <div class="form-group">
                    <label>Modelo secundario</label>
                    <input type="text" id="ai-secondary-model" class="form-control" placeholder="gpt-4o-mini">
                </div>
                <div id="ai-error" class="admin-error" style="display:none"></div>
                <div class="modal-footer" style="padding:0;margin-top:1rem;border:none">
                    <button class="btn-secondary" id="btn-test-ai">Probar conexión</button>
                    <button class="btn-primary" id="btn-save-ai">Guardar</button>
                </div>
                <div id="ai-status" style="margin-top:.75rem;font-size:.8rem;color:var(--text-dim)"></div>
            </div>
        </div>

    </div>
</div>

<!-- TOASTS -->
<div id="toast-container" class="toast-container"></div>

@push('styles')
<style>
/* Auth tabs */
.auth-tabs { display:flex; gap:0; margin-bottom:1.25rem; border-radius:var(--radius); overflow:hidden; border:1px solid var(--border); }
.auth-tab {
    flex:1; padding:.55rem; text-align:center; font-size:.82rem; font-weight:500;
    background:var(--surface); color:var(--text-dim); border:none; cursor:pointer;
    font-family:inherit; transition:all .15s;
}
.auth-tab:hover { background:var(--surface-hover); }
.auth-tab.active { background:var(--accent-muted); color:#93b4fd; }
.admin-info {
    background:rgba(59,130,246,.12); border:1px solid rgba(59,130,246,.25);
    color:#93b4fd; border-radius:var(--radius); padding:.5rem .75rem; font-size:.82rem; margin-bottom:.75rem;
}

/* Admin layout */
.admin-layout { display:flex; height:100vh; overflow:hidden; }
.admin-main { flex:1; overflow-y:auto; padding:2rem; background:var(--bg); }
.admin-nav { padding:.75rem; display:flex; flex-direction:column; gap:.15rem; margin-top:.5rem; }
.admin-nav-item {
    padding:.5rem .75rem; border-radius:var(--radius); color:var(--text-dim);
    text-decoration:none; font-size:.85rem; transition:all .15s;
}
.admin-nav-item:hover { background:var(--surface-hover); color:var(--text); }
.admin-nav-item.active { background:var(--accent-muted); color:#93b4fd; font-weight:500; }
.admin-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; }
.admin-header h1 { font-size:1.1rem; font-weight:600; color:var(--text-bright); }
.admin-card {
    background:var(--sidebar-bg); border:1px solid var(--border);
    border-radius:var(--radius-lg); padding:1.25rem;
}
.admin-card-title { font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-dim); margin-bottom:1rem; font-weight:600; }
.admin-error {
    background:var(--danger-muted); border:1px solid rgba(220,38,38,.25);
    color:#fca5a5; border-radius:var(--radius); padding:.5rem .75rem; font-size:.82rem; margin-top:.75rem;
}
.admin-table { width:100%; border-collapse:collapse; }
.admin-table th {
    text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.06em;
    color:var(--text-dim); font-weight:600; padding:.5rem .75rem;
    border-bottom:1px solid var(--border);
}
.admin-table td { padding:.65rem .75rem; border-bottom:1px solid var(--border-light); font-size:.82rem; color:var(--text); }
.admin-table tr:last-child td { border-bottom:none; }
.admin-table tr:hover td { background:var(--surface-hover); }
.badge-admin { background:var(--accent-muted); color:#93b4fd; font-size:.68rem; padding:.1rem .4rem; border-radius:3px; font-weight:600; }
.badge-user  { background:var(--surface); color:var(--text-dim); font-size:.68rem; padding:.1rem .4rem; border-radius:3px; }
.btn-sm {
    padding:.3rem .7rem; border-radius:var(--radius); font-size:.75rem; cursor:pointer;
    font-family:inherit; border:1px solid var(--border); background:var(--surface);
    color:var(--text); transition:all .15s;
}
.btn-sm:hover { background:var(--surface-hover); }
.btn-sm.danger { border-color:rgba(220,38,38,.3); color:#fca5a5; }
.btn-sm.danger:hover { background:var(--danger-muted); }
</style>
@endpush

@push('scripts')
<script>
let token = localStorage.getItem('token');

// ── Toast ──────────────────────────────────────────────────────
function toast(msg, type = 'info') {
    const icons = { success:'✓', error:'✕', info:'ℹ' };
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<span>${icons[type]}</span><span>${msg}</span>`;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

// ── API ────────────────────────────────────────────────────────
async function api(method, path, body = null) {
    const headers = { 'Content-Type':'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    const opts = { method, headers };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(`/api${path}`, opts);
    if (res.status === 401 && token) { localStorage.clear(); token = null; showAuthScreen(); }
    return { ok: res.ok, data: await res.json().catch(() => ({})) };
}

// ── Screen switching ───────────────────────────────────────────
function showAuthScreen() {
    document.getElementById('auth-screen').style.display = '';
    document.getElementById('admin-panel').style.display = 'none';
}

function showAdminPanel(user) {
    document.getElementById('auth-screen').style.display = 'none';
    document.getElementById('admin-panel').style.display = '';
    document.getElementById('sidebar-user').textContent = user.username;
    loadUsers();
}

// ── Auth Tabs ──────────────────────────────────────────────────
document.querySelectorAll('.auth-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        const isLogin = tab.dataset.tab === 'login';
        document.getElementById('login-form').style.display = isLogin ? '' : 'none';
        document.getElementById('register-form').style.display = isLogin ? 'none' : '';
    });
});

// ── Login Form ─────────────────────────────────────────────────
document.getElementById('login-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    const err = document.getElementById('login-error');
    btn.disabled = true; btn.textContent = 'Verificando…'; err.style.display = 'none';

    try {
        const res = await fetch('/api/auth/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                username: document.getElementById('login-username').value.trim(),
                password: document.getElementById('login-password').value
            })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || data.message || 'Credenciales incorrectas');

        token = data.access_token || data.token;
        localStorage.setItem('token', token);
        localStorage.setItem('user', JSON.stringify(data.user));

        if (!data.user?.is_admin) {
            window.location.href = '/';
            return;
        }
        showAdminPanel(data.user);
    } catch (ex) {
        err.textContent = ex.message;
        err.style.display = 'block';
    } finally {
        btn.disabled = false; btn.textContent = 'Entrar';
    }
});

// ── Register Form ──────────────────────────────────────────────
document.getElementById('register-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    const err = document.getElementById('register-error');
    btn.disabled = true; btn.textContent = 'Creando…'; err.style.display = 'none';

    try {
        const res = await fetch('/api/auth/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                username: document.getElementById('reg-username').value.trim(),
                password: document.getElementById('reg-password').value,
                is_admin: true
            })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || data.message || 'Error al registrar');

        token = data.token;
        localStorage.setItem('token', token);
        localStorage.setItem('user', JSON.stringify(data.user));

        toast('Cuenta creada correctamente', 'success');
        showAdminPanel(data.user);
    } catch (ex) {
        err.textContent = ex.message;
        err.style.display = 'block';
    } finally {
        btn.disabled = false; btn.textContent = 'Crear cuenta';
    }
});

// ── Admin Tabs ─────────────────────────────────────────────────
document.querySelectorAll('.admin-nav-item').forEach(el => {
    el.addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('.admin-nav-item').forEach(x => x.classList.remove('active'));
        document.querySelectorAll('.admin-tab').forEach(x => x.style.display = 'none');
        el.classList.add('active');
        const tab = el.dataset.tab;
        document.getElementById(`tab-${tab}`).style.display = '';
        if (tab === 'users')    loadUsers();
        if (tab === 'accounts') loadAllAccounts();
        if (tab === 'ai')       loadAIConfig();
    });
});

// ── Users ──────────────────────────────────────────────────────
async function loadUsers() {
    const [active, deleted] = await Promise.all([
        api('GET', '/users?deleted=false'),
        api('GET', '/users?deleted=true'),
    ]);
    renderUsers(active.data,  'users-list',         false);
    renderUsers(deleted.data, 'deleted-users-list',  true);
}

function renderUsers(users, containerId, isDeleted) {
    const el = document.getElementById(containerId);
    if (!Array.isArray(users) || !users.length) {
        el.innerHTML = '<p style="color:var(--text-dim);font-size:.82rem">Sin usuarios</p>';
        return;
    }
    el.innerHTML = `
        <table class="admin-table">
            <thead><tr>
                <th>ID</th><th>Usuario</th><th>Rol</th><th>Creado</th><th></th>
            </tr></thead>
            <tbody>
            ${users.map(u => `
                <tr>
                    <td style="color:var(--text-dim)">${u.id}</td>
                    <td>${u.username}</td>
                    <td><span class="${u.is_admin ? 'badge-admin' : 'badge-user'}">${u.is_admin ? 'Admin' : 'Usuario'}</span></td>
                    <td style="color:var(--text-dim)">${u.created_at ? new Date(u.created_at).toLocaleDateString('es-ES') : '—'}</td>
                    <td style="display:flex;gap:.4rem">
                        ${isDeleted
                            ? `<button class="btn-sm" onclick="restoreUser(${u.id})">Restaurar</button>
                               <button class="btn-sm danger" onclick="hardDeleteUser(${u.id})">Eliminar def.</button>`
                            : `<button class="btn-sm" onclick="changePassword(${u.id}, '${u.username}')">Contraseña</button>
                               <button class="btn-sm danger" onclick="deleteUser(${u.id})">Eliminar</button>`
                        }
                    </td>
                </tr>`).join('')}
            </tbody>
        </table>`;
}

document.getElementById('btn-show-create-user').addEventListener('click', () => {
    const form = document.getElementById('create-user-form');
    form.style.display = form.style.display === 'none' ? '' : 'none';
});

document.getElementById('btn-create-user').addEventListener('click', async () => {
    const username = document.getElementById('new-username').value.trim();
    const password = document.getElementById('new-password').value;
    const is_admin = document.getElementById('new-is-admin').checked;
    const errEl = document.getElementById('create-user-error');
    errEl.style.display = 'none';

    if (!username || !password) { errEl.textContent = 'Usuario y contraseña obligatorios'; errEl.style.display = ''; return; }

    const r = await api('POST', '/auth/register', { username, password, is_admin });
    if (r.ok) {
        toast('Usuario creado', 'success');
        document.getElementById('new-username').value = '';
        document.getElementById('new-password').value = '';
        document.getElementById('create-user-form').style.display = 'none';
        loadUsers();
    } else {
        errEl.textContent = r.data?.error || r.data?.message || 'Error al crear usuario';
        errEl.style.display = '';
    }
});

window.deleteUser = async function(id) {
    if (!confirm('¿Eliminar usuario?')) return;
    const r = await api('DELETE', `/users/${id}`);
    if (r.ok) { toast('Usuario eliminado', 'success'); loadUsers(); }
    else toast('Error', 'error');
};

window.restoreUser = async function(id) {
    const r = await api('POST', `/users/${id}/restore`);
    if (!r.ok) await api('PATCH', `/users/${id}`, { deleted_at: null });
    toast('Usuario restaurado', 'success'); loadUsers();
};

window.hardDeleteUser = async function(id) {
    if (!confirm('¿Eliminar DEFINITIVAMENTE? No se puede deshacer.')) return;
    const r = await api('DELETE', `/users/${id}?permanent=true`);
    if (r.ok) { toast('Eliminado permanentemente', 'success'); loadUsers(); }
    else toast('Error', 'error');
};

window.changePassword = async function(id, username) {
    const pwd = prompt(`Nueva contraseña para "${username}":`);
    if (!pwd) return;
    if (pwd.length < 6) { toast('Mínimo 6 caracteres', 'error'); return; }
    const r = await api('PUT', `/users/${id}/password`, { password: pwd });
    if (r.ok) toast('Contraseña actualizada', 'success');
    else toast(r.data?.message || 'Error', 'error');
};

// ── All Accounts ───────────────────────────────────────────────
async function loadAllAccounts() {
    const r = await api('GET', '/admin/accounts');
    const el = document.getElementById('all-accounts-list');
    const accounts = Array.isArray(r.data) ? r.data : (r.data?.data || []);
    if (!accounts.length) { el.innerHTML = '<p style="color:var(--text-dim);font-size:.82rem">Sin cuentas</p>'; return; }
    el.innerHTML = `
        <table class="admin-table">
            <thead><tr>
                <th>ID</th><th>Usuario</th><th>Email</th><th>Protocolo</th><th>Estado</th><th></th>
            </tr></thead>
            <tbody>
            ${accounts.map(a => `
                <tr>
                    <td style="color:var(--text-dim)">${a.id}</td>
                    <td>${a.user?.username || a.user_id}</td>
                    <td>${a.email_address}</td>
                    <td style="text-transform:uppercase;font-size:.72rem;color:var(--text-dim)">${a.protocol || 'imap'}</td>
                    <td><span class="${a.is_active ? 'badge-admin' : 'badge-user'}">${a.is_active ? 'Activa' : 'Inactiva'}</span></td>
                    <td><button class="btn-sm danger" onclick="deleteAdminAccount(${a.id})">Eliminar</button></td>
                </tr>`).join('')}
            </tbody>
        </table>`;
}

window.deleteAdminAccount = async function(id) {
    if (!confirm('¿Eliminar esta cuenta de correo?')) return;
    const r = await api('DELETE', `/admin/accounts/${id}`);
    if (r.ok) { toast('Cuenta eliminada', 'success'); loadAllAccounts(); }
    else toast('Error', 'error');
};

// ── AI Config ──────────────────────────────────────────────────
async function loadAIConfig() {
    const r = await api('GET', '/ai-config');
    if (!r.ok) return;
    const c = r.data?.config || r.data || {};
    document.getElementById('ai-url').value            = c.api_url || '';
    document.getElementById('ai-primary-model').value  = c.primary_model || '';
    document.getElementById('ai-secondary-model').value = c.secondary_model || '';
}

document.getElementById('btn-save-ai').addEventListener('click', async () => {
    const body = {
        api_url:         document.getElementById('ai-url').value.trim(),
        api_key:         document.getElementById('ai-key').value.trim() || undefined,
        primary_model:   document.getElementById('ai-primary-model').value.trim(),
        secondary_model: document.getElementById('ai-secondary-model').value.trim(),
    };
    const r = await api('PUT', '/ai-config', body);
    if (r.ok) toast('Configuración guardada', 'success');
    else toast(r.data?.message || 'Error al guardar', 'error');
});

document.getElementById('btn-test-ai').addEventListener('click', async () => {
    const statusEl = document.getElementById('ai-status');
    statusEl.textContent = 'Probando conexión…';
    const r = await api('POST', '/ai/test', {
        api_url: document.getElementById('ai-url').value.trim(),
        api_key: document.getElementById('ai-key').value.trim() || undefined,
        primary_model: document.getElementById('ai-primary-model').value.trim() || undefined,
    });
    statusEl.textContent = r.ok ? '✓ Conexión correcta' : '✕ Error: ' + (r.data?.message || 'sin respuesta');
    statusEl.style.color = r.ok ? '#4ade80' : '#fca5a5';
});

// ── Init ───────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
    if (!token) {
        showAuthScreen();
        return;
    }

    // Validate token
    const me = await api('GET', '/auth/me');
    if (!me.ok) {
        localStorage.clear();
        token = null;
        showAuthScreen();
        return;
    }

    if (!me.data?.is_admin) {
        window.location.href = '/';
        return;
    }

    showAdminPanel(me.data);
});

document.getElementById('btn-logout').addEventListener('click', () => {
    api('POST', '/auth/logout').finally(() => {
        localStorage.clear();
        token = null;
        showAuthScreen();
    });
});
</script>
@endpush
@endsection
