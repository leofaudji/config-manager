# Config Manager

Config Manager adalah aplikasi web sederhana yang dibangun menggunakan PHP Native dan MySQL untuk mengelola file konfigurasi dinamis (misalnya untuk Traefik Proxy). Aplikasi ini menyediakan antarmuka dashboard modern untuk melakukan operasi CRUD (Create, Read, Update, Delete) pada data konfigurasi dan menghasilkan file YAML akhir.

## Fitur Utama

- **Manajemen Konfigurasi Traefik**:
  - Antarmuka CRUD untuk **Routers**, **Services**, dan **Middlewares**.
  - **Pratinjau & Deploy**: Lihat pratinjau file YAML yang akan dihasilkan, lengkap dengan validasi, lalu deploy langsung dari UI.
  - **Riwayat & Perbandingan**: Simpan setiap versi deployment, lihat perbedaannya, dan kembalikan ke versi sebelumnya.
- **Manajemen Host & Kontainer**:
  - Kelola beberapa host Docker (standalone atau Swarm) dari satu tempat.
  - Lihat, mulai, hentikan, dan restart kontainer.
  - **Live Stats**: Pantau penggunaan CPU & Memori kontainer secara real-time.
  - Kelola images, networks, dan volumes dengan mudah, termasuk fitur "prune" untuk membersihkan sumber daya yang tidak terpakai.
- **App Launcher**:
  - Deploy aplikasi dari repositori **Git**, **image yang sudah ada** di host, atau dari **Docker Hub**.
  - **Build On-Host**: Opsi untuk membangun image dari `Dockerfile` langsung di host tujuan.
  - Konfigurasi dinamis untuk port, volume, network, dan sumber daya.
  - Log deployment real-time untuk melacak proses.
- **Manajemen Grup**: Halaman khusus untuk membuat, mengubah, dan menghapus grup untuk mengorganisir router dan service.
- **Fitur Kloning**: Duplikasi Router atau Service yang sudah ada dengan satu klik untuk mempercepat pembuatan konfigurasi serupa.
- **UI Modern & Responsif**: Dibangun dengan Bootstrap 5 untuk pengalaman pengguna yang baik di berbagai perangkat.
- **Validasi Duplikat**: Mencegah pembuatan router atau service dengan nama yang sama.
- **Integritas & Backup Git**:
  - **Generate & Deploy**: Secara otomatis men-deploy konfigurasi Traefik ke repositori Git.
  - **Sync Stacks**: Sinkronkan dan backup semua file `docker-compose.yml` dari stack yang Anda deploy ke repositori Git.
- **Integritas Data**: Menjaga konsistensi data dengan pembaruan dan penghapusan yang aman (cascading updates & protected deletes).
- **Dependensi Minimal**: Hanya memerlukan satu file library eksternal (`Spyc.php`) untuk fungsionalitas YAML.
- **Interaksi AJAX**: Operasi CRUD menggunakan AJAX untuk pemrosesan di latar belakang. Aksi hapus memberikan umpan balik instan, sementara aksi simpan akan kembali ke halaman utama dengan pesan status yang jelas.

## Prasyarat

- Web Server (Apache, Nginx, dll)
- PHP 7.4 atau lebih baru
- MySQL 5.7 atau lebih baru
- Ekstensi PHP: `mysqli`

## Instalasi

1.  **Clone Repositori**
    ```bash
    git clone [URL-repositori-anda] config-manager
    cd config-manager
    ```

2.  **Setup Database**
    Anda bisa memilih salah satu dari dua metode berikut:

    **Metode 1: Menggunakan Skrip PHP (Direkomendasikan)**
    - Salin file `.env.example` menjadi `.env` dan sesuaikan kredensial database di dalamnya.
    - Buka `http://[alamat-web-anda]/setup_db.php` di browser. Skrip ini akan membuat database (jika belum ada), tabel, dan mengisi data awal secara otomatis.
    - **PENTING:** Setelah setup berhasil, hapus file `setup_db.php` dari server Anda untuk keamanan.

    **Metode 2: Manual menggunakan file SQL**
    - Buat database baru di MySQL secara manual dan pastikan kredensialnya cocok dengan yang ada di file `.env` Anda.
    - Impor file `database.sql` (jika tersedia di repositori) untuk membuat tabel dan mengisi data awal.

3.  **Konfigurasi Koneksi**
    - Semua konfigurasi koneksi database sekarang dikelola di dalam file `.env` di direktori root proyek.

4.  **Konfigurasi Path Output (Opsional)**
    - Buka file `.env` yang telah Anda buat.
    - Ubah nilai variabel `YAML_OUTPUT_PATH` untuk menentukan lokasi dan nama file di mana konfigurasi YAML akan disimpan.
    - Ubah nilai variabel `TRAEFIK_API_URL` untuk menunjuk ke alamat API Traefik Anda.

5.  **Jalankan Aplikasi**
    - Arahkan browser Anda ke `http://[alamat-web-anda]/login.php`.
    - Login menggunakan kredensial default:
      - **Username**: `admin`
      - **Password**: `password`

## Konfigurasi Web Server (URL Rewriting)

Aplikasi ini menggunakan URL rewriting untuk membuat URL lebih bersih (misalnya `/login` daripada `/login.php`).

### Apache
Pastikan `mod_rewrite` diaktifkan di server Apache Anda dan Anda mengizinkan `AllowOverride All` untuk direktori proyek agar file `.htaccess` yang disertakan dapat berfungsi. Jika Anda menjalankan aplikasi di dalam subdirektori (misalnya `http://localhost/traefik-manager/`), Anda perlu menyesuaikan `RewriteBase` di dalam file `.htaccess`.

### Nginx
Jika Anda menggunakan Nginx, tambahkan blok `try_files` berikut ke konfigurasi server Anda:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## Keamanan
Aplikasi ini kini diproteksi oleh sistem login. Semua halaman dan aksi memerlukan sesi yang aktif untuk dapat diakses.

Aturan pada file `.htaccess` juga telah ditambahkan untuk mencegah akses langsung ke file-file sensitif yang diawali dengan titik (misalnya, `.env`).
## Manajemen Pengguna dan Peran
Aplikasi ini menyertakan manajemen pengguna dengan dua peran:
- **Admin**: Akses penuh ke semua fitur, termasuk CRUD konfigurasi dan pengguna.
- **Viewer**: Akses hanya-lihat (read-only) ke dashboard.

Fitur manajemen yang tersedia (diakses melalui menu dropdown):
- **Manajemen Pengguna (Admin)**: Tambah, edit, dan hapus pengguna.
- **Ubah Password Sendiri**: Setiap pengguna dapat mengubah password akunnya sendiri.
- **Riwayat Deployment (Admin)**:
    - Melihat riwayat semua file konfigurasi yang telah di-deploy.
    - Mengembalikan (restore) seluruh konfigurasi ke versi yang dipilih.
    - Mengunduh (download) file YAML dari versi tertentu.
    - Membandingkan (diff) dua versi deployment untuk melihat perubahan.
    - **Sistem Draft & Deploy**: Konfigurasi yang digenerate menjadi "draft" dan harus di-"deploy" secara manual untuk menjadi aktif, memberikan lapisan keamanan tambahan.
- **Log Aktivitas Pengguna (Admin)**: Melihat jejak audit dari semua aksi penting yang terjadi di dalam aplikasi.