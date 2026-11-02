# Defaults build Moodle 5.1 exactly as before. Other versions pass build args, see
# compose.moodle43.yml and compose.moodle52.yml (plan assess-moodle-ws, group A).
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-apache

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

# Download Moodle (default 5.1, branch stable501) with checksum verification.
# Update MOODLE_TGZ_SHA256 when upgrading Moodle version.
ARG MOODLE_TGZ_URL=https://download.moodle.org/download.php/direct/stable501/moodle-latest-501.tgz
ARG MOODLE_TGZ_SHA256=7e432c40f22a64f8b3c40e3310bdb80ae4d080cdb219b958c9204b128344f37e
RUN cd /tmp && \
    curl -fsSL -o moodle.tgz "${MOODLE_TGZ_URL}" && \
    echo "${MOODLE_TGZ_SHA256}  moodle.tgz" | sha256sum -c - && \
    tar -xzf moodle.tgz && \
    cp -a moodle/. /var/www/html/ && \
    rm -rf moodle moodle.tgz

# Web document root: 5.x serves from public/, 4.x from the Moodle root. CLI scripts
# (admin/cli/*) are at the Moodle root in every version.
ARG MOODLE_DOCROOT=/var/www/html/public
ENV MOODLE_DOCROOT=${MOODLE_DOCROOT}
RUN if [ "${MOODLE_DOCROOT}" != "/var/www/html" ]; then \
        sed -i "s|DocumentRoot /var/www/html|DocumentRoot ${MOODLE_DOCROOT}|g" /etc/apache2/sites-available/000-default.conf; \
    fi && \
    printf '<Directory %s>\n    Options Indexes FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n' "${MOODLE_DOCROOT}" > /etc/apache2/conf-available/moodle-public.conf && \
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
