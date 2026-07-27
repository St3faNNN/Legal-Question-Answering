# Dev-grade image for the docker-compose demo; Laravel Herd remains the primary
# local development path on Windows. Multi-stage: the build stage compiles the
# PHP grpc extension, installs Composer deps, and generates the PHP gRPC stubs;
# the runtime stage carries only the compiled extension and the built app, so
# the build toolchain (grpc source, protoc, PHPIZE_DEPS) never ships.

# ---------- build stage ----------
FROM php:8.4-cli AS build

# Build toolchain: PHPIZE_DEPS to compile the grpc PECL extension, protoc +
# grpc_php_plugin to generate the PHP stubs, libpq-dev for pdo_pgsql.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libpq-dev zlib1g-dev $PHPIZE_DEPS \
        protobuf-compiler protobuf-compiler-grpc \
    && docker-php-ext-install pdo_pgsql \
    && pecl install grpc \
    && docker-php-ext-enable grpc \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .

# grpc/grpc + google/protobuf back the generated stubs. They are required only
# by the grpc driver, so they are added here (Docker) rather than in the app's
# committed composer.json, keeping local Herd installs unchanged. Dev deps stay:
# config/scribe.php references Scribe classes at config load time. `composer
# require` (after install) resolves and installs them, then the stubs are
# generated and the optimized autoloader is rebuilt so it maps them.
# --no-scripts on the require: its post-update-cmd hook runs `artisan
# boost:update` (a dev tool) which isn't available in the image and would fail
# the build. The final dump-autoload still runs package:discover normally.
RUN composer install --no-interaction \
    && composer require grpc/grpc google/protobuf predis/predis --no-interaction --no-scripts \
    && bash scripts/gen-proto.sh --php \
    && composer dump-autoload --optimize --no-interaction

# ---------- runtime stage ----------
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq5 \
    && rm -rf /var/lib/apt/lists/*

# Carry over the compiled extensions (grpc, pdo_pgsql) and enable them.
COPY --from=build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
RUN docker-php-ext-enable grpc pdo_pgsql

WORKDIR /var/www/html
COPY --from=build /var/www/html /var/www/html

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=60s --retries=5 \
    CMD php -r "exit(strpos(@file_get_contents('http://127.0.0.1:8000/up'), 'html') !== false ? 0 : 1);"

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
