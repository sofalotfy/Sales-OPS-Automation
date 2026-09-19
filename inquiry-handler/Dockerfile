# AI sales inquiry handler (Laravel 13) — multi-stage image.
# Mirrors the per-service pattern of work-scope-rag/auth-service/dashboard:
# one service, one image, HTTP-only communication within the Compose network.
# No Vite/Node stage — the test console is a single server-rendered Blade page
# with inline CSS (constitution V: smallest version; no asset pipeline needed).

# --- Stage 1: composer dependencies --------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --prefer-dist
COPY . .
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache \
    && composer install --no-dev --no-scripts --no-progress --prefer-dist --optimize-autoloader

# --- Stage 2: runtime ------------------------------------------------------
FROM php:8.4-cli
WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libxml2-dev libonig-dev libpq-dev \
    && docker-php-ext-install -j"$(nproc)" ctype mbstring fileinfo pdo pdo_pgsql xml pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=vendor /app /app
COPY docker-entrypoint.sh /usr/local/bin/inquiry-handler-entrypoint
RUN chmod +x /usr/local/bin/inquiry-handler-entrypoint \
    && mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

ENV APP_ENV=production
EXPOSE 8003

ENTRYPOINT ["inquiry-handler-entrypoint"]
# --no-reload: without it Laravel's ServeCommand silently degrades to a single
# worker even when PHP_CLI_SERVER_WORKERS>1. We need the fork pool so a slow
# web-research triage cannot block health/admin/dashboard traffic.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8003", "--no-reload"]