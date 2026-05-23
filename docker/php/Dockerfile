# syntax=docker/dockerfile:1

FROM php:8.5-fpm AS development
WORKDIR /var/www/html

COPY ./docker/php/php.ini /usr/local/etc/php/php.ini

RUN apt-get update && apt-get install -y zip unzip libpq-dev supervisor && \
    rm -rf /var/lib/apt/lists/*
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pcntl redis xdebug sockets

COPY ./docker/php/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

ARG UID=1000
ARG GID=1000

RUN usermod -u ${UID} -s /bin/bash www-data && groupmod -g ${GID} www-data

RUN chown www-data:www-data /var/www

RUN mkdir -p /var/log/supervisor /var/run/supervisor && \
    chown -R www-data:www-data /var/log/supervisor /var/run/supervisor /etc/supervisor

USER www-data

COPY --chown=www-data:www-data ./composer.json ./
COPY --chown=www-data:www-data ./composer.lock ./

COPY --chown=www-data:www-data --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN composer install --no-scripts --no-autoloader

COPY --chown=www-data:www-data . .

RUN composer dump-autoload

COPY --chown=www-data:www-data package.json ./
