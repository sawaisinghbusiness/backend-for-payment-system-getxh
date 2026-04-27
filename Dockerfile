FROM php:8.1-apache

# System deps for MongoDB extension
RUN apt-get update && apt-get install -y \
    libssl-dev pkg-config git unzip \
 && rm -rf /var/lib/apt/lists/*

# MongoDB PHP extension
RUN pecl install mongodb && docker-php-ext-enable mongodb

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache modules
RUN a2enmod rewrite headers
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copy project files
COPY . /var/www/html/

# Install PHP dependencies
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader

# Remove sensitive files
RUN rm -f /var/www/html/.env \
           /var/www/html/backup.sh \
           /var/www/html/migrate.sql \
           /var/www/html/schema.sql

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
