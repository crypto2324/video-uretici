FROM php:8.2-apache

# FFmpeg + GD + gerekli araçlar
RUN apt-get update && apt-get install -y \
        ffmpeg \
        libpng-dev libjpeg-dev libwebp-dev \
        fonts-dejavu-core \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache: /api yolunu index.php'ye yönlendir
RUN a2enmod rewrite
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/allow-override.conf \
    && a2enconf allow-override

# Çalışma dizini
WORKDIR /var/www/html

# Uygulama dosyalarını kopyala (api/ klasörünü)
COPY api/ /var/www/html/

# Vercel 80 portunu bekler
EXPOSE 80
