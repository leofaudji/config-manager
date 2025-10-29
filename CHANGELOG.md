# Changelog

Semua perubahan penting pada proyek ini akan didokumentasikan di file ini.

Format file ini didasarkan pada [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), dan proyek ini mengikuti [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.2.0] - 2025-10-29

### Added
- **Manajemen Helper Agent**:
  - Halaman "Host Details" kini memiliki antarmuka khusus untuk mengelola **Helper Agents** (Health Agent, Falco, Falcosidekick).
  - Pengguna dapat men-deploy, me-redeploy, me-restart, dan menghapus setiap agen langsung dari UI.
  - Status agen (misalnya, `Running`, `Stopped`, `Not Deployed`) dan waktu laporan terakhir dari Health Agent ditampilkan secara real-time.
  - Log deployment untuk agen kini ditampilkan secara *streaming* di dalam modal untuk pemantauan yang lebih baik.
- **Autoscaler Layanan Aplikasi**:
  - Cron job baru (`autoscaler.php`) untuk melakukan penskalaan otomatis pada *Application Stacks*.
  - **Penskalaan Vertikal**: Untuk host Standalone, autoscaler akan menyesuaikan **batas CPU** kontainer naik atau turun berdasarkan utilisasi CPU host.
  - **Penskalaan Horizontal**: Untuk host Swarm, autoscaler akan menambah atau mengurangi **jumlah replika** layanan.
  - Ambang batas CPU untuk scale-up dan scale-down dapat dikonfigurasi per-stack.
- **Peningkatan Webhook & Deployment Otomatis**:
  - Webhook kini mendukung event `ping` dari GitHub/Gitea untuk memvalidasi koneksi saat penyiapan awal.
  - Proses deployment yang dipicu oleh webhook kini berjalan sepenuhnya di latar belakang, memberikan respons cepat ke Git provider dan mencegah *timeout*.
  - Menambahkan kebijakan update via webhook (`realtime` atau `scheduled`), memungkinkan beberapa pembaruan stack dijadwalkan untuk di-deploy nanti.
- **Halaman Bantuan & Dokumentasi Internal**:
  - Menambahkan halaman **Pertanyaan Umum (FAQ)** dengan fitur pencarian untuk membantu pengguna memahami fungsionalitas aplikasi.
  - Menambahkan beberapa halaman **diagram alur kerja** (menggunakan Mermaid.js) untuk memvisualisasikan proses seperti Manajemen Traefik, Deployment Health Agent, dan Keamanan Falco.
  - Menambahkan parser Markdown untuk menampilkan `CHANGELOG.md` ini secara dinamis di dalam aplikasi.

### Changed
- **Logika Health Check**: Logika di balik `Health Agent` kini didokumentasikan dengan jelas di dalam modal pada halaman "Host Details", menjelaskan pendekatan berlapis (Docker Healthcheck -> Konektivitas Port -> Ping).
- **Penanganan Status Host**: Logika untuk mendeteksi host yang *down* (tidak melapor) telah dipindahkan ke dalam cron job `autoscaler.php` untuk memastikan deteksi yang andal dan terpusat.
- **Formulir Router & Service**: Formulir kini mendukung input CIDR (misalnya, `192.168.1.0/24`) untuk menambahkan rentang server secara massal ke sebuah service, yang akan diekspansi secara otomatis menjadi daftar IP individual.

### Fixed
- **Penghapusan Service**: Memperbaiki logika di mana service tidak dapat dihapus jika masih terhubung ke sebuah router. Kini, sistem akan memberikan pesan error yang jelas kepada pengguna.
- **Pemicu Deployment**: Logika pemicu deployment (baik otomatis maupun manual) telah disempurnakan untuk menargetkan grup konfigurasi yang benar, bukan memicu deployment global setiap saat.
- **Stabilitas Webhook**: Proses webhook kini lebih tangguh, dengan kemampuan untuk menangani berbagai format URL repositori (SSH, HTTPS, dan URL kloning) dari payload Git.
- **Validasi Input**: Memperkuat validasi di berbagai endpoint API (Router, Service, User) untuk mencegah data yang tidak valid dan memberikan pesan error yang lebih spesifik.

### Security
- **Validasi Token Webhook**: Memperkuat keamanan endpoint webhook dengan validasi token yang ketat menggunakan `hash_equals` untuk mencegah serangan *timing attack*.
- **Pencegahan Hapus Diri Sendiri**: Pengguna tidak dapat lagi menghapus akun mereka sendiri atau menghapus akun admin terakhir, mencegah kondisi *lock-out*.

