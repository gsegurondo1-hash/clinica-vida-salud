FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

# Render asigna el puerto en la variable PORT (por defecto 10000)
ENV PORT=10000
RUN echo 'Listen ${PORT}' > /etc/apache2/ports.conf
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html
RUN sed -i 's/\r$//' docker/entrypoint.sh && chmod +x docker/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html

EXPOSE 10000
CMD ["/var/www/html/docker/entrypoint.sh"]
