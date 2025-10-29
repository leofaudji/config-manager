<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    #faqAccordion .accordion-item {
        background-color: transparent;
        border: none;
    }

    #faqAccordion .accordion-button {
        background-color: transparent;
        border-bottom: 1px solid var(--bs-border-color);
        border-radius: 0 !important;
    }

    #faqAccordion .accordion-button:not(.collapsed) {
        background-color: transparent;
        color: var(--bs-primary);
        box-shadow: none;
    }
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-question-circle-fill"></i> Pertanyaan Umum (FAQ)</h1>
</div>

<div class="mb-4">
    <div class="input-group">
        <span class="input-group-text" id="faq-search-icon"><i class="bi bi-search"></i></span>
        <input type="text" class="form-control" id="faq-search-input" placeholder="Search questions and answers..." aria-label="Search FAQ" aria-describedby="faq-search-icon">
    </div>
</div>

<div id="no-results-message" class="alert alert-warning" style="display: none;">No results found for your search.</div>

<div class="card">
    <div class="card-body">
        <div class="accordion" id="faqAccordion">

            <!-- General Questions -->
            <div class="accordion-item">
                <h2 class="accordion-header" id="headingGeneral">
                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseGeneral" aria-expanded="true" aria-controls="collapseGeneral">
                        <i class="bi bi-patch-question-fill me-2"></i> Pertanyaan Umum
                    </button>
                </h2>
                <div id="collapseGeneral" class="accordion-collapse collapse show" aria-labelledby="headingGeneral" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">
                        <div class="faq-item mb-4">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Apa itu aplikasi ini?</p>
                            <p class="ps-4">Config Manager adalah aplikasi web yang dirancang untuk menyederhanakan pengelolaan konfigurasi dinamis (seperti untuk Traefik Proxy), host Docker, dan deployment aplikasi. Aplikasi ini menyediakan antarmuka terpusat untuk operasi CRUD, pemantauan, dan alur kerja deployment.</p>
                            <div class="ps-4 mt-3">
                                <p>Berikut adalah gambaran alur kerja arsitektur aplikasi secara umum:</p>
                                <div class="text-center border rounded p-3">
                                    <pre class="mermaid">
