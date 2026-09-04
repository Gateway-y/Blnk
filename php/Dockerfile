FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --ignore-platform-req=ext-redis --ignore-platform-req=ext-pdo_pgsql

FROM php:8.3-cli-bookworm

# Install pg_dump (postgresql-client) and required PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev postgresql-client unzip \
    && docker-php-ext-install pdo_pgsql pgsql pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app
COPY --from=deps /app/vendor /app/vendor

RUN chmod +x /app/bin/blnk && ln -s /app/bin/blnk /usr/local/bin/blnk

CMD ["blnk", "start"]

EXPOSE 5001
