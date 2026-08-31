# A produção corre PHP 8.4. O contentor corria 8.5, e por isso o que passava aqui podia
# falhar lá -- ou, pior, o contrário.
FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    inotify-tools \
    build-essential \
    pkg-config \
    wget \
    libopencore-amrnb-dev \
    libopencore-amrwb-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    default-libmysqlclient-dev \
    && cd /tmp \
    && wget -q https://ffmpeg.org/releases/ffmpeg-7.1.1.tar.xz \
    && tar -xf ffmpeg-7.1.1.tar.xz \
    && cd ffmpeg-7.1.1 \
    && ./configure --prefix=/usr/local --enable-gpl --enable-version3 --enable-libopencore-amrnb --enable-libopencore-amrwb --disable-debug --disable-doc --disable-static --enable-shared --disable-x86asm \
    && make -j$(nproc) \
    && make install \
    && ldconfig \
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
