# Script de despliegue para correos.hawkins.es
# Uso: .\deploy.ps1 -User "usuario" -Host "correos.hawkins.es" -RemotePath "/var/www/correos"
param(
    [string]$User = "root",
    [string]$RemoteHost = "correos.hawkins.es",
    [string]$RemotePath = "/var/www/correos"   # <-- ajusta si es diferente
)

Write-Host "🏗️  Construyendo frontend..." -ForegroundColor Cyan
Set-Location frontend
npm run build
if ($LASTEXITCODE -ne 0) { Write-Host "❌ Build fallido" -ForegroundColor Red; exit 1 }
Set-Location ..

Write-Host "📤 Subiendo frontend a $User@$RemoteHost`:$RemotePath ..." -ForegroundColor Cyan
scp -r frontend/dist/* "$User@$RemoteHost`:$RemotePath/"
if ($LASTEXITCODE -ne 0) { Write-Host "❌ Error subiendo frontend" -ForegroundColor Red; exit 1 }

Write-Host "✅ Frontend desplegado con éxito en $RemoteHost" -ForegroundColor Green
Write-Host ""
Write-Host "Si el backend también ha cambiado, reinícialo en el servidor:" -ForegroundColor Yellow
Write-Host "  ssh $User@$RemoteHost 'systemctl restart gestor-correo'" -ForegroundColor Gray
