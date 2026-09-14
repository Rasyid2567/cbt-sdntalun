FROM php:8.2-apache

# Install ekstensi PostgreSQL & Apache rewrite module
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Salin konfigurasi PHP kustom (max_multipart_body_parts, max_input_vars, dll)
COPY config/php.ini /usr/local/etc/php/conf.d/custom.ini

# Pastikan folder assets/uploads dibuat dan memiliki izin tulis untuk Apache www-data
RUN mkdir -p /var/www/html/assets/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 777 /var/www/html/assets/uploads