## [3.1.0] - 2025-10-20
### Added
- **Laporan Insiden & Analisis (RCA)**:
  - Fitur utama baru untuk melacak insiden secara otomatis saat host *down* atau kontainer *unhealthy*.
  - Halaman detail insiden kini mencakup template **Post-Mortem / Root Cause Analysis (RCA)** yang komprehensif, termasuk *Executive Summary*, *Root Cause*, *Lessons Learned*, dan *Action Items*.
  - Menambahkan **Tingkat Keparahan (Severity)** dan **Pemilik (Assignee)** untuk setiap insiden, lengkap dengan filter di halaman daftar.
  - Notifikasi di header dan sidebar untuk insiden yang sedang terbuka (`Open` atau `Investigating`).
  - Kemampuan untuk mencetak laporan daftar insiden atau detail insiden tunggal ke dalam format **PDF** dengan format yang informatif.
- **Integrasi SLA & Insiden**:
  - Setiap peristiwa downtime di Laporan SLA kini secara otomatis ditautkan ke laporan insiden yang relevan, mempercepat analisis akar masalah.
  - Menambahkan **Periode Pengecualian (Maintenance Window)** yang dapat dikonfigurasi di "Settings", di mana downtime yang terjadi selama periode ini tidak akan dihitung sebagai penalti SLA.
- **Backup & Restore**:
  - Halaman baru "Backup & Restore" untuk membuat backup penuh konfigurasi aplikasi dalam format JSON dan me-restore-nya.
  - Menambahkan fitur **Backup Otomatis** yang dapat dijadwalkan melalui halaman "Cron Job Management", lengkap dengan pengaturan path dan retensi di "General Settings".
- **Peningkatan Sistem & UI**:
  - Menambahkan **System Logs Viewer** sebagai tab baru di "Log Viewer" untuk memisahkan log yang dihasilkan sistem.
  - Menambahkan opsi di "General Settings" untuk mengatur **interval refresh notifikasi** di header.
  - Menambahkan fitur **auto-refresh** pada halaman "Log Viewer".
  - Menambahkan **penyimpanan filter dan paginasi ke `localStorage`** di halaman "Incident Reports" untuk menjaga state saat navigasi.

### Changed
- **Optimisasi Performa**:
  - Panggilan API untuk statistik dashboard dan status Git kini hanya dieksekusi saat berada di halaman Dashboard, mengurangi beban saat navigasi.
  - Variabel `autoRefreshInterval` di berbagai halaman telah diganti dengan variabel global untuk menghemat sumber daya browser.
- **Standardisasi Zona Waktu**: Mengatur zona waktu default aplikasi (PHP & MySQL) ke **GMT+7 (Asia/Jakarta)** untuk memastikan konsistensi data waktu di seluruh sistem.
- **Peningkatan Format Laporan**: Mendesain ulang format PDF untuk daftar dan detail insiden agar lebih informatif dan mudah dibaca.
- **Header Notifikasi**: Mengatur ulang urutan ikon notifikasi di header untuk memprioritaskan notifikasi yang paling kritis.

### Fixed
- **Perhitungan SLA**: Memperbaiki bug dalam logika perhitungan SLA yang menyebabkan persentase tidak akurat, terutama untuk laporan harian.
- **Status Kesehatan**: Memperbaiki bug di mana status kontainer bisa terjebak dalam keadaan "Unknown" secara permanen.
- **Navigasi & Alur Kerja**: Memperbaiki berbagai bug kecil terkait alur kerja, seperti tombol "View Incident" yang tidak berfungsi, proses penyimpanan detail insiden, dan validasi endpoint API.

## [3.0.0] - 2025-10-15

### Added

- **App Launcher**: Fitur utama baru untuk men-deploy aplikasi dari berbagai sumber.
    - Mendukung deployment dari repositori Git, image yang sudah ada di host, dan Docker Hub (lengkap dengan fitur pencarian).
    - Menampilkan log deployment secara *real-time* di dalam modal.
    - Memungkinkan konfigurasi dinamis untuk port, volume (termasuk multiple volume), network (dengan saran IP), dan sumber daya (CPU/Memori).
    - Secara otomatis mengatur `container_name` dan `hostname` untuk identifikasi yang lebih baik pada host standalone.
    - Secara otomatis menambahkan `restart_policy` untuk meningkatkan keandalan layanan.
- **Build from Dockerfile**: Menambahkan opsi pada App Launcher (sumber Git) untuk membangun image Docker langsung di host tujuan menggunakan `Dockerfile` yang ada di repositori.
- **Live Container Stats**: Menambahkan tombol "Live Stats" pada setiap kontainer yang berjalan, menampilkan grafik penggunaan CPU dan Memori secara *real-time* di dalam modal, dengan *refresh rate* yang dapat diatur (5, 30, 60 detik).
- **Stack & Image Tracking**:
    - Halaman "Application Stacks" kini menampilkan kolom "Source" yang informatif (Git, Host Image, Docker Hub, dll.).
    - Halaman "Host Images" kini menampilkan kolom "Used By" untuk menunjukkan stack mana yang menggunakan image tersebut.
