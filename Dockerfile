FROM php:8.1-apache

# System dependencies for MongoDB extension
RUN apt-get update && apt-get install -y \
    libssl-dev \
    pkg-config \
    git \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# MongoDB PHP extension (pinned version = stable build)
RUN pecl install mongodb-1.16.2 \
    && docker-php-ext-enable mongodb

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Enable Apache modules
RUN a2enmod rewrite headers

# Apache VirtualHost with AllowOverride All
RUN { \
    echo '<VirtualHost *:80>'; \
    echo '    DocumentRoot /var/www/html'; \
    echo '    <Directory /var/www/html>'; \
    echo '        AllowOverride All'; \
    echo '        Require all granted'; \
    echo '    </Directory>'; \
    echo '    ErrorLog ${APACHE_LOG_DIR}/error.log'; \
    echo '    CustomLog ${APACHE_LOG_DIR}/access.log combined'; \
    echo '</VirtualHost>'; \
} > /etc/apache2/sites-available/000-default.conf

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Copy and install dependencies first (Docker layer cache)
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy all project files
COPY . .

# Remove sensitive files from image
RUN rm -f .env backup.sh migrate.sql schema.sql

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
