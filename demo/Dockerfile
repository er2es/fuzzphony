# syntax=docker/dockerfile:1
#
# Fuzzphony demo image. The build context is the REPOSITORY ROOT (the demo needs ../src and ../benchmarks):
#   docker build -f demo/Dockerfile --target php .
#
# Targets
#   php  runtime for php-fpm, the queue worker and the init job (immutable: dependencies, compiled assets and the
#        Symfony cache are baked in; no composer, no dev tooling)
#   web  nginx serving the compiled assets and proxying the rest to php-fpm
#   dev  php + composer for docker-compose.dev.yml (source bind-mounted, APP_ENV=dev)
ARG PHP_IMAGE=php:8.4.26-fpm-alpine3.24@sha256:26d7864255a0a4be3dcf9cbfb7a2b01659a1670cb52c839f582ebfd5d9d96bfc
ARG NGINX_IMAGE=nginxinc/nginx-unprivileged:1.30.5-alpine3.24@sha256:4714e0b1b2577eaa1a6131d07c958b67f0eb68e6d0521e90c6e5287db8cf0bc5
ARG COMPOSER_IMAGE=composer:2.10.3@sha256:9715c7f69044da2a212a5fbde29ee7da24e364d426560ae6367b060236f847d7

FROM ${COMPOSER_IMAGE} AS composer

# ---------------------------------------------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS base
RUN set -eux; \
    apk add --no-cache libpq postgresql-client fcgi; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl opcache; \
    apk del .build-deps; \
    rm -f /usr/local/etc/php-fpm.d/*.conf /usr/local/etc/php-fpm.d/*.default; \
    addgroup -g 10001 -S app; \
    adduser -u 10001 -S -G app -h /home/app -s /sbin/nologin app
COPY demo/docker/php/php.ini /usr/local/etc/php/conf.d/10-app.ini
COPY demo/docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/00-app.conf
ENV PHP_FPM_MAX_CHILDREN=16 \
    PHP_FPM_START_SERVERS=4 \
    PHP_FPM_MIN_SPARE_SERVERS=2 \
    PHP_FPM_MAX_SPARE_SERVERS=6 \
    PHP_FPM_MAX_REQUESTS=500 \
    PHP_FPM_SLOWLOG_TIMEOUT=3s

# ---------------------------------------------------------------------------------------------------------------
FROM base AS build
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache git unzip
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 COMPOSER_CACHE_DIR=/tmp/composer-cache
# The path repositories in demo/composer.json (symlink: true) point at ../src/*, so the tree keeps its layout.
WORKDIR /app
COPY src ./src
WORKDIR /app/demo
COPY demo/composer.json demo/composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY demo/ ./
# Placeholders for the build only (not persisted in the image); the real values come from the environment at runtime.
ENV APP_ENV=prod APP_DEBUG=0
RUN --mount=type=cache,target=/tmp/composer-cache \
    export APP_SECRET=build-only DATABASE_URL='postgresql://build:build@localhost/build?serverVersion=18'; \
    set -eux; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    php bin/console importmap:install; \
    php bin/console asset-map:compile; \
    php bin/console cache:warmup; \
    test -f var/cache/prod/App_KernelProdContainer.preload.php; \
    # pre-compressed copies for nginx's gzip_static
    find public/assets -type f \( -name '*.js' -o -name '*.css' -o -name '*.svg' \) -exec gzip -9 -k -n {} +; \
    rm -rf docker Dockerfile docker-compose*.yml README.md .env.example .gitattributes .gitignore; \
    # symfony/runtime insists on the file; the real configuration comes from the environment
    echo 'APP_ENV=prod' > .env

# ---------------------------------------------------------------------------------------------------------------
FROM base AS php
COPY --from=build /app/src /app/src
COPY --from=build /app/demo /app/demo
COPY --from=build --chown=app:app /app/demo/var /app/demo/var
COPY benchmarks/seed.sql /app/benchmarks/seed.sql
COPY demo/docker/php/fpm.ini /usr/local/etc/php/fpm.d/20-preload.ini
COPY --chmod=0755 demo/docker/init.sh /usr/local/bin/demo-init
USER app
WORKDIR /app/demo
EXPOSE 9000
# FPM's own ping handler (no PHP code runs, no database is touched).
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong
CMD ["php-fpm", "--nodaemonize"]

# ---------------------------------------------------------------------------------------------------------------
FROM ${NGINX_IMAGE} AS web
USER root
# the stock entrypoint scripts only rewrite the default vhost / templates, which this image does not use
RUN rm -f /docker-entrypoint.d/*
USER 101
COPY demo/docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY demo/docker/nginx/security-headers.conf demo/docker/nginx/fastcgi-php.conf /etc/nginx/
COPY --from=build /app/demo/public/assets /app/demo/public/assets
HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1

# ---------------------------------------------------------------------------------------------------------------
FROM base AS dev
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache git unzip
COPY demo/docker/php/dev.ini /usr/local/etc/php/conf.d/20-dev.ini
COPY --chmod=0755 demo/docker/init.sh /usr/local/bin/demo-init
# The bind mount (..:/app) hides the app; vendor lives in a named volume that composer fills on first start.
RUN mkdir -p -m 0777 /app/demo/vendor /home/app && chmod 0777 /home/app
ENV APP_ENV=dev APP_DEBUG=1 COMPOSER_HOME=/tmp/composer HOME=/tmp
WORKDIR /app/demo
EXPOSE 9000
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong
CMD ["php-fpm", "--nodaemonize"]
