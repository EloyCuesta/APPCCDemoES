FROM php:8.3-apache-bookworm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libonig-dev unzip gosu curl \
    && docker-php-ext-install -j2 pdo_pgsql mbstring opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
ENV APP_ENV=prod APP_DEBUG=0
COPY backend/composer.json backend/composer.lock backend/symfony.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress \
    --no-scripts --no-autoloader
COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && a2enmod rewrite headers \
    && mkdir -p var /var/appcc/evidencias /var/appcc/jwt \
    && chown -R www-data:www-data var /var/appcc
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/appcc.ini
COPY --chmod=755 deploy/backend-entrypoint.sh /usr/local/bin/appcc-entrypoint
COPY --chmod=755 deploy/scheduler.sh /usr/local/bin/appcc-scheduler
ENTRYPOINT ["appcc-entrypoint"]
CMD ["apache2-foreground"]
