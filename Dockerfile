FROM php:8.4-apache

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APP_TIMEZONE=Asia/Shanghai

COPY docker/apache-security.conf /etc/apache2/conf-available/inventory-security.conf
RUN a2enconf inventory-security

COPY php/ /var/www/html/
COPY scripts/ /opt/inventory/scripts/

RUN chown -R www-data:www-data /var/www/html \
    && chmod 0755 /opt/inventory/scripts/docker-entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD ["php", "/opt/inventory/scripts/healthcheck.php"]

ENTRYPOINT ["/opt/inventory/scripts/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
