FROM php:8.3-apache

WORKDIR /usr/src

# Install and configure everything in one layer
RUN apt-get update && apt-get install -y \
    wget make gcc g++ \
    libjpeg-dev libpng-dev libwebp-dev zlib1g-dev \
    unzip git apache2-dev libxml2-dev \
    libsqlite3-dev pkg-config libzip-dev \
    libcurl4-openssl-dev ca-certificates && \
    # Build and install SQLite
    wget https://www.sqlite.org/2025/sqlite-autoconf-3490100.tar.gz && \
    tar xzf sqlite-autoconf-3490100.tar.gz && \
    cd sqlite-autoconf-3490100 && \
    ./configure --prefix=/usr/local && \
    make -j$(nproc) && \
    make install && \
    cd .. && \
    rm -rf sqlite-autoconf-3490100* && \
    # Update system SQLite
    cp /usr/local/bin/sqlite3 /usr/bin/sqlite3 && \
    cp /usr/local/lib/libsqlite3.so* /usr/lib/ && \
    ldconfig && \
    # Rebuild PHP
    docker-php-source extract && \
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
    docker-php-source delete && \
    # Install PHP extensions
    docker-php-ext-configure gd --with-jpeg --with-webp && \
    docker-php-ext-install gd curl && \
    # Clean up
    apt-get purge -y --auto-remove wget make gcc g++ && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* && \
    # PHP config
    cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini && \
    update-ca-certificates && \
    echo "allow_url_fopen = On" >> /usr/local/etc/php/conf.d/drupal.ini && \
    echo "openssl.cafile = /etc/ssl/certs/ca-certificates.crt" >> /usr/local/etc/php/conf.d/drupal.ini && \
    # Apache config
    a2enmod rewrite && \
    echo "DocumentRoot /var/www/web" > /etc/apache2/conf-available/document-root.conf && \
    echo "<Directory /var/www/web>" >> /etc/apache2/conf-available/document-root.conf && \
    echo "    Options Indexes FollowSymLinks" >> /etc/apache2/conf-available/document-root.conf && \
    echo "    AllowOverride All" >> /etc/apache2/conf-available/document-root.conf && \
    echo "    Require all granted" >> /etc/apache2/conf-available/document-root.conf && \
    echo "</Directory>" >> /etc/apache2/conf-available/document-root.conf && \
    a2enconf document-root

WORKDIR /var/www
COPY --chown=www-data:www-data web/ web/
COPY --chown=www-data:www-data vendor/ vendor/
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

WORKDIR /var/www/web
ENV DRUPAL_DOCKER=1

ENTRYPOINT ["/entrypoint.sh"]
EXPOSE 80
