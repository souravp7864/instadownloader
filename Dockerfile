FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libzip-dev \
    libcurl4-openssl-dev \
    pkg-config \
    python3 \
    python3-pip \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install zip curl

# Install yt-dlp
RUN pip3 install yt-dlp

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure Apache
RUN a2enmod rewrite headers
COPY .htaccess /var/www/html/.htaccess

# Copy application files
COPY . /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod 666 /var/www/html/users.json \
    && chmod 666 /var/www/html/error.log \
    && touch /var/www/html/stats.json \
    && chmod 666 /var/www/html/stats.json

# Create download directory with proper permissions
RUN mkdir -p /tmp/insta_reels_bot && chmod 777 /tmp/insta_reels_bot

# Expose port
EXPOSE 8080

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/ || exit 1

# Start Apache
CMD ["apache2-foreground"]