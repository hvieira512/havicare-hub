FROM php:8.1-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    inotify-tools \
    && docker-php-ext-install -j$(nproc) \
        sockets \
        pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

EXPOSE 8080 9000

CMD ["php", "bin/server-hub.php"]
