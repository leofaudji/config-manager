# Gunakan image PHP CLI resmi yang ringan
FROM php:8.1-cli-alpine

# Instal dependensi: cron, dan ekstensi PHP yang dibutuhkan untuk cURL, JSON, dan TCP Sockets.
RUN apk add --no-cache dcron \
    && docker-php-ext-install -j$(nproc) curl json sockets

# Create the working directory and set it
# This ensures the directory exists and any subsequent commands run from here.
WORKDIR /usr/src/app

# Buat file log untuk cron dan atur direktori crontab
RUN touch /var/log/cron.log \
    && mkdir -p /etc/crontabs

# Salin file crontab yang sudah kita siapkan ke dalam container
# File ini berisi jadwal untuk menjalankan agent.php setiap menit
COPY health-agent-cron /etc/crontabs/root

# Salin skrip agen ke dalam direktori kerja di dalam container.
COPY agent.php .

# Jalankan cron daemon di foreground sebagai proses utama container.
CMD ["crond", "-f", "-l", "8"]