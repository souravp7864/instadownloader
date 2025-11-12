FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    wget \
    ffmpeg \
    python3 \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install zip

# Install yt-dlp standalone binary
RUN wget -q https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp
RUN chmod a+rx /usr/local/bin/yt-dlp

# Test yt-dlp
RUN yt-dlp --version

# Configure Apache
RUN a2enmod rewrite
COPY .htaccess /var/www/html/.htaccess

# Copy application files
COPY . /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod 666 /var/www/html/users.json \
    && chmod 666 /var/www/html/error.log \
    && touch /var/www/html/stats.json \
    && chmod 666 /var/www/html/stats.json

# Create download directory
RUN mkdir -p /tmp/insta_reels_bot && chmod 777 /tmp/insta_reels_bot

EXPOSE 80

CMD ["apache2-foreground"]