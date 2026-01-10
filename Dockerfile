FROM php:8.2-apache

# Install extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql && docker-php-ext-enable mysqli

# Enable mod_rewrite
RUN a2enmod rewrite

# Change Apache port to 7860 for Hugging Face
RUN sed -i 's/80/7860/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

# Update permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 7860
