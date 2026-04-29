#!/bin/bash
set -e

# Remove conflicting MPM modules at runtime
rm -f /etc/apache2/mods-enabled/mpm_event.load
rm -f /etc/apache2/mods-enabled/mpm_event.conf
rm -f /etc/apache2/mods-enabled/mpm_worker.load
rm -f /etc/apache2/mods-enabled/mpm_worker.conf

# Ensure only prefork is enabled
a2enmod mpm_prefork 2>/dev/null || true

# Railway sets $PORT dynamically — make Apache listen on it
APACHE_PORT=${PORT:-80}
sed -i "s/^Listen 80$/Listen ${APACHE_PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${APACHE_PORT}>/" /etc/apache2/sites-enabled/000-default.conf

exec apache2-foreground
