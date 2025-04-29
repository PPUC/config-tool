FROM php:8.3-apache

# Install system dependencies
RUN apt update && apt install -y \
    wget \
    make \
    gcc \
    g++ \
    libsqlite3-dev \
    libjpeg-dev \
    libpng-dev \
    libwebp-dev \
    unzip \
    git

# Install latest SQLite (3.49+)
RUN wget https://www.sqlite.org/2025/sqlite-autoconf-3490100.tar.gz && \
    tar xzf sqlite-autoconf-3490100.tar.gz && \
    cd sqlite-autoconf-3490100 && \
    ./configure && make && make install && \
    cd .. && rm -rf sqlite-autoconf-3490100*

# Rebuild PHP extensions
RUN docker-php-ext-configure gd --with-jpeg --with-webp && \
    docker-php-ext-install pdo pdo_sqlite gd \

# Set working directory
WORKDIR /var/www

# Copy application files
COPY web/ web/
COPY vendor/ vendor/
COPY docker/entrypoint.sh /entrypoint.sh

# Set permissions
RUN chmod +x /entrypoint.sh \
 && chown -R www-data:www-data /var/www

# Create symlink: html -> web
RUN ln -sf /var/www/web /var/www/html

# Set working directory inside the container to the webroot
WORKDIR /var/www/html

# Set environment variable to detect Docker
ENV DRUPAL_DOCKER=1

# Set entrypoint
ENTRYPOINT ["/entrypoint.sh"]

EXPOSE 80
