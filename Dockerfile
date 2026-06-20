FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git unzip libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN php bin/console cache:clear --env=prod || true

CMD php -S 0.0.0.0:${PORT:-10000} -t public