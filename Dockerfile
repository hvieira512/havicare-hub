# PHP 8.4, o mesmo da produção.
FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    build-essential \
    pkg-config \
    # O codificador AMR-NB das mensagens de voz vem no libavcodec-extra, como no hub-prod.
    ffmpeg \
    libavcodec-extra \
    libjpeg62-turbo-dev \
    libpng-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        sockets \
        pcntl \
        pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

EXPOSE 8081 9000

CMD ["php", "bin/server-hub.php"]
