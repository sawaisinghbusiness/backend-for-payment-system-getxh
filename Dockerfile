FROM php:8.1-apache

# System dependencies
RUN apt-get update && apt-get install -y \
    libssl-dev pkg-config git unzip curl \
    && rm -rf /var/lib/apt/lists/*

# MongoDB PHP extension
RUN pecl install mongodb-1.16.2 \
    && docker-php-ext-enable mongodb

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Fix MPM conflict: disable event, enable prefork only
RUN apt-get update -qq && apt-get install -y apache2 || true \
    && a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork rewrite headers 2>/dev/null || true \
    && rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*

# Apache config
RUN { \
    echo '<VirtualHost *:80>'; \
    echo '    DocumentRoot /var/www/html'; \
    echo '    <Directory /var/www/html>'; \
    echo '        AllowOverride All'; \
    echo '        Require all granted'; \
    echo '    </Directory>'; \
    echo '</VirtualHost>'; \
} > /etc/apache2/sites-available/000-default.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .

RUN rm -f .env backup.sh migrate.sql schema.sql \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]
