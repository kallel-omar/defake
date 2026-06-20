FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git unzip libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

ENV APP_ENV=prod

RUN composer install --no-dev --optimize-autoloader --no-scripts

RUN php bin/console cache:clear --env=prod || true



CMD php bin/console doctrine:migrations:migrate --no-interaction --env=prod; php bin/console messenger:setup-transports --env=prod; php -S 0.0.0.0:${PORT:-10000} -t public