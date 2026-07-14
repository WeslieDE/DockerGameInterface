# SGI — Simple Game Interface
# Single minimal container: PHP 8.3 + Apache. No external PHP dependencies.
FROM php:8.3-apache

# Enable URL rewriting (.htaccess: /api/* -> public/api/index.php).
RUN a2enmod rewrite

# The ext-curl extension used for the Docker socket is bundled in the official
# PHP image; nothing to install.

# Serve from public/ only; src/ stays outside the document root.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides in the document root.
RUN printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
    > /etc/apache2/conf-available/sgi.conf \
    && a2enconf sgi

# Application code.
COPY public/ /var/www/html/public/
COPY src/    /var/www/html/src/
COPY composer.json /var/www/html/composer.json

# Entrypoint grants www-data access to the mounted Docker socket at runtime.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
