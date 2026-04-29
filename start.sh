#!/bin/bash
set -e

# Remove conflicting MPM modules at runtime
rm -f /etc/apache2/mods-enabled/mpm_event.load
rm -f /etc/apache2/mods-enabled/mpm_event.conf
rm -f /etc/apache2/mods-enabled/mpm_worker.load
rm -f /etc/apache2/mods-enabled/mpm_worker.conf

# Ensure only prefork is enabled
a2enmod mpm_prefork 2>/dev/null || true

exec apache2-foreground
