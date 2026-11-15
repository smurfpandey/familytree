FROM php:8.5-apache

# Cache-busting stamp for CSS/JS, passed by CI with the commit SHA. A local
# build leaves it empty and PHP uses "dev".
ARG FT_ASSET_VERSION=
ENV FT_ASSET_VERSION=${FT_ASSET_VERSION}

RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libzip-dev libcurl4-openssl-dev libsqlite3-dev libonig-dev \
  && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
  && docker-php-ext-install -j"$(nproc)" pdo_sqlite gd curl mbstring zip \
  && a2enmod rewrite headers \
  && rm -rf /var/lib/apt/lists/*
COPY public_html/ /var/www/html/
RUN mkdir -p /var/www/html/admin/uploads /var/www/html/storage \
  && chown -R www-data:www-data /var/www/html
# storage/ (SQLite, app.key, caches) and admin/uploads may be volumes or bind
# mounts; the entrypoint fixes their ownership at runtime, before Apache drops
# to www-data
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
# ENTRYPOINT in a child image resets the base image's CMD, so restate it
CMD ["apache2-foreground"]
EXPOSE 80