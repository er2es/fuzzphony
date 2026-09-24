FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev unzip git postgresql-client \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app/demo
