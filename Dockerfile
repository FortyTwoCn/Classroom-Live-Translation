FROM node:24-bookworm-slim AS browser
WORKDIR /build/frontend
RUN npm install -g pnpm@10
COPY frontend/package.json frontend/pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
COPY frontend/ ./
RUN mkdir -p ../public && pnpm build

FROM php:8.5-fpm-bookworm AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libonig-dev libzip-dev ffmpeg unzip ca-certificates && docker-php-ext-install pdo_pgsql mbstring zip sockets pcntl && pecl install redis && docker-php-ext-enable redis && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/classroom
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --classmap-authoritative
COPY . .
COPY deploy/php.ini /usr/local/etc/php/conf.d/classroom.ini
COPY --from=browser /build/public/ ./public/
RUN mkdir -p storage/app/recordings storage/framework/views storage/framework/sessions storage/logs bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache && composer dump-autoload --no-dev --classmap-authoritative
USER www-data
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web
COPY --from=runtime /var/www/classroom/public/ /var/www/classroom/public/
COPY deploy/nginx.conf /etc/nginx/conf.d/default.conf
