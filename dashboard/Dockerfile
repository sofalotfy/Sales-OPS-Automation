# Admin dashboard (Laravel 13) — multi-stage image.
# Mirrors the per-service pattern of work-scope-rag/auth-service: one service,
# one image, HTTP-only communication within the Compose network.

# --- Stage 1: frontend assets (Vite + Tailwind) ---------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# --- Stage 2: composer dependencies --------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --prefer-dist
COPY . .
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache \
    && composer install --no-dev --no-scripts --no-progress --prefer-dist --optimize-autoloader

# --- Stage 3: runtime ------------------------------------------------------
FROM php:8.4-cli
WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libxml2-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" ctype mbstring fileinfo pdo xml pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker-entrypoint.sh /usr/local/bin/dashboard-entrypoint
RUN chmod +x /usr/local/bin/dashboard-entrypoint \
    && mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

ENV APP_ENV=production
EXPOSE 8002

ENTRYPOINT ["dashboard-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8002"]