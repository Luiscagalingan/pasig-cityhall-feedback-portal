#!/bin/sh
set -eu

# Use the canonical configuration shipped by the official PHP Apache image.
export APACHE_CONFDIR=/etc/apache2
export APACHE_ENVVARS=/etc/apache2/envvars

echo '=== Apache runtime configuration before MPM normalization ==='
apache2 -V || true
apache2ctl -M || true
find /etc/apache2/mods-enabled -maxdepth 1 -type l -printf '%f -> %l\n'
grep -R "LoadModule mpm_" /etc/apache2 /usr/local/etc 2>/dev/null || true

# mod_php is non-thread-safe in this image and must run with prefork only.
rm -f \
    /etc/apache2/mods-enabled/mpm_event.conf \
    /etc/apache2/mods-enabled/mpm_event.load \
    /etc/apache2/mods-enabled/mpm_worker.conf \
    /etc/apache2/mods-enabled/mpm_worker.load
a2enmod mpm_prefork >/dev/null

echo '=== Final Apache runtime configuration ==='
apache2ctl -M
mpm_count="$(apache2ctl -M 2>/dev/null | awk '/mpm_.*_module/ { count++ } END { print count + 0 }')"
if [ "$mpm_count" -ne 1 ]; then
    echo "Expected exactly one Apache MPM; found $mpm_count." >&2
    exit 1
fi
apache2ctl configtest

# Retain the official image's argument handling and foreground Apache startup.
exec docker-php-entrypoint "$@"

