FROM composer:2.10.3 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress

FROM php:8.5.11-apache-trixie
RUN docker-php-ext-install pdo_mysql \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && echo 'FallbackResource /index.php' > /etc/apache2/conf-enabled/front-controller.conf
WORKDIR /var/www/html
COPY --from=vendor /app/vendor vendor
COPY . .
