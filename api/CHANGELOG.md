# Changelog

Semua perubahan penting pada proyek ini akan didokumentasikan di file ini.

Format file ini didasarkan pada [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), dan proyek ini mengikuti [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2023-10-29

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

### Changed
- **UI Refinements**: Tombol "Preview Config" dipindahkan dari header utama ke halaman "Routers" untuk alur kerja yang lebih kontekstual.
- **App Updater**: Halaman "Update Application" telah didesain ulang sepenuhnya agar konsisten dengan fungsionalitas dan UI App Launcher yang baru.

### Removed
- **Import YAML**: Fitur "Import YAML" dihapus dari header utama untuk menyederhanakan antarmuka.

## [2.9.2] - 2023-10-28

### Changed
- **Database Seeding**: Menyinkronkan data awal pada `setup_db.php` agar sesuai dengan contoh `dynamic.yml`, menggantikan data dummy yang lama.

## [2.9.1] - 2023-10-28

### Changed
- **Deploy from Preview Workflow**: Tombol "Deploy" di modal pratinjau kini menggunakan AJAX, memberikan umpan balik (progress button, notifikasi toast) tanpa memuat ulang halaman, dan menutup modal secara otomatis setelah berhasil.

## [2.9.0] - 2023-10-28

### Added
- **Deploy from Preview**: Menambahkan tombol "Deploy" di dalam modal pratinjau konfigurasi, memungkinkan admin untuk langsung men-deploy konfigurasi yang sedang dilihat setelah konfirmasi.

## [2.8.9] - 2023-10-28

### Changed
- **Preview Workflow**: Fitur "Preview Configuration" kini ditampilkan dalam modal dialog, bukan di halaman terpisah, untuk alur kerja yang lebih cepat dan terintegrasi.

## [2.8.8] - 2023-10-28

### Added
- **Preview Configuration**: Menambahkan fitur untuk melihat pratinjau file YAML yang akan dihasilkan dari data saat ini di database. Aksi ini bersifat non-destruktif (tidak menimpa file `dynamic.yml` atau membuat entri riwayat baru).

## [2.8.6] - 2023-10-28

### Changed
- **UI Tweak**: Menyesuaikan padding bawah pada body untuk memberikan lebih banyak ruang bagi footer.

## [2.8.5] - 2023-10-28

### Fixed
- **PHP Compatibility**: Memperbaiki `Warning: Undefined property: mysqli::$in_transaction` pada PHP versi di bawah 8.0 dengan menghapus pengecekan yang tidak kompatibel saat melakukan rollback transaksi database.

## [2.8.4] - 2023-10-28

### Fixed
- **Fatal Error on Edit Router**: Memperbaiki error fatal `mysqli_stmt object is already closed` pada saat mengedit router dengan merestrukturisasi alur logika di backend.
- **Logic Bug on Add Router**: Memperbaiki bug di mana form "Tambah Router" tidak dapat membuat service baru. Kini, alur tambah dan edit router memiliki fungsionalitas yang konsisten.

## [2.8.3] - 2023-10-28

### Changed
- **Edit Router Workflow**: Halaman "Edit Router" kini lebih fleksibel, memungkinkan pengguna untuk menghubungkan router ke service yang sudah ada atau membuat service baru secara langsung, sama seperti pada form kloning.

## [2.8.1] - 2023-10-28

### Fixed
- **Missing Clone Button**: Memperbaiki bug di mana tombol "Clone" untuk Service tidak muncul di dashboard.

## [2.8.0] - 2023-10-28

### Added
- **Clone Functionality**: Menambahkan tombol "Clone" untuk Router dan Service, memungkinkan duplikasi konfigurasi yang ada dengan cepat.
- **Group Management Page**: Menambahkan halaman khusus untuk operasi CRUD (Create, Read, Update, Delete) pada Grup.
- **Load Balancer Method**: Menambahkan opsi untuk memilih metode load balancer (seperti `leastConn`, `ipHash`, dll.) saat membuat atau mengedit Service. Keterangan metode akan muncul di dashboard jika bukan default.
- **History Cleanup**: Menambahkan fitur untuk membersihkan riwayat deployment yang sudah diarsipkan dan lebih lama dari 30 hari.
- **Bulk Actions**: Menambahkan kemampuan untuk memilih beberapa router dan memindahkannya ke grup lain secara massal.

### Changed
- **Clone Router Workflow**: Halaman "Clone Router" kini lebih fleksibel, memungkinkan pengguna untuk menghubungkan router ke service yang sudah ada atau membuat service baru secara langsung, sama seperti form tambah konfigurasi gabungan.

### Security
- Memperkuat keamanan server dengan menambahkan aturan pada `.htaccess` untuk memblokir akses langsung ke file tersembunyi (seperti `.env`).

## [2.7.7] - 2023-10-28

### Fixed
- Memperbaiki fatal error `ArgumentCountError` pada form tambah konfigurasi gabungan yang disebabkan oleh jumlah parameter yang salah saat binding ke database.

## [2.7.6] - 2023-10-28

### Changed
- Mengubah alur kerja "Generate Config File". Tombol ini kini menjadi "Generate & Deploy", yang akan langsung menimpa file `dynamic.yml` di server dan mencatatnya sebagai versi `active` baru di riwayat.
- Mengubah alur kerja "Generate Config File". Tombol ini kini menjadi "Generate & Deploy", yang akan langsung menimpa file `dynamic.yml` di server dan mencatatnya sebagai versi `active` baru di riwayat deployment.

## [2.7.5] - 2023-10-28

### Fixed
- Memperbaiki fitur "Service Health Status" yang selalu menampilkan "Unknown". Logika pencocokan kini lebih fleksibel dan tidak lagi bergantung pada provider Traefik yang di-hardcode.

## [2.7.1] - 2023-10-28

### Changed
- Meningkatkan alur kerja "User Management". Form edit pengguna kini ditampilkan dalam modal dialog, sehingga admin tidak perlu meninggalkan halaman daftar pengguna.

## [2.7.0] - 2023-10-28

### Added
- Mengimplementasikan fitur "Log Aktivitas Pengguna" (Audit Trail) untuk mencatat semua aksi penting yang dilakukan pengguna, seperti login, deploy, dan manajemen pengguna.

## [2.6.4] - 2023-10-28

### Changed
- Mengubah alur kerja "Import YAML" menjadi non-destruktif. Proses import kini akan menambah atau memperbarui (upsert) konfigurasi yang ada di database, bukan menghapus semuanya.

## [2.6.3] - 2023-10-28

### Fixed
- Mengganti library `Spyc.php` dengan versi yang sudah diperbaiki untuk mengatasi bug kritis pada pembuatan file YAML, memastikan `servers` dan `entryPoints` diformat dengan benar sebagai list.

## [2.6.2] - 2023-10-28

### Fixed
- Memperbaiki bug kritis pada fitur "Deploy" dan "Import" yang menyebabkan proses gagal secara diam-diam. Logika validasi kini lebih kuat dan dapat menangani berbagai format YAML dengan benar.

## [2.6.1] - 2023-10-28

### Fixed
- Memperbaiki bug kritis pada fitur "Deploy" dan "Import" yang menyebabkan proses gagal secara diam-diam. Logika validasi kini lebih kuat dan dapat menangani berbagai format YAML dengan benar.

## [2.6.0] - 2023-10-28

### Changed
- Meningkatkan fitur "Deploy". Proses deploy kini juga akan menyinkronkan database dengan konten dari versi riwayat yang dipilih, memastikan konsistensi data antara database dan file konfigurasi yang aktif.
- Meningkatkan fitur "Deploy". Proses deploy kini juga akan menyinkronkan database dengan konten dari versi riwayat deployment yang dipilih, memastikan konsistensi data antara database dan file konfigurasi yang aktif.

## [2.5.7] - 2023-10-28

### Fixed
- Memperbaiki fitur "Import YAML" secara tuntas dengan membuat logika validasi lebih defensif. Skrip kini dapat menangani file YAML yang tidak memiliki semua bagian (seperti `http`, `services`, `serversTransports`) tanpa menyebabkan error.

## [2.5.6] - 2023-10-28

### Fixed
- Memperbaiki fitur "Import YAML" agar lebih toleran terhadap file yang tidak memiliki semua kunci (seperti `http`, `services`, dll.), mencegah fatal error.
- Pesan error saat import kini ditampilkan dengan benar di dalam modal.
- Memperbarui teks peringatan pada modal import untuk lebih akurat menjelaskan alur kerja "Import as Draft".

## [2.5.4] - 2023-10-28

### Fixed
- Memperbaiki fitur "Compare Configurations" yang tidak menampilkan apa pun jika tidak ada perbedaan. Kini, sebuah pesan akan ditampilkan jika kedua versi identik.

## [2.5.3] - 2023-10-28

### Changed
- Mengubah alur kerja "Import YAML". Proses import kini akan membuat "draft" baru di riwayat dan memperbarui database, tetapi tidak akan langsung men-deploy atau mengubah konfigurasi yang sedang aktif.
- Mengubah alur kerja "Import YAML". Proses import kini akan membuat "draft" baru di riwayat deployment dan memperbarui database, tetapi tidak akan langsung men-deploy atau mengubah konfigurasi yang sedang aktif.

## [2.5.2] - 2023-10-28

### Fixed
- Memperbaiki fitur "Import YAML". Proses import kini juga akan menyimpan konfigurasi ke riwayat sebagai versi `active` yang baru dan langsung men-deploy-nya ke file `dynamic.yml`.

## [2.5.1] - 2023-10-28

### Fixed
- Memperbaiki bug kritis pada pembuatan file YAML di mana `servers` tidak diformat sebagai list, dan karakter `|` yang tidak perlu ditambahkan ke `rule`.

## [2.4.4] - 2023-10-28

### Fixed
- Memperbaiki bug kritis pada pembuatan file YAML di mana `entryPoints` dan `servers` tidak diformat sebagai list, dan karakter `|` yang tidak perlu ditambahkan ke `rule`.

## [2.5.0] - 2023-10-28

### Added
- Mengimplementasikan sistem "Draft & Deploy" untuk konfigurasi. Konfigurasi yang digenerate kini menjadi draft dan harus di-deploy secara manual untuk menjadi aktif.

## [2.3.9] - 2023-10-28

### Fixed
- Memperbaiki bug di mana fitur auto-refresh setelah menghapus item di halaman utama tidak berfungsi. Logika JavaScript kini lebih andal dalam membedakan antara aksi refresh dan redirect.

## [2.3.8] - 2023-10-28

### Changed
- Meningkatkan pengalaman pengguna dengan mengimplementasikan auto-refresh pada tabel di halaman utama. Operasi hapus kini akan memperbarui data secara dinamis tanpa memuat ulang seluruh halaman.

## [2.3.7] - 2023-10-28

### Fixed
- Memperbaiki bug pada pembuatan file YAML di mana karakter `|` yang tidak perlu ditambahkan ke `rule` router.

## [2.3.6] - 2023-10-28

### Fixed
- Memperbaiki bug kritis di mana data pada halaman riwayat konfigurasi tidak muncul saat halaman dibuka dengan membuat logika persiapan parameter query lebih andal.

## [2.3.5] - 2023-10-28

### Added
- Menambahkan fitur untuk mengarsipkan (archive) dan membatalkan pengarsipan (unarchive) entri riwayat konfigurasi.

## [2.3.4] - 2023-10-28

### Fixed
- Memperbaiki bug pada pembuatan file YAML di mana string placeholder `___YAML_Literal_Block___` tidak dihapus dari hasil akhir.

## [2.3.3] - 2023-10-28

### Added
- Menambahkan tombol "Copy to Clipboard" untuk setiap `rule` pada tabel Router untuk kemudahan penggunaan.

## [2.3.1] - 2023-10-28

### Added
- Menambahkan dukungan untuk konfigurasi TLS (`certResolver`) pada router.

## [2.3.0] - 2023-10-28

### Added
- Menambahkan fitur pencarian pada halaman riwayat deployment untuk memfilter berdasarkan nama pengguna.

## [2.2.9] - 2023-10-28

### Added
- Menambahkan fitur untuk membandingkan (diff) dua versi deployment dari halaman riwayat.

## [2.2.8] - 2023-10-28

### Added
- Menambahkan tombol "Download" pada setiap baris di halaman riwayat deployment, memungkinkan admin untuk mengunduh versi konfigurasi YAML tertentu.

## [2.2.6] - 2023-10-28

### Fixed
- Memperbaiki bug di mana data pada halaman riwayat deployment tidak langsung muncul saat halaman dibuka.

## [2.2.5] - 2023-10-28

### Fixed
- Memperbaiki fitur "View YAML" pada halaman riwayat yang tidak menampilkan konten.
- Menambahkan syntax highlighting (pewarnaan sintaks) pada tampilan YAML untuk meningkatkan keterbacaan.

## [2.2.4] - 2023-10-28

### Added
- Menambahkan tombol "Restore" pada halaman riwayat deployment, memungkinkan admin untuk mengembalikan seluruh pengaturan ke versi yang dipilih.

## [1.9.11] - 2023-10-28

### Fixed
- Memperbaiki error `Unexpected token '<'` pada fitur import YAML secara tuntas dengan menambahkan *shutdown function* yang memastikan semua jenis error PHP (termasuk fatal error) dikembalikan sebagai JSON yang valid.

## [1.9.10] - 2023-10-28

### Fixed
- Memperbaiki fitur "Import YAML" dengan menambahkan validasi yang lebih kuat pada struktur file yang diunggah. Ini mencegah error saat memproses file YAML dengan format yang tidak terduga dan memberikan pesan kesalahan yang lebih jelas kepada pengguna.
- Mengoptimalkan proses import dengan mempersiapkan *statement* database di luar perulangan.

## [1.9.9] - 2023-10-28

### Fixed
- Memperbaiki semua tombol hapus yang tidak berfungsi dengan menambahkan kembali logika event handler AJAX yang hilang.

## [1.9.8] - 2023-10-28

### Added
- Menambahkan fitur "Import YAML". Pengguna kini dapat mengunggah file konfigurasi `.yml` untuk menimpa seluruh data yang ada di database.

## [1.9.6] - 2023-10-28

### Fixed
- Memperbaiki bug kritis pada pembuatan file YAML di mana `entryPoints` dengan satu item tidak diformat sebagai list. Masalah ini diselesaikan dengan mengganti file library `Spyc.php` yang buggy dengan versi resmi yang stabil.
- Menambahkan `trim` pada pemrosesan `entryPoints` untuk menangani spasi ekstra.

## [1.9.5] - 2023-10-28

### Changed
- Menyempurnakan logika pembuatan YAML: Melakukan refactoring pada metode `getServices` untuk memastikan pengelompokan server lebih eksplisit dan jelas, sesuai dengan format daftar yang berkelanjutan.

## [1.9.1] - 2023-10-28

### Removed
- Menghapus label "Pass Host Header" dari tampilan daftar Service untuk antarmuka yang lebih bersih.

## [1.8.9] - 2023-10-28

### Changed
- Menyesuaikan lebar header agar konsisten dengan lebar body untuk tampilan yang lebih serasi.

## [1.8.7] - 2023-10-28

### Changed
- Menyesuaikan lebar kolom pada halaman utama untuk memberikan lebih banyak ruang pada tabel Router (rasio ~2:1).
- Mengubah layout utama dari `container` menjadi `container-fluid` untuk memaksimalkan penggunaan ruang layar.

## [1.8.6] - 2023-10-28

### Fixed
- Memperbaiki bug pada fitur pencarian otomatis yang mencegahnya berfungsi.
- Merapikan dan menggabungkan beberapa *event listener* pada JavaScript untuk meningkatkan keandalan dan keterbacaan kode.

## [1.8.5] - 2023-10-28

### Added
- Menambahkan tombol "Reset" pada form pencarian untuk menghapus filter dengan mudah.
- Pencarian kini berjalan secara otomatis saat pengguna mengetik (dengan *debounce*), tidak perlu lagi menekan tombol cari.

## [1.8.0] - 2023-10-28

### Changed
- Menyeragamkan semua alur kerja AJAX. Kini, semua operasi (tambah, edit, hapus) akan selalu mengarahkan pengguna kembali ke halaman utama dengan pesan status yang jelas.

### Fixed
- Memperbaiki semua tombol hapus yang tidak berfungsi dengan menyederhanakan dan memperbaiki logika JavaScript.

## [1.7.3] - 2023-10-28

### Changed
- Menyeragamkan alur kerja AJAX untuk operasi hapus. Kini, setelah menghapus item, pengguna akan diarahkan kembali ke halaman utama dengan pesan status, sama seperti operasi simpan.

### Removed
- Menghapus file JavaScript yang tidak terpakai (`main.js`) untuk menjaga kebersihan proyek.

## [1.7.2] - 2023-10-28

### Changed
- Mengubah alur kerja form simpan data. Setelah submit, baik berhasil maupun gagal, pengguna akan selalu diarahkan kembali ke halaman utama (`index.php`) dengan pesan status yang sesuai.

## [1.7.1] - 2023-10-28

### Fixed
- Memperbaiki logika penanganan error pada AJAX untuk semua operasi (tambah, edit, hapus). Error dari server kini ditangani dengan benar dan pesannya ditampilkan kepada pengguna melalui notifikasi toast.
- Memperbaiki `actions/router_delete.php` untuk mengirim status HTTP error yang benar saat penghapusan gagal.

## [1.7.0] - 2023-10-28

### Changed
- Pengalaman pengguna pada form tambah/edit ditingkatkan. Setelah menyimpan data, pengguna akan tetap berada di halaman form dan menerima notifikasi toast. Form tambah akan di-reset secara otomatis setelah berhasil disimpan, siap untuk entri berikutnya.

## [1.6.1] - 2023-10-28

### Changed
- Proses tambah (create) dan edit (update) untuk semua entitas kini juga menggunakan AJAX. Form akan memberikan umpan balik instan melalui notifikasi toast jika terjadi error, dan akan mengarahkan kembali ke halaman utama setelah berhasil, tanpa memuat ulang halaman saat validasi gagal.

## [1.6.0] - 2023-10-28

### Changed
- Proses hapus (delete) untuk semua entitas (router, service, server) kini menggunakan AJAX, sehingga tidak memerlukan muat ulang halaman dan memberikan pengalaman pengguna yang lebih mulus.

## [1.5.5] - 2023-10-28

### Added
- Menambahkan fitur "Safe Delete" untuk Server URL. Server terakhir dari sebuah service tidak dapat dihapus untuk menjaga validitas konfigurasi.

## [1.5.4] - 2023-10-28

### Fixed
- Memperbaiki fitur hapus router yang tidak berfungsi karena lokasi file yang salah. Logika penghapusan telah dipindahkan ke `actions/router_delete.php`.

## [1.5.3] - 2023-10-28

### Added
- Menambahkan fitur "Safe Delete" untuk Services. Sebuah service tidak dapat dihapus jika masih ada router yang terhubung dengannya, untuk mencegah konfigurasi yang rusak.

## [1.5.2] - 2023-10-28

### Changed
- Meningkatkan integritas data: Saat nama sebuah service diubah, semua router yang terhubung ke service tersebut akan otomatis diperbarui untuk mencerminkan nama baru. Proses ini menggunakan transaksi database untuk memastikan konsistensi.

## [1.5.1] - 2023-10-28

### Added
- Mengimplementasikan kembali fungsionalitas "Edit" untuk Routers, Services, dan Servers dengan form dan logika backend yang terdedikasi.

### Changed
- Mengaktifkan kembali tombol "Edit" di halaman utama untuk mengakses form edit.

## [1.4.1] - 2023-10-28

### Added
- Menambahkan file library `Spyc.php` yang hilang, yang merupakan dependensi penting untuk membuat file YAML.

## [1.4.0] - 2023-10-28

### Added
- Fitur riwayat deployment: Setiap file YAML yang digenerate akan disimpan ke dalam tabel `config_history` di database.

### Fixed
- Memperbaiki bug pada `YamlGenerator` yang dapat menyebabkan error saat tidak ada data service. Logika pengambilan data server kini lebih aman dan andal.

## [1.3.1] - 2023-10-28

### Changed
- Mengganti nama aplikasi dari "Traefik Manager" menjadi "Config Manager" di seluruh antarmuka dan dokumentasi.

## [1.3.0] - 2023-10-28

### Added
- Form interaktif gabungan (`combined_form.php`) untuk membuat Router dan Service (baru atau yang sudah ada) dalam satu alur kerja.
- Logika backend (`actions/combined_add.php`) menggunakan transaksi database untuk memastikan integritas data saat menyimpan konfigurasi baru.

## [1.2.0] - 2023-10-28

### Changed
- Logika pembuatan file YAML direfaktor ke dalam class `YamlGenerator` untuk meningkatkan struktur kode, keterbacaan, dan performa (menghindari N+1 query).

### Added
- Class `YamlGenerator` baru untuk menangani semua logika terkait pembuatan file konfigurasi.

## [1.1.0] - 2023-10-28

### Added
- Fungsionalitas CRUD penuh untuk **Services** dan **Servers**.
- Validasi di sisi server untuk mencegah duplikasi nama pada **Routers** dan **Services**.
- Tampilan UI yang lebih baik untuk blok Service di halaman utama.

## [1.0.0] - 2023-10-27

### Added
- Rilis awal Config Manager.
- Fitur CRUD untuk **Routers**.
- Tampilan (Read-only) untuk **Services**, **Servers**, dan **Transports**.
- Fungsionalitas untuk men-generate file `dynamic-config.yml` dari data database.
- UI modern menggunakan Bootstrap 5.
- Dokumentasi awal `README.md` dan `CHANGELOG.md`.