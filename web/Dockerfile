# =====================================================================
#  Drupal 11 (php-fpm) — drupal/web
#  Docroot proyek ada di subfolder: /var/www/html/web
#  (hasil `composer create-project drupal/recommended-project .`)
#
#  Build :  docker compose build
#  Base  :  php:8.3-fpm-bookworm  (Drupal 11 butuh PHP >= 8.3)
#  User  :  www-data di-remap ke uid/gid 1000 (= user `www` di host)
#           supaya file di bind mount tidak jadi milik root.
# =====================================================================
FROM php:8.3-fpm-bookworm

# --- paket sistem + client mariadb (untuk drush/dump dari container) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
      git \
      unzip \
      patch \
      curl \
      rsync \
      default-mysql-client \
      libpng-dev \
      libjpeg62-turbo-dev \
      libwebp-dev \
      libfreetype6-dev \
      libzip-dev \
      libicu-dev \
      libonig-dev \
      libyaml-dev \
    && rm -rf /var/lib/apt/lists/*

# --- ekstensi PHP untuk Drupal 11 ---
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        pdo_mysql \
        mysqli \
        zip \
        intl \
        opcache \
        exif \
        bcmath

# --- ekstensi tambahan dari PECL (cache Redis & parsing YAML) ---
RUN pecl install yaml redis \
    && docker-php-ext-enable yaml redis \
    && rm -rf /tmp/pear

# --- Composer (diambil dari image resmi) ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- selaraskan www-data dengan uid/gid 1000 (user `www` di host) ---
RUN groupmod -o -g 1000 www-data \
    && usermod -o -u 1000 -g 1000 -d /var/www www-data

WORKDIR /var/www/html

RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000
