# syntax=docker/dockerfile:1.7

ARG PHP_IMAGE=php:8.4.15-fpm-bookworm
ARG COMPOSER_IMAGE=composer:2.9.5
ARG REDIS_PECL_VERSION=6.3.0

FROM ${PHP_IMAGE} AS php-base
ARG REDIS_PECL_VERSION

RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ${PHPIZE_DEPS} \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    ; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    ; \
    pecl install "redis-${REDIS_PECL_VERSION}"; \
    docker-php-ext-enable redis; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual ${savedAptMark} > /dev/null; \
    apt-mark manual libicu72 libpq5 libzip4 libssl3 zlib1g > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /tmp/pear ~/.pearrc /var/lib/apt/lists/*

COPY infrastructure/docker/api/php/production.ini /usr/local/etc/php/conf.d/99-utcp-production.ini

WORKDIR /var/www/html

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM php-base AS vendor-prod
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

FROM php-base AS vendor-test
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

FROM php-base AS app-prod
ARG BUILD_VERSION=0.1.0-dev
ARG BUILD_COMMIT=unknown
ARG BUILD_CREATED=unknown
ARG IMAGE_SOURCE=unknown

LABEL org.opencontainers.image.title="Unified Telephony Control Plane API" \
      org.opencontainers.image.description="Laravel API image for the Unified Telephony Control Plane" \
      org.opencontainers.image.version="${BUILD_VERSION}" \
      org.opencontainers.image.revision="${BUILD_COMMIT}" \
      org.opencontainers.image.created="${BUILD_CREATED}" \
      org.opencontainers.image.source="${IMAGE_SOURCE}"

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    UTCP_SERVICE_NAME=utcp-api \
    UTCP_APP_VERSION="${BUILD_VERSION}" \
    UTCP_BUILD_COMMIT="${BUILD_COMMIT}" \
    UTCP_BUILD_TIMESTAMP="${BUILD_CREATED}"

COPY apps/api ./
COPY --from=vendor-prod /var/www/html/vendor ./vendor
COPY infrastructure/docker/api/entrypoint /usr/local/bin/utcp-api-entrypoint

RUN set -eux; \
    rm -f .env; \
    rm -f bootstrap/cache/*.php; \
    mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R u=rwX,g=rwX,o= storage bootstrap/cache; \
    chmod +x /usr/local/bin/utcp-api-entrypoint

USER www-data
EXPOSE 9000
ENTRYPOINT ["utcp-api-entrypoint"]
CMD ["api"]

FROM app-prod AS test
USER root
COPY --from=vendor-test /var/www/html/vendor ./vendor
RUN touch .phpunit.result.cache \
    && chown -R www-data:www-data vendor .phpunit.result.cache
USER www-data
CMD ["php", "artisan", "test"]
