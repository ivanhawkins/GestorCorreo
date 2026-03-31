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
    </div>
</div>

@push('scripts')
<script>
if (localStorage.getItem('token')) window.location.href = '/';

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
        if (!res.ok) throw new Error(data.message || 'Credenciales incorrectas');
        localStorage.setItem('token', data.access_token || data.token);
        localStorage.setItem('user', JSON.stringify(data.user));
        window.location.href = '/';
    } catch (ex) {
        err.textContent = ex.message;
        err.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Entrar';
    }
});
</script>
@endpush
@endsection
