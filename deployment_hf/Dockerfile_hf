FROM php:8.2-apache

# Install extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql && docker-php-ext-enable mysqli

# Enable mod_rewrite
RUN a2enmod rewrite

# Update permissions (optional but good)
RUN chown -R www-data:www-data /var/www/html
