# syntax=docker/dockerfile:1
#
# Imagens de produção do Clinic (ver docs/deploy.md):
#   --target app  PHP-FPM com o código, dependências sem as de desenvolvimento e assets compilados
#   --target web  nginx com a pasta public/ e o HTTPS
# O docker-compose.production.yml constrói as duas.

ARG PHP_VERSION=8.3
ARG NODE_VERSION=22
ARG NGINX_VERSION=1.27

# ---------------------------------------------------------------------------
# PHP com as extensões usadas pelo sistema: pdo_mysql, gd (fotos em JPEG, PNG
# e WebP), exif (orientação da foto), intl e opcache.
FROM php:${PHP_VERSION}-fpm-bookworm AS php-base

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev libicu-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype; \
    docker-php-ext-install -j"$(nproc)" gd exif intl pdo_mysql opcache; \
    apt-get purge -y --auto-remove libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev libicu-dev; \
    apt-get install -y --no-install-recommends libjpeg62-turbo libpng16-16 libwebp7 libfreetype6 libicu72; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Dependências PHP, sem as de desenvolvimento.
FROM php-base AS vendor

RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---------------------------------------------------------------------------
# CSS e JavaScript. O Tailwind lê as views do código e as da paginação do
# Laravel (em vendor), por isso esta etapa parte do código já com vendor.
FROM node:${NODE_VERSION}-bookworm-slim AS assets

WORKDIR /var/www/html
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /var/www/html ./
RUN npm run build

# ---------------------------------------------------------------------------
FROM php-base AS app

COPY docker/app/php.ini "$PHP_INI_DIR/conf.d/zz-clinic.ini"
COPY docker/app/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-clinic.conf
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=vendor --chown=root:root /var/www/html ./
COPY --from=assets --chown=root:root /var/www/html/public/build ./public/build
COPY --chmod=755 docker/app/entrypoint.sh /usr/local/bin/clinic-entrypoint

# O código fica somente leitura para o PHP. Gravação só em storage, em
# bootstrap/cache e na pasta das fotos (volume).
RUN set -eux; \
    mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /var/clinic/patient-files; \
    chown -R www-data:www-data storage bootstrap/cache /var/clinic/patient-files; \
    chmod 700 /var/clinic/patient-files

# Padrões da imagem; o .env (env_file do docker-compose) prevalece.
ENV APP_ENV=production \
    APP_NAME=Clinic \
    APP_LOCALE=pt_BR \
    APP_FALLBACK_LOCALE=en \
    DB_CONNECTION=mysql \
    LOG_CHANNEL=stack \
    LOG_STACK=daily \
    PATIENT_PHOTOS_PATH=/var/clinic/patient-files

USER www-data

ENTRYPOINT ["clinic-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
FROM nginx:${NGINX_VERSION}-alpine AS web

COPY docker/nginx/clinic.conf.template /etc/nginx/templates/default.conf.template
COPY --from=app /var/www/html/public /var/www/html/public
