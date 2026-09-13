FROM php:8.2-apache

ARG COMPOSER_FLAGS="--no-dev"

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libzip-dev default-mysql-client \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" gd pdo_mysql \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY composer.json ./
RUN composer install ${COMPOSER_FLAGS} --no-interaction --no-progress --no-scripts --no-autoloader || true

COPY . .
RUN composer dump-autoload --optimize \
 && mkdir -p storage/uploads \
 && chown -R www-data:www-data storage

EXPOSE 80
