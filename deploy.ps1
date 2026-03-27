# ============================================================
# Script de despliegue completo para correos.hawkins.es
# Uso: .\deploy.ps1
# Requisito: SSH configurado con clave pública en el servidor
# ============================================================
param(
    [string]$User       = "root",
    [string]$RemoteHost = "correos.hawkins.es",
    [string]$RemotePath = "/var/www/correos",          # raiz del frontend (dist/)
    [string]$BackendPath = "/var/www/correos-backend"  # raiz del backend Laravel
)

$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  DESPLIEGUE GestorCorreo" -ForegroundColor Cyan
Write-Host "  Servidor: $User@$RemoteHost" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# ── 1. BUILD FRONTEND ────────────────────────────────────────
Write-Host "[1/4] Construyendo frontend..." -ForegroundColor Yellow
Push-Location frontend
npm run build
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Build del frontend fallido." -ForegroundColor Red
    Pop-Location; exit 1
}
Pop-Location
Write-Host "      OK" -ForegroundColor Green

# ── 2. SUBIR FRONTEND ───────────────────────────────────────
Write-Host "[2/4] Subiendo frontend a $RemoteHost`:$RemotePath ..." -ForegroundColor Yellow
scp -r frontend/dist/* "${User}@${RemoteHost}:${RemotePath}/"
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: No se pudo subir el frontend." -ForegroundColor Red; exit 1
}
Write-Host "      OK" -ForegroundColor Green

# ── 3. SUBIR BACKEND (sin vendor/) ──────────────────────────
Write-Host "[3/4] Subiendo backend Laravel a $RemoteHost`:$BackendPath ..." -ForegroundColor Yellow

# Subir todo laravel-backend excepto vendor/, storage/logs, .env
# rsync es lo ideal; si no está disponible usamos scp
$hasRsync = Get-Command rsync -ErrorAction SilentlyContinue
if ($hasRsync) {
    rsync -avz --delete `
        --exclude='vendor/' `
        --exclude='.env' `
        --exclude='storage/logs/' `
        --exclude='storage/app/pop3_cache/' `
        laravel-backend/ "${User}@${RemoteHost}:${BackendPath}/"
} else {
    # Fallback con scp (sube todo; vendor/ ya no está en el repo)
    scp -r laravel-backend/* "${User}@${RemoteHost}:${BackendPath}/"
}
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: No se pudo subir el backend." -ForegroundColor Red; exit 1
}
Write-Host "      OK" -ForegroundColor Green

# ── 4. COMANDOS EN EL SERVIDOR ──────────────────────────────
Write-Host "[4/4] Ejecutando comandos post-deploy en el servidor..." -ForegroundColor Yellow

$remoteCommands = @"
set -e
cd $BackendPath

echo '-- Instalando dependencias PHP (composer install)...'
composer install --no-dev --optimize-autoloader --no-interaction

echo '-- Ejecutando migraciones...'
php artisan migrate --force

echo '-- Limpiando y recacheando config...'
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo '-- Ajustando permisos...'
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo '-- Reiniciando servicio...'
systemctl restart gestor-correo 2>/dev/null || php-fpm -t && systemctl reload php-fpm 2>/dev/null || true

echo 'Deploy completado.'
"@

ssh "${User}@${RemoteHost}" $remoteCommands
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Falló algún comando en el servidor." -ForegroundColor Red; exit 1
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  DEPLOY COMPLETADO CON EXITO" -ForegroundColor Green
Write-Host "  https://correos.hawkins.es" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
