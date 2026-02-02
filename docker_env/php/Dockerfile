FROM php:8.5-fpm

# Set environment variables
ENV TZ=UTC

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    git \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql pgsql pdo_pgsql zip \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug

# Set working directory
WORKDIR /var/www/html

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Copy application to the container
COPY . .

# Create folder for upload files and give to it permissions
RUN mkdir -p /uploads \
    && chown -R www-data:www-data /uploads \
    && chmod -R 777 /uploads

# Install PHP dependencies
# RUN composer install

# Expose port 9000 for the application
EXPOSE 9000
CMD ["php-fpm"]
