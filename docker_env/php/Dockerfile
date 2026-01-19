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

# Copy the Laravel application to the container
COPY . .

# Install PHP dependencies
# RUN composer install

# Expose port 9000 for the application
EXPOSE 9000
CMD ["php-fpm"]
