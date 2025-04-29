FROM php:8.3-apache

# Install system dependencies
RUN apt update && apt install -y \
    sqlite3 \
    libsqlite3-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_sqlite

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
