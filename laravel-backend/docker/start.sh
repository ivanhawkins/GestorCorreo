#!/bin/sh

echo "[start.sh] Iniciando GestorCorreo Laravel..."

# Generar APP_KEY si no existe
if [ -z "$APP_KEY" ]; then
    echo "[start.sh] Generando APP_KEY..."
    php artisan key:generate --force
fi

# Esperar a que la base de datos esté disponible (max 60s)
echo "[start.sh] Esperando conexión a la base de datos..."
for i in $(seq 1 30); do
    php artisan db:monitor 2>/dev/null && break || true
    php -r "
        try {
            new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: 3306) . ';dbname=' . getenv('DB_DATABASE'),
                getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_TIMEOUT => 2]);
            exit(0);
        } catch(Exception \$e) { exit(1); }
    " 2>/dev/null && break || true
    echo "[start.sh] BD no disponible, reintentando ($i/30)..."
    sleep 2
done

# Ejecutar migraciones (no fallar si ya existen)
echo "[start.sh] Ejecutando migraciones..."
php artisan migrate --force 2>&1 || echo "[start.sh] ADVERTENCIA: migraciones con errores (puede ser normal si ya existen)"

# Cache de configuración (ignorar errores)
echo "[start.sh] Cacheando configuración..."
php artisan config:cache 2>&1 || true
php artisan route:cache 2>&1 || true

# Ajustar permisos
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

echo "[start.sh] Iniciando PHP-FPM y Nginx..."
exec supervisord -c /etc/supervisord.conf
