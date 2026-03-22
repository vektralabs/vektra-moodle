FROM php:8.3-apache

# System dependencies for PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    libonig-dev \
    curl \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions required by Moodle
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    gd \
    zip \
    intl \
    opcache \
    mysqli \
    pdo \
    pdo_mysql \
    soap \
    mbstring

RUN a2enmod rewrite

# Download Moodle 5.1 (branch stable501) with checksum verification.
# Update MOODLE_TGZ_SHA256 when upgrading Moodle version.
ARG MOODLE_TGZ_URL=https://download.moodle.org/download.php/direct/stable501/moodle-latest-501.tgz
ARG MOODLE_TGZ_SHA256=bdc98f85c9e0cbf2d55d61e9c581516b79d962b9315623099733695d53a1d25a
RUN cd /tmp && \
    curl -fsSL -o moodle.tgz "${MOODLE_TGZ_URL}" && \
    echo "${MOODLE_TGZ_SHA256}  moodle.tgz" | sha256sum -c - && \
    tar -xzf moodle.tgz && \
    cp -r moodle/* /var/www/html/ && \
    rm -rf moodle moodle.tgz

# Moodle 5.1 serves from public/ subdirectory
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf && \
    printf '<Directory /var/www/html/public>\n    Options Indexes FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/moodle-public.conf && \
    a2enconf moodle-public

# Moodle data directory (outside web root)
RUN mkdir -p /var/moodledata && \
    chown -R www-data:www-data /var/moodledata /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod -R 770 /var/moodledata

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

WORKDIR /var/www/html

EXPOSE 80

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
