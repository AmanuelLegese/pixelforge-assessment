FROM php:8.4-cli-alpine

RUN apk add --no-cache git unzip postgresql-dev icu-dev libzip-dev nodejs npm \
    && docker-php-ext-install pdo_pgsql pcntl intl zip bcmath \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