- **Git Integration Enhancements**:
    - **Sync Stacks to Git**: Fitur baru untuk mem-backup semua file `docker-compose.yml` dari stack yang dikelola ke repositori Git terpusat.
    - **Connection Test**: Menambahkan tombol "Test Connection" di halaman "Settings" untuk memvalidasi URL repositori Git (HTTPS dan SSH) sebelum disimpan.
- **Validasi Real-time**:
  - App Launcher kini memvalidasi duplikasi nama stack secara *real-time* saat pengguna mengetik.
  - Menambahkan validasi di sisi server untuk mencegah error deployment akibat duplikasi nama kontainer.
- **Laporan Insiden & Analisis (RCA)**:
  - Fitur utama baru untuk melacak insiden secara otomatis saat host *down* atau kontainer *unhealthy*.
  - Halaman detail insiden kini mencakup template **Post-Mortem / Root Cause Analysis (RCA)** yang komprehensif.
  - Menambahkan **Tingkat Keparahan (Severity)** dan **Pemilik (Assignee)** untuk setiap insiden.
  - Kemampuan untuk mencetak laporan insiden ke dalam format **PDF**.
- **Integrasi SLA & Insiden**:
  - Setiap peristiwa downtime di Laporan SLA kini secara otomatis ditautkan ke laporan insiden yang relevan.
  - Menambahkan **Periode Pengecualian (Maintenance Window)** yang dapat dikonfigurasi di "Settings".
- **Backup & Restore**:
  - Halaman baru "Backup & Restore" untuk membuat backup penuh konfigurasi aplikasi dalam format JSON dan me-restore-nya.
  - Menambahkan fitur **Backup Otomatis** yang dapat dijadwalkan melalui halaman "Cron Job Management".
- **Peningkatan Sistem & UI**:
  - Menambahkan **System Logs Viewer** sebagai tab baru di "Log Viewer".
  - Menambahkan fitur **auto-refresh** pada halaman "Log Viewer".
  - Menambahkan **penyimpanan filter dan paginasi ke `localStorage`** di halaman "Incident Reports" untuk menjaga state saat navigasi.

### Changed
- **UI Refinements**: Tombol "Preview Config" dipindahkan dari header utama ke halaman "Routers" untuk alur kerja yang lebih kontekstual.
- **App Updater**: Halaman "Update Application" telah didesain ulang sepenuhnya agar konsisten dengan fungsionalitas dan UI App Launcher yang baru.
- **Resource Limits**: Batasan CPU dan Memori yang diisi di form App Launcher kini akan selalu menimpa (replace) nilai yang ada di file `docker-compose.yml` asli.
- **Optimisasi Performa**: Panggilan API untuk statistik dashboard dan status Git kini hanya dieksekusi saat berada di halaman Dashboard.
- **Standardisasi Zona Waktu**: Mengatur zona waktu default aplikasi (PHP & MySQL) ke **GMT+7 (Asia/Jakarta)**.

### Fixed
- **Perhitungan SLA**: Memperbaiki bug dalam logika perhitungan SLA yang menyebabkan persentase tidak akurat.
- **Status Kesehatan**: Memperbaiki bug di mana status kontainer bisa terjebak dalam keadaan "Unknown" secara permanen.

### Removed
- **Import YAML**: Fitur "Import YAML" dihapus dari header utama untuk menyederhanakan antarmuka.

## [2.0.0] - 2025-10-10

### Added
- Fungsionalitas CRUD penuh untuk **Services** dan **Servers**.
- Validasi di sisi server untuk mencegah duplikasi nama pada **Routers** dan **Services**.
- Tampilan UI yang lebih baik untuk blok Service di halaman utama.
- **Manajemen Konfigurasi & Grup**:
  - Halaman khusus untuk operasi CRUD (Create, Read, Update, Delete) pada Grup.
  - Form interaktif gabungan untuk membuat Router dan Service (baru atau yang sudah ada) dalam satu alur kerja.
  - Menambahkan tombol "Clone" untuk Router dan Service, memungkinkan duplikasi konfigurasi yang ada dengan cepat.
  - Menambahkan kemampuan untuk memilih beberapa router dan memindahkannya ke grup lain secara massal.
  - Menambahkan opsi untuk memilih metode load balancer (seperti `leastConn`, `ipHash`, dll.) saat membuat atau mengedit Service.
  - Menambahkan dukungan untuk konfigurasi TLS (`certResolver`) pada router.
