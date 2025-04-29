FROM php:8.3-apache

# Set working directory for build context
WORKDIR /usr/src

# Install build dependencies
RUN apt-get update && apt-get install -y \
    wget make gcc g++ \
    libjpeg-dev libpng-dev libwebp-dev \
    unzip git apache2-dev \
    libsqlite3-dev pkg-config

# Build and install latest SQLite (3.49.1)
RUN wget https://www.sqlite.org/2025/sqlite-autoconf-3490100.tar.gz && \
    tar xzf sqlite-autoconf-3490100.tar.gz && \
    cd sqlite-autoconf-3490100 && \
    ./configure --prefix=/usr/local && make && make install && \
    cd .. && rm -rf sqlite-autoconf-3490100*

# Replace SQLite
RUN cp /usr/local/bin/sqlite3 /usr/bin/sqlite3 && \
    cp /usr/local/lib/libsqlite3.so* /usr/lib/ && \
    ldconfig

# Rebuild PHP with the new SQLite version
RUN docker-php-source extract && \
    cd /usr/src/php/ext/pdo_sqlite && \
    phpize && \
    ./configure --with-pdo-sqlite=/usr/local && \
    make && make install && \
    docker-php-ext-enable pdo_sqlite && \
    docker-php-source delete

# Install PHP extensions with correct SQLite version
RUN docker-php-ext-configure gd --with-jpeg --with-webp && \
    docker-php-ext-install pdo gd

# Rebuild Apache PHP module
RUN a2dismod php8.3 && \
    docker-php-source extract && \
    cd /usr/src/php && \
    ./buildconf --force && \
    ./configure --with-apxs2 --with-pdo-mysql --with-pdo-sqlite=/usr/local --with-sqlite3=/usr/local && \
    make && \
    make install && \
    a2enmod php8.3 && \
    docker-php-source delete

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