graph TD
    subgraph "Pengguna"
        A["<i class='bi bi-person-fill'></i> Pengguna (Admin/Viewer)"]
    end

    subgraph "Infrastruktur Terkelola"
        C["<i class='bi bi-bezier2'></i> Traefik Proxy"]
        subgraph Docker Hosts
            D["<i class='bi bi-docker'></i> Docker Daemon"]
            E["<i class='bi bi-heart-pulse-fill'></i> Health Agent"]
            F["<i class='bi bi-stack'></i> Deployed Apps (Stacks)"]
        end
    end

    subgraph "Aplikasi Utama"
        B["<i class='bi bi-display-fill'></i> Config Manager<br>(PHP + MySQL)"]
    end

    A -- "Mengelola via Web UI" --> B
    B -- "Menghasilkan `dynamic.yml`" --> C
    B -- "Mengelola via Docker API" --> D
    B -- "Men-deploy & Mengelola" --> F
    B -- "Men-deploy & Mengonfigurasi" --> E
    E -- "Melaporkan Status Kesehatan" --> B
                                    </pre>
                                </div>
                            </div>
                        </div>

                        <div class="faq-item">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Untuk siapa aplikasi ini?</p>
                            <p class="ps-4">Aplikasi ini ditujukan untuk Administrator Sistem, Engineer DevOps, dan developer yang membutuhkan antarmuka terpadu untuk mengelola aplikasi dalam kontainer, perutean jaringan, dan kesehatan sistem secara keseluruhan.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Traefik Management -->
            <div class="accordion-item">
                <h2 class="accordion-header" id="headingTraefik">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTraefik" aria-expanded="false" aria-controls="collapseTraefik">
                        <i class="bi bi-bezier2 me-2"></i> Manajemen Traefik
                    </button>
                </h2>
                <div id="collapseTraefik" class="accordion-collapse collapse" aria-labelledby="headingTraefik" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">
                        <div class="faq-item mb-4">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Apa itu Router, Service, dan Middleware?</p>
                            <div class="ps-4">
                                <ul>
                                    <li><strong>Router:</strong> Mendefinisikan aturan (misalnya, `Host('app.example.com')`) untuk menangkap permintaan masuk dan mengarahkannya ke sebuah Service.</li>
                                    <li><strong>Service:</strong> Mewakili aplikasi backend Anda. Service mengetahui alamat IP dan port dari kontainer yang sedang berjalan.</li>
                                    <li><strong>Middleware:</strong> Komponen opsional yang dapat memodifikasi permintaan sebelum mencapai service Anda (misalnya, menambahkan header, pembatasan laju).</li>
                                </ul>
                                <div class="btn-group mt-2">
                                    <a href="<?= base_url('/routers') ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-bezier2"></i> Routers</a>
                                    <a href="<?= base_url('/services') ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-gear-wide-connected"></i> Services</a>
                                    <a href="<?= base_url('/middlewares') ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-layers-fill"></i> Middlewares</a>
                                </div>
                            </div>
                        </div>
                        <div class="faq-item mb-4">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Apa fungsi Groups?</p>
                            <p class="ps-4">Groups memungkinkan Anda untuk mengorganisir router dan service. Ini berguna untuk mengelola konfigurasi untuk lingkungan yang berbeda (misalnya, Development, Staging, Production) atau proyek yang berbeda. Deployment dapat dilakukan per grup.</p>
                            <a href="<?= base_url('/groups') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-collection-fill"></i> Ke Halaman Groups</a>
                        </div>
                        <div class="faq-item">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Mengapa saya melihat notifikasi "Pending Changes"?</p>
                            <p class="ps-4">Notifikasi ini muncul ketika Anda telah membuat perubahan pada router, service, atau middleware, tetapi belum men-deploy-nya. Aplikasi ini melacak perubahan berdasarkan grup. Buka halaman "Groups" dan klik tombol "Deploy" untuk grup yang memiliki perubahan tertunda untuk menerapkannya.</p>
                            <a href="<?= base_url('/groups') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-collection-fill"></i> Ke Halaman Groups</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- App Launcher & Stacks -->
            <div class="accordion-item">
                <h2 class="accordion-header" id="headingAppLauncher">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAppLauncher" aria-expanded="false" aria-controls="collapseAppLauncher">
                        <i class="bi bi-stack me-2"></i> App Launcher & Stack
                    </button>
                </h2>
                <div id="collapseAppLauncher" class="accordion-collapse collapse" aria-labelledby="headingAppLauncher" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">
                        <div class="faq-item mb-4">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Bagaimana cara kerja App Launcher?</p>
                            <p class="ps-4">App Launcher adalah wizard untuk men-deploy aplikasi baru. Fitur ini mengambil sumber Anda (Git, Image Docker, atau konten Editor), menggabungkannya dengan konfigurasi sumber daya (port, volume, dll.), menghasilkan file `docker-compose.yml`, dan menjalankannya di host target. Anda dapat melihat alur kerja visual <a href="<?= base_url('/app-launcher-workflow') ?>" target="_blank">di sini</a>.</p>
                            <a href="<?= base_url('/app-launcher') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-rocket-launch-fill"></i> Buka App Launcher</a>
                        </div>
                        <div class="faq-item mb-4">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Di mana file aplikasi saya disimpan?</p>
                            <p class="ps-4">Saat Anda men-deploy aplikasi, server ini membuat direktori persisten untuknya, biasanya di bawah path yang ditentukan di "General Settings" (misalnya, `/opt/stacks/`). Direktori ini menyimpan file `docker-compose.yml` dan, untuk deployment berbasis Git, salinan repositori Anda. Ini memungkinkan aplikasi untuk mengelola siklus hidup stack (update, stop, dll.) di kemudian hari.</p>
                            <a href="<?= base_url('/settings') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-sliders"></i> Ke General Settings</a>
                        </div>
                        <div class="faq-item">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Apa perbedaan mode deployment Standalone dan Swarm?</p>
                            <div class="ps-4">
                                <ul>
                                    <li><strong>Standalone:</strong> Men-deploy aplikasi sebagai proyek `docker-compose` standar di satu host Docker.</li>
                                    <li><strong>Swarm:</strong> Men-deploy aplikasi sebagai `docker stack` di klaster Docker Swarm. Mode ini mengaktifkan fitur seperti replika, batasan penempatan, dan pembaruan bergulir (rolling updates).</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monitoring & Reporting -->
            <div class="accordion-item">
                <h2 class="accordion-header" id="headingMonitoring">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseMonitoring" aria-expanded="false" aria-controls="collapseMonitoring">
                        <i class="bi bi-activity me-2"></i> Pemantauan & Pelaporan
                    </button>
                </h2>
                <div id="collapseMonitoring" class="accordion-collapse collapse" aria-labelledby="headingMonitoring" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">
                        <div class="faq-item mb-4">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Bagaimana cara kerja pengecekan Health Status?</p>
                            <p class="ps-4">Sistem secara berkala memeriksa kesehatan kontainer Anda. Sistem ini memprioritaskan direktif `HEALTHCHECK` bawaan kontainer. Jika tidak tersedia, sistem akan mencoba terhubung ke port yang dipublikasikan atau port internal umum (seperti 80, 443) untuk menentukan apakah layanan responsif.</p>
                            <a href="<?= base_url('/health-status') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-heart-pulse"></i> Lihat Health Status</a>
                        </div>
                        <div class="faq-item mb-4">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Apa itu Laporan SLA?</p>
                            <p class="ps-4">Laporan Service Level Agreement (SLA) menghitung persentase uptime kontainer Anda selama periode yang dipilih. Laporan ini menggunakan data dari riwayat kesehatan untuk menentukan periode downtime dan memberikan skor ketersediaan secara keseluruhan.</p>
                            <a href="<?= base_url('/sla-report') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-clipboard-data-fill"></i> Lihat Laporan SLA</a>
                        </div>
                        <div class="faq-item">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Bagaimana Insiden dibuat?</p>
                            <p class="ps-4">Insiden dibuat secara otomatis ketika sistem mendeteksi perubahan status kesehatan dari "Healthy" menjadi "Unhealthy" untuk sebuah kontainer, atau ketika sebuah host menjadi "Unreachable". Ini memungkinkan Anda untuk melacak peristiwa downtime dan melakukan Analisis Akar Masalah (RCA).</p>
                            <a href="<?= base_url('/incident-reports') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-shield-fill-exclamation"></i> Lihat Laporan Insiden</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System & Administration -->
            <div class="accordion-item">
                <h2 class="accordion-header" id="headingSystem">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSystem" aria-expanded="false" aria-controls="collapseSystem">
                        <i class="bi bi-sliders me-2"></i> Sistem & Administrasi
                    </button>
                </h2>
                <div id="collapseSystem" class="accordion-collapse collapse" aria-labelledby="headingSystem" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">
                        <div class="faq-item mb-4">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Bagaimana cara menambahkan Host Docker baru?</p>
                            <p class="ps-4">Buka halaman "Hosts" dan klik "Add New Host". Anda perlu menyediakan URL API Docker dari host target (misalnya, `tcp://192.168.1.100:2375`). Pastikan daemon Docker di host target dikonfigurasi untuk mendengarkan pada soket TCP.</p>
                            <a href="<?= base_url('/hosts') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-hdd-network-fill"></i> Ke Halaman Hosts</a>
                        </div>
                        <div class="faq-item mb-4">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Bagaimana cara kerja fitur Backup & Restore?</p>
                            <p class="ps-4">Fitur backup membuat satu file JSON yang berisi semua data konfigurasi Anda (router, service, pengguna, pengaturan, dll.). Fitur restore akan sepenuhnya menimpa database saat ini dengan data dari file backup yang dipilih. Ini berguna untuk pemulihan bencana atau migrasi aplikasi ke server baru.</p>
                            <a href="<?= base_url('/backup-restore') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-database-down"></i> Ke Halaman Backup & Restore</a>
                        </div>
                        <div class="faq-item">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Apa fungsi Cron Jobs?</p>
                            <p class="ps-4">Halaman "Cron Job Management" memungkinkan Anda untuk mengaktifkan dan menjadwalkan tugas latar belakang yang penting untuk fitur pemantauan aplikasi. Ini termasuk mengumpulkan statistik host, menjalankan autoscaler layanan, dan membersihkan data log lama.</p>
                            <a href="<?= base_url('/cron-jobs') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-clock-history"></i> Ke Halaman Cron Jobs</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security -->
            <div class="accordion-item">
                <h2 class="accordion-header" id="headingSecurity">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSecurity" aria-expanded="false" aria-controls="collapseSecurity">
                        <i class="bi bi-shield-lock-fill me-2"></i> Keamanan
                    </button>
                </h2>
                <div id="collapseSecurity" class="accordion-collapse collapse" aria-labelledby="headingSecurity" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">
                        <div class="faq-item mb-4">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Apa saja fitur keamanan yang ada di aplikasi ini?</p>
                            <p class="ps-4">Aplikasi ini mencakup beberapa lapisan keamanan, termasuk:
                                <ul>
                                    <li><strong>Manajemen Peran Pengguna:</strong> Memisahkan hak akses antara 'Admin' (akses penuh) dan 'Viewer' (hanya lihat).</li>
                                    <li><strong>Deteksi Ancaman Real-time:</strong> Integrasi dengan <a href="https://falco.org/" target="_blank">Falco</a> untuk mendeteksi perilaku mencurigakan di dalam kontainer secara real-time.</li>
                                    <li><strong>Webhook Aman:</strong> Endpoint webhook untuk deployment otomatis diamankan dengan token rahasia untuk mencegah eksekusi yang tidak sah.</li>
                                    <li><strong>Konfigurasi TLS:</strong> Kemampuan untuk mengaktifkan TLS dan menentukan Cert Resolver pada router Traefik untuk lalu lintas terenkripsi.</li>
                                </ul>
                            </p>
                            <a href="<?= base_url('/users') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-people-fill"></i> Ke Manajemen Pengguna</a>
                            <a href="<?= base_url('/security-events') ?>" class="btn btn-sm btn-outline-primary mt-2" target="_blank"><i class="bi bi-shield-shaded"></i> Lihat Security Events</a>
                        </div>
                        <div class="faq-item mb-4">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Bagaimana cara kerja integrasi Falco?</p>
                            <p class="ps-4">Aplikasi ini menyediakan endpoint API (`/api/security/ingest`) yang dapat menerima peringatan dari Falcosidekick. Saat Falco mendeteksi aktivitas yang mencurigakan di salah satu host Anda, Falcosidekick akan meneruskan acara tersebut ke Config Manager. Acara tersebut kemudian dicatat dan ditampilkan di halaman "Security Events" untuk analisis lebih lanjut.</p>
                            <a href="<?= base_url('/security-workflow') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-diagram-3"></i> Lihat Alur Kerja Keamanan</a>
                        </div>
                        <div class="faq-item">
                            <p class="fw-bold mb-1"><i class="bi bi-question-circle text-primary me-2"></i>Bagaimana webhook diamankan?</p>
                            <p class="ps-4">Setiap endpoint webhook (misalnya, untuk auto-deploy dari Git) dilindungi oleh token rahasia yang unik. Saat Anda mengonfigurasi webhook di penyedia Git Anda (seperti GitHub atau GitLab), Anda harus menyertakan token ini dalam permintaan. Config Manager akan memvalidasi token ini pada setiap permintaan yang masuk untuk memastikan hanya sumber tepercaya yang dapat memicu deployment.</p>
                            <a href="<?= base_url('/settings') ?>" class="btn btn-sm btn-outline-primary ms-4 mt-2" target="_blank"><i class="bi bi-sliders"></i> Lihat Pengaturan Webhook</a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('faq-search-input');
    const accordion = document.getElementById('faqAccordion');
    const noResultsMessage = document.getElementById('no-results-message');

    if (!searchInput || !accordion || !noResultsMessage) return;

    function filterFAQ() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        let totalResultsFound = false;

        // Iterate over each accordion item (e.g., General, Traefik, etc.)
        accordion.querySelectorAll('.accordion-item').forEach(accordionItem => {
            let sectionHasVisibleItems = false;
            const collapseElement = accordionItem.querySelector('.accordion-collapse');
            const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapseElement);

            // Iterate over each question/answer pair within the section
            accordionItem.querySelectorAll('.faq-item').forEach(faqItem => {
                const itemText = faqItem.textContent.toLowerCase();

                if (itemText.includes(searchTerm)) {
                    faqItem.style.display = 'block';
                    sectionHasVisibleItems = true;
                    totalResultsFound = true;
                } else {
                    faqItem.style.display = 'none';
                }
            });

            // Show or hide the entire section based on its content
            if (sectionHasVisibleItems) {
                accordionItem.style.display = 'block';
                // If searching, expand the section to show the results
                if (searchTerm) {
                    bsCollapse.show();
                }
            } else {
                accordionItem.style.display = 'none';
                bsCollapse.hide();
            }
        });

        // Show "No results" message if nothing was found
        noResultsMessage.style.display = totalResultsFound ? 'none' : 'block';

        // If search is cleared, collapse all but the first section
        if (!searchTerm) {
            bootstrap.Collapse.getOrCreateInstance(document.getElementById('collapseGeneral')).show();
        }
    }

    searchInput.addEventListener('input', filterFAQ);
});
</script>
<script type="module">
    import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.esm.min.mjs';
    mermaid.initialize({ startOnLoad: true });
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>