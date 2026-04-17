FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        fonts-dejavu-core \
        git \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql intl gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
