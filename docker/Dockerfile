FROM node:20-bookworm-slim AS node-src

FROM php:8.3-apache-bookworm

ENV DEBIAN_FRONTEND=noninteractive

# Systempakete: Bild-/Multimedia-Bibliotheken + FFmpeg/FFprobe (fuer Videoverarbeitung)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
        libicu-dev libzip-dev libssl-dev zlib1g-dev libonig-dev \
        libmagickwand-dev imagemagick \
        ffmpeg \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# PHP-Extensions laut PlayHub-Anforderungen
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd bcmath intl pdo_mysql zip exif opcache pcntl

# Imagick (empfohlen) + Redis-Client
RUN pecl install imagick redis \
    && docker-php-ext-enable imagick redis

# Node 20.x + npm aus dem Offiziellen Image (fuer npm ci / npm run build im Installer)
COPY --from=node-src /usr/local/bin/node /usr/local/bin/node
COPY --from=node-src /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && node -v && npm -v

# Docker CLI + Compose v2 (LiveKit-Runtime-Verwaltung, Docker-Sockel wird gemountet)
ARG DOCKER_GID=942
RUN groupadd -g "${DOCKER_GID}" dockerhost \
    && usermod -a -G dockerhost www-data

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc \
    && chmod a+r /etc/apt/keyrings/docker.asc \
    && echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian bookworm stable" > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends docker-ce-cli docker-compose-plugin \
    && rm -rf /var/lib/apt/lists/*

# yt-dlp (vollstaendige Binary) fuer den CrzSourceResolver (Auto-Update via cron-Container)
RUN curl -fsSL -o /usr/local/bin/yt-dlp https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux \
    && chmod +x /usr/local/bin/yt-dlp \
    && /usr/local/bin/yt-dlp --version

# Apache: Rewrite + SSL aktivieren
RUN a2enmod rewrite ssl headers

# www-data auf UID/GID 1000 mappen, damit Bind-Mount-Dateien dem Host-User (benny) gehoeren
RUN groupmod -g 1000 www-data \
    && usermod -u 1000 -g www-data www-data \
    && mkdir -p /var/run/apache2 /var/lock/apache2 \
    && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/www/html

WORKDIR /var/www/html
