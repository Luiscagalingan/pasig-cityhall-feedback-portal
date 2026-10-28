#!/bin/sh
set -eu

# Use the canonical configuration shipped by the official PHP Apache image.
export APACHE_CONFDIR=/etc/apache2
export APACHE_ENVVARS=/etc/apache2/envvars

# Configure a sendmail-compatible SMTP relay from runtime-only environment
# variables. The SMTP password is read from the environment by msmtp at send
# time and is never written to the configuration file or printed to logs.
if [ -n "${PASIG_SMTP_HOST:-}" ]; then
    smtp_host=$PASIG_SMTP_HOST
    smtp_port=${PASIG_SMTP_PORT:-587}
    smtp_user=${PASIG_SMTP_USER:-}
    smtp_tls_mode=${PASIG_SMTP_TLS_MODE:-starttls}
    mail_from=${PASIG_MAIL_FROM:-}

    case "$smtp_host" in ''|*[!A-Za-z0-9.-]*)
        echo 'Invalid PASIG_SMTP_HOST; SMTP transport is disabled.' >&2
        exit 1
        ;;
    esac
    case "$smtp_port" in ''|*[!0-9]*)
        echo 'Invalid PASIG_SMTP_PORT; SMTP transport is disabled.' >&2
        exit 1
        ;;
    esac
    if [ "$smtp_port" -lt 1 ] || [ "$smtp_port" -gt 65535 ]; then
        echo 'Invalid PASIG_SMTP_PORT; SMTP transport is disabled.' >&2
        exit 1
    fi
    case "$smtp_user" in *[!A-Za-z0-9._@+-]*)
        echo 'Invalid PASIG_SMTP_USER; SMTP transport is disabled.' >&2
        exit 1
        ;;
    esac
    case "$mail_from" in ''|*[!A-Za-z0-9._%+@-]*)
        echo 'Invalid PASIG_MAIL_FROM; SMTP transport is disabled.' >&2
        exit 1
        ;;
    esac
    if [ -z "$smtp_user" ] || [ -z "${PASIG_SMTP_PASSWORD:-}" ]; then
        echo 'PASIG_SMTP_USER and PASIG_SMTP_PASSWORD are required when SMTP is enabled.' >&2
        exit 1
    fi
    case "$smtp_tls_mode" in
        starttls) smtp_starttls=on ;;
        tls) smtp_starttls=off ;;
        *)
            echo 'PASIG_SMTP_TLS_MODE must be starttls or tls.' >&2
            exit 1
            ;;
    esac

    smtp_config=/tmp/pasig-msmtprc
    previous_umask=$(umask)
    umask 077
    {
        printf '%s\n' 'defaults' 'auth on' 'tls on' 'timeout 20'
        printf 'tls_starttls %s\n' "$smtp_starttls"
        printf 'host %s\n' "$smtp_host"
        printf 'port %s\n' "$smtp_port"
        printf 'user %s\n' "$smtp_user"
        printf 'from %s\n' "$mail_from"
        printf '%s\n' 'passwordeval "printenv PASIG_SMTP_PASSWORD"'
    } > "$smtp_config"
    umask "$previous_umask"
    chown www-data:www-data "$smtp_config"
    chmod 600 "$smtp_config"
    printf 'sendmail_path = "/usr/sbin/sendmail -C %s -t -i"\n' "$smtp_config" \
        > /usr/local/etc/php/conf.d/zz-pasig-mail.ini
    echo 'Runtime SMTP transport configured with TLS.'
fi

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