- **Riwayat & Deployment**:
  - Mengimplementasikan sistem "Draft & Deploy". Konfigurasi yang digenerate kini menjadi draft dan harus di-deploy secara manual untuk menjadi aktif.
  - Fitur riwayat deployment: Setiap file YAML yang digenerate akan disimpan ke dalam tabel `config_history`.
  - Menambahkan halaman riwayat dengan fitur:
    - **Restore**: Mengembalikan seluruh pengaturan ke versi yang dipilih.
    - **Download**: Mengunduh versi konfigurasi YAML tertentu.
    - **Compare (Diff)**: Membandingkan dua versi deployment untuk melihat perubahan.
    - **Archive/Unarchive**: Mengarsipkan entri riwayat.
    - **Cleanup**: Membersihkan riwayat deployment yang lebih lama dari 30 hari.
- **Manajemen Pengguna & UI**:
  - Mengimplementasikan fitur "Log Aktivitas Pengguna" (Audit Trail) untuk mencatat semua aksi penting.
  - Form edit pengguna kini ditampilkan dalam modal dialog untuk alur kerja yang lebih baik.
  - Menambahkan tombol "Copy to Clipboard" untuk `rule` router.
  - Menambahkan fitur pencarian otomatis saat mengetik (dengan *debounce*) dan tombol reset.
- **Import YAML**: Menambahkan fitur "Import YAML" untuk mengunggah file konfigurasi `.yml` dan memperbarui data di database.

### Changed
- **Alur Kerja Deployment**: Tombol "Generate Config File" diubah menjadi "Generate & Deploy", yang akan langsung menimpa file `dynamic.yml` dan mencatatnya sebagai versi `active` baru di riwayat.
- **Alur Kerja Import**: Proses import kini menjadi non-destruktif (upsert) dan membuat "draft" baru, tidak langsung men-deploy konfigurasi aktif.
- **Integritas Data**:
  - Saat nama sebuah service diubah, semua router yang terhubung akan otomatis diperbarui.
  - Mencegah penghapusan Service jika masih terhubung ke router.
  - Mencegah penghapusan Server URL terakhir dari sebuah service.
- **Pengalaman Pengguna (UX)**:
  - Semua operasi CRUD (tambah, edit, hapus) kini menggunakan AJAX untuk pengalaman yang lebih mulus tanpa memuat ulang halaman.
  - Setelah menyimpan data, pengguna akan tetap berada di halaman form dan menerima notifikasi toast.
  - Menyesuaikan layout dan lebar kolom untuk memaksimalkan penggunaan ruang layar.
- **Struktur Kode**: Logika pembuatan file YAML direfaktor ke dalam class `YamlGenerator` untuk meningkatkan struktur, keterbacaan, dan performa.

### Fixed
- **Pembuatan YAML**: Memperbaiki serangkaian bug kritis terkait pembuatan file YAML, termasuk:
  - `entryPoints` dan `servers` yang tidak diformat sebagai list.
  - Karakter `|` yang tidak perlu ditambahkan ke `rule`.
  - String placeholder `___YAML_Literal_Block___` yang tidak dihapus.
  - Mengganti library `Spyc.php` yang bermasalah dengan versi yang stabil.
- **Fungsionalitas Halaman**:
  - Memperbaiki fatal error `ArgumentCountError` pada form tambah konfigurasi gabungan.
  - Memperbaiki fitur "Service Health Status" yang selalu menampilkan "Unknown".
  - Memperbaiki bug di mana data pada halaman riwayat tidak muncul saat halaman dibuka.
  - Memperbaiki semua tombol hapus yang tidak berfungsi.
  - Memperbaiki fitur "View YAML" pada halaman riwayat yang tidak menampilkan konten dan menambahkan syntax highlighting.
- **Penanganan Error**: Memperbaiki logika penanganan error pada AJAX dan memastikan semua jenis error PHP (termasuk fatal) dikembalikan sebagai JSON yang valid untuk fitur import.

### Security
- Memperkuat keamanan server dengan menambahkan aturan pada `.htaccess` untuk memblokir akses langsung ke file tersembunyi (seperti `.env`).

## [1.0.0] - 2025-09-27

### Added

- Rilis awal Config Manager.
- Fitur CRUD untuk **Routers**.
- Tampilan (Read-only) untuk **Services**, **Servers**, dan **Transports**.
- Fungsionalitas untuk men-generate file `dynamic-config.yml` dari data database.
- UI modern menggunakan Bootstrap 5.
- Dokumentasi awal `README.md` dan `CHANGELOG.md`.