FROM php:8.2-cli-bookworm AS builder
RUN apt-get update && apt-get install -y --no-install-recommends git curl unzip \
    && rm -rf /var/lib/apt/lists/*
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
WORKDIR /src
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction

FROM php:8.2-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql curl \
    && apt-get purge -y --auto-remove libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*
COPY gen-report.php functions.php createdata.php style.css /app/
COPY --from=builder /src/vendor /app/vendor
USER 1000:1000
