<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Hawkins Mail')</title>
    <link rel="stylesheet" href="/css/mail.css">
    <script>
        const theme = localStorage.getItem('theme') || 'dark';
        document.documentElement.setAttribute('data-theme', theme);
    </script>
    @stack('styles')
</head>
<body>
    <!-- Theme Toggle Floating Button (visible everywhere) -->
    <button onclick="toggleTheme()" class="btn-theme-floating" title="Cambiar tema (claro/oscuro)" style="position:fixed; bottom:1.25rem; left:1.25rem; width:40px; height:40px; border-radius:50%; background:var(--surface); border:1px solid var(--border); color:var(--text); cursor:pointer; z-index:100; box-shadow:0 4px 12px rgba(0,0,0,.15); display:flex; align-items:center; justify-content:center; font-size:1.2rem; transition:all .15s;">
        <span id="theme-icon">◐</span>
    </button>

    @yield('content')
    
    <script>
        function updateThemeIcon() {
            const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
            document.getElementById('theme-icon').textContent = isDark ? '☀' : '☾';
        }
        
        window.toggleTheme = function() {
            const current = document.documentElement.getAttribute('data-theme') || 'dark';
            const next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
            updateThemeIcon();
        };
        
        updateThemeIcon();
    </script>
    @stack('scripts')
</body>
</html>
