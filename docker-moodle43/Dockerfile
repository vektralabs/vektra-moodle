FROM php:8.1-apache

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

# PHP extensions required by Moodle 4.3
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
    mbstring \
    exif

RUN a2enmod rewrite

# Download Moodle 4.3.3 (branch stable403) with checksum verification.
ARG MOODLE_TGZ_URL=https://download.moodle.org/download.php/direct/stable403/moodle-4.3.3.tgz
ARG MOODLE_TGZ_SHA256=c85ac3dbbedb3b6e4ee46797546e1584a3bfa07f5db20aa84ecae5319a171db2
RUN cd /tmp && \
    curl -fsSL -o moodle.tgz "${MOODLE_TGZ_URL}" && \
    echo "${MOODLE_TGZ_SHA256}  moodle.tgz" | sha256sum -c - && \
    tar -xzf moodle.tgz && \
    cp -a moodle/. /var/www/html/ && \
    rm -rf moodle moodle.tgz

# Moodle 4.3 serves directly from /var/www/html (no public/ subdirectory)
# DocumentRoot remains the Apache default.

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
