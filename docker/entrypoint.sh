#!/bin/sh
# Hand the writable paths to Apache's user before it starts.
#
# These paths may be backed by a Docker named volume or a host bind mount, so
# their ownership is only known at runtime: a fresh named volume is created as
# root, and a bind-mounted folder is whatever the host had. Apache runs as
# www-data, so fix ownership here (as root) rather than at build time.
set -e

for dir in \
    /var/www/html/storage \
    /var/www/html/admin/uploads
do
    mkdir -p "$dir"
    if ! chown www-data:www-data "$dir" 2>/dev/null; then
        echo "entrypoint: could not chown $dir to www-data; make it writable by uid $(id -u www-data)" >&2
    fi
done

# never hand off an empty command (that would make the container exit 0)
if [ "$#" -eq 0 ]; then
    set -- apache2-foreground
fi

exec docker-php-entrypoint "$@"
