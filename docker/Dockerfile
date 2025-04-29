FROM drupal:11-apache

# Install sqlite3 CLI
RUN apt-get update && apt-get install -y sqlite3

# Copy only what's required to run the config-tool
COPY web/ web/
COPY vendor/ vendor/
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
 && chown -R www-data:www-data /var/www \
 && ln -sf /var/www/web /var/www/html

WORKDIR /var/www/html

ENV DRUPAL_DOCKER=1

ENTRYPOINT ["/entrypoint.sh"]

EXPOSE 80
