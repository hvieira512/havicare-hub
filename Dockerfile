FROM php:8.5-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    inotify-tools \
    libjpeg62-turbo-dev \
    libpng-dev \
    default-libmysqlclient-dev \
    libsqlite3-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        sockets \
        pcntl \
        pdo_mysql \
        pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

EXPOSE 8081 9000

CMD ["php", "bin/server-hub.php"]
