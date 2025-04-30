FROM php:8.3-apache

# Set working directory for build context
WORKDIR /usr/src

# Install build dependencies
RUN apt-get update && apt-get install -y \
    wget make gcc g++ \
    libjpeg-dev libpng-dev libwebp-dev zlib1g-dev \
    unzip git apache2-dev libxml2-dev \
    libsqlite3-dev pkg-config libzip-dev

# Build and install latest SQLite (3.49.1)
RUN wget https://www.sqlite.org/2025/sqlite-autoconf-3490100.tar.gz && \
    tar xzf sqlite-autoconf-3490100.tar.gz && \
    cd sqlite-autoconf-3490100 && \
    ./configure --prefix=/usr/local && make && make install && \
    cd .. && rm -rf sqlite-autoconf-3490100*

# Update system SQLite binaries and libraries
RUN cp /usr/local/bin/sqlite3 /usr/bin/sqlite3 && \
    cp /usr/local/lib/libsqlite3.so* /usr/lib/ && \
    ldconfig

# Rebuild PHP with the new SQLite version
RUN docker-php-source extract && \
    cd /usr/src/php && \
    ./buildconf --force && \
    ./configure \
        --with-apxs2 \
        --with-pdo-sqlite=/usr/local \
        --with-sqlite3=/usr/local \
        --with-zlib \
        --with-zip \
        --with-config-file-path=/usr/local/etc/php \
        --with-config-file-scan-dir=/usr/local/etc/php/conf.d && \
    make -j$(nproc) && \
    make install && \
    docker-php-source delete

# Install PHP extensions
RUN docker-php-ext-configure gd --with-jpeg --with-webp && \
    docker-php-ext-install gd

# Note: pdo, pdo_sqlite, zip and zlib are already included in the base PHP image
# so we don't need to explicitly install them

# Enable PHP module in Apache
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

# Copy application code
WORKDIR /var/www
COPY web/ web/
COPY vendor/ vendor/
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh && \
    chown -R www-data:www-data /var/www && \
    ln -sf /var/www/web /var/www/html

WORKDIR /var/www/html
ENV DRUPAL_DOCKER=1

ENTRYPOINT ["/entrypoint.sh"]

EXPOSE 80
