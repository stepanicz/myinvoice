# MyInvoice.cz — multi-stage Docker build
#
# Stage 1: build Vue frontend (web/dist)
# Stage 2: install PHP dependencies (api/vendor)
# Stage 3: runtime image (PHP 8.5 + Apache)
#
# Build:    docker compose build       (or cmd/docker-build.{sh,ps1})
# First run: cmd/docker-install.{sh,ps1}
# Daily:    docker compose up -d / down

# ---------- Stage 1: frontend ----------
FROM node:20-alpine AS web-build
WORKDIR /app
COPY web/package.json web/pnpm-lock.yaml ./
RUN corepack enable && corepack prepare pnpm@latest --activate \
 && pnpm install --frozen-lockfile
COPY web/ ./
RUN pnpm build

# ---------- Stage 2: composer ----------
# The composer image has only a minimal PHP without pdo_mysql/gd/intl extensions,
# but the runtime stage installs them — so skip platform checks here.
FROM composer:2 AS php-deps
WORKDIR /app
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
        --ignore-platform-reqs
COPY api/ ./
RUN composer dump-autoload --optimize --classmap-authoritative

# ---------- Stage 2b: supercronic (CUSTOM stepanicz) ----------
# Lightweight Docker-friendly cron daemon. Used by sidecar `cron` service in
# docker-compose.yml. Downloaded in alpine to avoid touching runtime apt-get.
FROM alpine:3.20 AS cron-deps
ARG TARGETARCH
ARG SUPERCRONIC_VERSION=v0.2.33
RUN apk add --no-cache curl ca-certificates \
 && curl -fsSLo /usr/local/bin/supercronic \
    "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${TARGETARCH:-amd64}" \
 && chmod +x /usr/local/bin/supercronic

# ---------- Stage 3: runtime ----------
FROM php:8.5-apache AS runtime

# Use mlocati/docker-php-extension-installer — the de-facto installer for PHP-Docker
# extensions. Handles apt deps, parallel builds, PECL packages and the
# install-modules race condition that bites raw `docker-php-ext-install` on PHP 8.5.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        pdo_mysql gd mbstring intl zip opcache exif bcmath redis \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
        tini \
        default-mysql-client \
 && a2enmod rewrite headers deflate expires \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*
# default-mysql-client (CUSTOM stepanicz): provides mariadb-dump used by cron-backup.php

# PHP runtime config
RUN { \
        echo 'memory_limit = 256M'; \
        echo 'upload_max_filesize = 20M'; \
        echo 'post_max_size = 25M'; \
        echo 'max_execution_time = 60'; \
        echo 'date.timezone = Europe/Prague'; \
        echo 'expose_php = Off'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 128'; \
        echo 'opcache.max_accelerated_files = 20000'; \
        echo 'opcache.validate_timestamps = 0'; \
    } > /usr/local/etc/php/conf.d/myinvoice.ini

# Apache: doc root → /var/www/html, allow .htaccess
RUN sed -ri \
        -e 's!/var/www/html!/var/www/html!g' \
        -e 's!AllowOverride None!AllowOverride All!g' \
        /etc/apache2/apache2.conf /etc/apache2/sites-available/000-default.conf

# CUSTOM (stepanicz): supercronic for in-container cron sidecar service
COPY --from=cron-deps /usr/local/bin/supercronic /usr/local/bin/supercronic

# Copy application code
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=web-build --chown=www-data:www-data /app/dist ./web/dist
COPY --from=php-deps  --chown=www-data:www-data /app/vendor ./api/vendor

# Writable dirs (cfg.php, log/, storage/, private/ are bind-mounted from host)
RUN mkdir -p log storage private && chown -R www-data:www-data log storage private

EXPOSE 80
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["apache2-foreground"]
