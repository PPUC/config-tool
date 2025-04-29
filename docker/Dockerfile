FROM drupal:11-apache

# Set working directory to /var/www (not /var/www/html!)
WORKDIR /var/www

# Copy only what's required to run the app
COPY web/ html/
COPY vendor/ vendor/
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
 && chown -R www-data:www-data /var/www

ENV DRUPAL_DOCKER=1

ENTRYPOINT ["/entrypoint.sh"]
EXPOSE 80
