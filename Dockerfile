# Use official PHP with Apache
FROM php:8.2-apache

# Install system dependencies, sqlite3, libzip, and python3 for outage analyzer
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    libzip-dev \
    zip \
    unzip \
    python3 \
    python3-pip \
    && docker-php-ext-install pdo pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html/

# Install python dependencies for outage analyzer
RUN if [ -f "requirements-outage.txt" ]; then pip3 install --no-cache-dir -r requirements-outage.txt --break-system-packages; fi

# Set appropriate permissions for SQLite database and web server
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 777 /var/www/html \
    && chmod 666 /var/www/html/welcome.sqlite

# Configure Apache Port for Render ($PORT)
ENV PORT=80
EXPOSE 80

# Configure custom apache port binding script to listen to Render's $PORT
RUN echo '#!/bin/bash\n\
sed -i "s/80/${PORT:-80}/g" /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf\n\
exec apache2-foreground' > /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

CMD ["/usr/local/bin/entrypoint.sh"]
