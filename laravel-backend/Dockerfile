FROM php:8.4-fpm-alpine

# Instalar dependencias del sistema
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    git \
    zip \
    unzip \
    c-client \
    imap-dev \
    krb5-dev \
    openssl-dev \
    oniguruma-dev \
    libzip-dev \
    ca-certificates \
    autoconf \
    gcc \
    g++ \
    make \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mbstring \
        zip \
        opcache \
    && pecl install imap \
    && docker-php-ext-enable imap

# Instalar Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar archivos del proyecto
COPY . .

# Instalar dependencias PHP
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Permisos de storage
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/pop3_cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Configuración Nginx
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Configuración Supervisor
COPY docker/supervisord.conf /etc/supervisord.conf

# Script de arranque
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 8080

# Healthcheck: espera hasta 3 minutos (migraciones pueden tardar)
HEALTHCHECK --interval=15s --timeout=10s --start-period=180s --retries=5 \
    CMD curl -f http://localhost:8080/api/health || exit 1

CMD ["/start.sh"]
