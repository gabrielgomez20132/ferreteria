FROM php:8.3-apache-bookworm

# Laravel 13 pide PHP ^8.3. PhpSpreadsheet (listas de precios) necesita gd, zip e intl.
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl \
    libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring bcmath exif intl gd zip opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Listas de precios en Excel pueden pasar los 2M del default de la imagen.
RUN { \
      echo 'upload_max_filesize = 32M'; \
      echo 'post_max_size = 64M'; \
      echo 'max_file_uploads = 20'; \
      echo 'memory_limit = 512M'; \
      echo 'max_execution_time = 120'; \
    } > /usr/local/etc/php/conf.d/zz-uploads.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/html/public>\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN mkdir -p bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/private \
        storage/app/public \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R ug+rwx bootstrap/cache storage

RUN git config --global --add safe.directory /var/www/html

RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
CMD ["/usr/local/bin/entrypoint.sh"]
