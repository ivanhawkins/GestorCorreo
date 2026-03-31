@extends('layouts.app')
@section('title', 'Acceso — Hawkins Mail')

@section('content')
<div class="login-page">
    <div class="login-card">
        <div class="login-logo">
            <div class="login-logo-mark">H</div>
            <div class="login-logo-text">
                Hawkins Mail
                <span>Gestor de correo corporativo</span>
            </div>
        </div>

        <p class="login-heading">Iniciar sesión</p>
        <p class="login-sub">Introduce tus credenciales para acceder</p>

        <!-- Tabs login / register -->
        <div class="auth-tabs" id="auth-tabs" style="display:flex; gap:0; margin-bottom:1.25rem; border-radius:var(--radius); overflow:hidden; border:1px solid var(--border);">
            <button class="auth-tab active" data-tab="login" style="flex:1; padding:.55rem; text-align:center; font-size:.82rem; font-weight:500; background:var(--accent-muted); color:#93b4fd; border:none; cursor:pointer;" onclick="switchTab('login')">Iniciar sesión</button>
            <button class="auth-tab" data-tab="register" style="flex:1; padding:.55rem; text-align:center; font-size:.82rem; font-weight:500; background:var(--surface); color:var(--text-dim); border:none; cursor:pointer;" onclick="switchTab('register')">Registrarse</button>
        </div>

        <form id="login-form">
            <div id="login-error" class="login-error" style="display:none"></div>
            <div class="form-group">
                <label for="username">Usuario</label>
                <input type="text" id="username" name="username"
                       autocomplete="username" required placeholder="nombre de usuario">
            </div>
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn-login" id="btn-login">Entrar</button>
        </form>

        <form id="register-form" style="display:none">
            <div id="register-info" class="admin-info" style="display:none"></div>
            <div id="register-error" class="login-error" style="display:none"></div>
            <div class="form-group">
                <label for="reg-username">Nuevo usuario</label>
                <input type="text" id="reg-username" name="reg-username"
                       autocomplete="username" required placeholder="nombre de usuario">
            </div>
            <div class="form-group">
                <label for="reg-password">Contraseña</label>
                <input type="password" id="reg-password" name="reg-password"
                       autocomplete="new-password" required placeholder="mínimo 6 caracteres">
            </div>
            <button type="submit" class="btn-login" id="btn-register">Crear cuenta</button>
        </form>
    </div>
</div>

@push('scripts')
<script>
if (localStorage.getItem('token')) window.location.href = '/';

function switchTab(tab) {
    const isLogin = tab === 'login';
    document.getElementById('login-form').style.display = isLogin ? 'block' : 'none';
    document.getElementById('register-form').style.display = isLogin ? 'none' : 'block';

    const tabs = document.querySelectorAll('.auth-tab');
    tabs[0].style.background = isLogin ? 'var(--accent-muted)' : 'var(--surface)';
    tabs[0].style.color = isLogin ? '#93b4fd' : 'var(--text-dim)';
    
    tabs[1].style.background = !isLogin ? 'var(--accent-muted)' : 'var(--surface)';
    tabs[1].style.color = !isLogin ? '#93b4fd' : 'var(--text-dim)';
}

document.getElementById('login-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-login');
    const err = document.getElementById('login-error');
    btn.disabled = true;
    btn.textContent = 'Verificando…';
    err.style.display = 'none';

    try {
        const res = await fetch('/api/auth/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                username: document.getElementById('username').value.trim(),
                password: document.getElementById('password').value
            })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message || data.error || 'Credenciales incorrectas');
        localStorage.setItem('token', data.access_token || data.token);
        localStorage.setItem('user', JSON.stringify(data.user));
        const redirect = sessionStorage.getItem('redirect_after_login') || '/';
        sessionStorage.removeItem('redirect_after_login');
        window.location.href = redirect;
    } catch (ex) {
        err.textContent = ex.message;
        err.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Entrar';
    }
});

document.getElementById('register-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-register');
    const err = document.getElementById('register-error');
    btn.disabled = true;
    btn.textContent = 'Creando…';
    err.style.display = 'none';

    try {
        const res = await fetch('/api/auth/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                username: document.getElementById('reg-username').value.trim(),
                password: document.getElementById('reg-password').value,
                is_admin: false
            })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message || data.error || 'Error al registrarse');
        localStorage.setItem('token', data.access_token || data.token);
        localStorage.setItem('user', JSON.stringify(data.user));
        window.location.href = '/';
    } catch (ex) {
        err.textContent = ex.message;
        err.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Crear cuenta';
    }
});
</script>
@endpush
@endsection
