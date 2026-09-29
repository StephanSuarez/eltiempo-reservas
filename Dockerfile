FROM composer:2.10.3 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress

FROM php:8.5.11-apache-trixie
RUN docker-php-ext-install pdo_mysql \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && echo 'FallbackResource /index.php' > /etc/apache2/conf-enabled/front-controller.conf
# Una solicitud válida mide menos de 1 KB. Apache rechaza con 413 los cuerpos mayores antes de que
# PHP los decodifique: un JSON de 8 MB ocupaba más de 128 MB por proceso.
RUN echo 'LimitRequestBody 4096' > /etc/apache2/conf-enabled/request-limits.conf
WORKDIR /var/www/html
COPY --from=vendor /app/vendor vendor
COPY . .
