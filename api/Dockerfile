# Laravel API on PHP-FPM. Build from the repository root (the schema file
# lives outside api/):  docker build -f api/Dockerfile .
FROM composer:2 AS vendor
WORKDIR /app
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY api/ ./
RUN composer dump-autoload --optimize --no-dev

FROM php:8.3-fpm-alpine
RUN apk add --no-cache libpq \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS postgresql-dev \
 && docker-php-ext-install pdo_pgsql opcache \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del .build
# Keep compiled PHP in memory. Without this every request recompiles the
# framework. The code in the image never changes, so files are not re-checked.
RUN printf 'opcache.enable=1\nopcache.validate_timestamps=0\nopcache.memory_consumption=192\nopcache.max_accelerated_files=20000\n' \
    > /usr/local/etc/php/conf.d/opcache.ini
# Forum uploads: images up to 5 MB and attachments up to 10 MB.
RUN printf 'upload_max_filesize=12M\npost_max_size=13M\n' > /usr/local/etc/php/conf.d/uploads.ini
WORKDIR /var/www/html
COPY --from=vendor /app/ ./
# The single migration loads ../db/schema.sql relative to the app.
COPY db/ /var/www/db/
# storage/app/forum is a volume in production. Creating it here, owned by
# www-data, is what makes a new volume start out writable by the app.
RUN php artisan package:discover --ansi \
 && mkdir -p storage/app/forum \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
# Cache the configuration with the real environment, then serve.
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && exec php-fpm"]
