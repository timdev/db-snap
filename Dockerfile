FROM php:8.0-cli-alpine

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache mysql-client gpg bzip2

WORKDIR /srv/db-snap

COPY composer.json composer.lock ./
COPY src/symfony.php /srv/db-snap/bin/db-snap

RUN composer install --no-interaction --no-dev --no-scripts --no-plugins --prefer-dist --no-progress --no-cache

ENTRYPOINT ["/srv/db-snap/bin/db-snap"]


