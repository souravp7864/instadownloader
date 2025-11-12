FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    wget \
    libzip-dev \
    libcurl4-openssl-dev \
    pkg-config \
    python3 \
    python3-pip \
    python3-venv \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install zip curl

# Install yt-dlp using the official method (more reliable)
RUN wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp
RUN chmod a+rx /usr/local/bin/yt-dlp

# Install yt-dlp dependencies
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    && pip3 install --no-cache-dir websockets

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
    CMD curl -f http://localhost:80/ || exit 1

# Start Apache
CMD ["apache2-foreground"]