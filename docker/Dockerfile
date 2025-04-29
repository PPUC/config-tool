FROM php:8.3-apache

# Set working directory for build context
WORKDIR /usr/src

# Install build dependencies
RUN apt-get update && apt-get install -y \
    wget make gcc g++ \
    libjpeg-dev libpng-dev libwebp-dev \
    unzip git

# Build and install latest SQLite (3.49.1)
RUN wget https://www.sqlite.org/2025/sqlite-autoconf-3490100.tar.gz && \
    tar xzf sqlite-autoconf-3490100.tar.gz && \
    cd sqlite-autoconf-3490100 && \
    ./configure --prefix=/usr/local && make && make install && \
    cd .. && rm -rf sqlite-autoconf-3490100*

# Make sure PHP uses the correct SQLite headers and libs
ENV CPPFLAGS="-I/usr/local/include"
ENV LDFLAGS="-L/usr/local/lib"
ENV LD_LIBRARY_PATH="/usr/local/lib"

# Rebuild PHP with the new SQLite version
RUN docker-php-source extract && \
    cd /usr/src/php/ext/pdo_sqlite && \
    phpize && \
    ./configure --with-pdo-sqlite=/usr/local && \
    make && make install && \
    docker-php-ext-enable pdo_sqlite && \
    docker-php-source delete

# Configure and install other extensions
RUN docker-php-ext-configure gd --with-jpeg --with-webp && \
    docker-php-ext-install pdo gd

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
