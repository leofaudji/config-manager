# Gunakan image resmi PHP 8.1 dengan server Apache sebagai dasar.
FROM php:8.1-apache

# Set direktori kerja di dalam container.
WORKDIR /var/www/html

# 1. Instal dependensi sistem yang dibutuhkan.
# - git: Untuk fitur kloning repositori.
# - unzip, libzip-dev: Untuk ekstensi PHP zip.
# - libicu-dev: Untuk ekstensi PHP intl (good practice).
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# 2. Instal ekstensi PHP yang dibutuhkan oleh aplikasi.
# - mysqli: Untuk koneksi ke database MySQL.
# - intl: Untuk fungsionalitas internasionalisasi.
# - zip: Untuk fitur yang mungkin memerlukan ZipArchive.
RUN docker-php-ext-install mysqli intl zip

# 3. Instal Docker CLI dan Docker Compose V2 di dalam container.
# Ini penting agar fitur "App Launcher" dapat menjalankan perintah `docker-compose`
# untuk men-deploy aplikasi ke host standalone.
RUN curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/debian \
    $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt-get update \
    && apt-get install -y docker-ce-cli
RUN curl -L "https://github.com/docker/compose/releases/download/v2.23.3/docker-compose-$(uname -s)-$(uname-m)" -o /usr/local/bin/docker-compose \
    && chmod +x /usr/local/bin/docker-compose

# 4. Konfigurasi Apache.
# Aktifkan mod_rewrite untuk URL yang bersih (digunakan oleh Router.php).
RUN a2enmod rewrite

# 5. Salin semua source code aplikasi ke dalam container.
COPY . .

# 6. Atur kepemilikan file agar Apache (user www-data) dapat menulis ke direktori yang dibutuhkan.
# Ini penting untuk generate file YAML dan menyimpan compose file dari App Launcher.
RUN mkdir -p /var/www/html/compose-files /var/www/html/traefik-configs \
    && chown -R www-data:www-data /var/www/html

# Port 80 sudah diekspos oleh base image, dan Apache akan dimulai secara otomatis.