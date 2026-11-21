FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM composer:2 AS composer

FROM php:8.4-apache AS app
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install bcmath pdo_mysql \
    && a2enmod rewrite headers
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/invenpilot.ini
WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --no-autoloader --no-progress
COPY backend/ ./
COPY --from=frontend /app/dist/ ./public/
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache
