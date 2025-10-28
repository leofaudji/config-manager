<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-database-down"></i> Backup & Restore</h1>
</div>

<div class="row">
    <!-- Backup Section -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Create Backup</h5>
            </div>
            <div class="card-body">
                <p>Click the button below to download a full backup of your application's configuration data. This includes all routers, services, hosts, users, settings, and more.</p>
                <p class="mb-2">You can either download a backup directly to your computer or trigger a manual backup to be saved on the server according to the automatic backup settings.</p>
                <div class="btn-group">
                    <a href="<?= base_url('/api/system/backup?type=download') ?>" class="btn btn-primary no-spa" id="download-backup-btn">
                        <i class="bi bi-download"></i> Download Backup
                    </a>
                    <button class="btn btn-outline-secondary" id="trigger-backup-btn"><i class="bi bi-play-circle"></i> Run Server Backup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Restore Section -->
    <div class="col-md-6">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0">Restore from Backup</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <strong><i class="bi bi-exclamation-triangle-fill"></i> Warning:</strong> This is a destructive operation. Restoring from a backup will <strong>completely overwrite</strong> all existing configuration data in this application.
                </div>
                <form id="restore-form" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="backup-file" class="form-label">Select Backup File (.json)</label>
                        <input class="form-control" type="file" id="backup-file" name="backup_file" accept=".json" required>
                    </div>
                    <button type="submit" class="btn btn-danger w-100" id="restore-btn">
                        <i class="bi bi-upload"></i> Restore Configuration
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Backup History Calendar -->
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-calendar-check"></i> Backup History Calendar</h5>
        <div class="d-flex align-items-center">
            <button id="prev-month-btn" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></button>
            <h6 id="calendar-month-year" class="mb-0 mx-3"></h6>
            <button id="next-month-btn" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></button>
        </div>
    </div>
    <div class="card-body">
        <div id="backup-calendar-container">
            <!-- Calendar will be rendered here by JS -->
        </div>
        <div class="d-flex justify-content-end align-items-center mt-2">
            <small class="me-3">Legend:</small>
            <span class="badge bg-success me-1">Success</span>
            <span class="badge bg-danger me-1">Failed</span>
            <span class="badge bg-light text-dark border">No Backup</span>
        </div>
    </div>
</div>

<style>
    #backup-calendar-container { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
    .calendar-day, .calendar-day-link {
        border: 1px solid var(--bs-border-color);
        border-radius: var(--bs-border-radius-sm);
        min-height: 50px; /* Give a minimum height */
        padding: 4px;
        transition: transform 0.2s, box-shadow 0.2s;
        display: flex;
        flex-direction: column; /* Stack items vertically */
        align-items: center;
        justify-content: center;
    }
    .calendar-day-link { text-decoration: none; color: inherit; display: block; }
    .calendar-day-link:hover { transform: scale(1.05); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    /* --- IDE: Tingkatkan Kontras Warna Font --- */
    .calendar-day.bg-success, .calendar-day.bg-success a {
        color: var(--bs-success-text-emphasis) !important; /* Gunakan variabel Bootstrap untuk warna teks yang kontras */
    }
    .calendar-day.bg-danger, .calendar-day.bg-danger a {
        color: var(--bs-danger-text-emphasis) !important; /* Gunakan variabel Bootstrap untuk warna teks yang kontras */
    }
    .dark-mode .calendar-day.bg-success, .dark-mode .calendar-day.bg-success a {
        color: #9fecb3 !important; /* Warna yang lebih terang untuk kontras di mode gelap */
    }
    /* --- Akhir IDE --- */
</style>

<script>
window.pageInit = function() {
    const restoreForm = document.getElementById('restore-form');
    const restoreBtn = document.getElementById('restore-btn');
    const backupFile = document.getElementById('backup-file');
    const triggerBackupBtn = document.getElementById('trigger-backup-btn');

    if (triggerBackupBtn) {
        triggerBackupBtn.addEventListener('click', function() {
            if (!confirm('Are you sure you want to run a manual backup now? This will create a backup file on the server.')) {
                return;
            }

            const originalBtnContent = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Backing up...`;

            fetch('<?= base_url('/api/system/backup?type=manual') ?>', { method: 'POST' })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    if (ok) {
                        // Refresh the calendar to show the new backup
                        fetchAndRenderCalendar();
                    }
                })
                .catch(error => showToast('Backup failed: ' + error.message, false))
                .finally(() => {
                    this.disabled = false;
                    this.innerHTML = originalBtnContent;
                });
        });
    }

    restoreForm.addEventListener('submit', function(e) {
        e.preventDefault();

        if (!backupFile.files.length) {
            showToast('Please select a backup file.', false);
            return;
        }

        if (!confirm('Are you absolutely sure you want to restore? This will delete all current configurations and replace them with the data from the backup file. This action cannot be undone.')) {
            return;
        }

        const originalBtnContent = restoreBtn.innerHTML;
        restoreBtn.disabled = true;
        restoreBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Restoring...`;

        const formData = new FormData();
        formData.append('backup_file', backupFile.files[0]);

        fetch('<?= base_url('/api/system/restore') ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json().then(data => ({ ok: response.ok, data })))
        .then(({ ok, data }) => {
            if (ok) {
                showToast(data.message, true);
                setTimeout(() => {
                    // Redirect to login after successful restore as user data might have changed
                    window.location.href = '<?= base_url('/logout') ?>';
                }, 2000);
            } else {
                throw new Error(data.message || 'An unknown error occurred.');
            }
        })
        .catch(error => {
            showToast('Restore failed: ' + error.message, false);
        })
        .finally(() => {
            restoreBtn.disabled = false;
            restoreBtn.innerHTML = originalBtnContent;
        });
    });

    // --- Calendar Logic ---
    const calendarContainer = document.getElementById('backup-calendar-container');
    const monthYearEl = document.getElementById('calendar-month-year');
    const prevBtn = document.getElementById('prev-month-btn');
    const nextBtn = document.getElementById('next-month-btn');
    let currentDate = new Date();

    function renderCalendar(year, month, statuses) {
        calendarContainer.innerHTML = '';
        const date = new Date(year, month, 1);
        monthYearEl.textContent = date.toLocaleString('default', { month: 'long', year: 'numeric' });

        const firstDay = date.getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        weekdays.forEach(day => {
            const dayEl = document.createElement('div');
            dayEl.className = 'text-center fw-bold small text-muted';
            dayEl.textContent = day;
            calendarContainer.appendChild(dayEl);
        });

        for (let i = 0; i < firstDay; i++) {
            calendarContainer.appendChild(document.createElement('div'));
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const dayString = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const dayData = statuses[dayString];
            
            let badgeClass = 'bg-light text-dark border';
            let tooltipText = 'No backup recorded';
            let isClickable = false;
            let downloadUrl = '#';

            if (dayData) {
                if (dayData.status === 'success' && dayData.filename) {
                    badgeClass = 'bg-success';
                    tooltipText = `Backup successful. Click to download: ${dayData.filename}`;
                    isClickable = true;
                    downloadUrl = `<?= base_url('/api/system/backup/download?file=') ?>${dayData.filename}`;
                } else if (dayData.status === 'error') {
                    badgeClass = 'bg-danger';
                    tooltipText = 'Backup failed';
                }
            }

            const dayEl = isClickable ? document.createElement('a') : document.createElement('div');
            dayEl.className = isClickable ? 'calendar-day-link' : 'calendar-day';
            if (isClickable) {
                // The whole cell is no longer a link, only the icon will be
                dayEl.classList.remove('calendar-day-link');
                dayEl.classList.add('calendar-day');
            }
            dayEl.classList.add(...badgeClass.split(' '));

            let content = `<span class="fw-bold fs-5">${day}</span>`;
            if (isClickable) {
                content += `<a href="${downloadUrl}" class="no-spa text-decoration-none" data-bs-toggle="tooltip" data-bs-original-title="${tooltipText}">
                                <i class="bi bi-file-earmark-zip-fill fs-6 mt-1"></i>
                            </a>`;
            }
            dayEl.innerHTML = content;

            calendarContainer.appendChild(dayEl);
        }

        // Re-initialize tooltips
        const tooltipTriggerList = calendarContainer.querySelectorAll('[data-bs-toggle="tooltip"]');
        [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }

    function fetchAndRenderCalendar() {
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth() + 1; // API expects 1-12

        fetch(`<?= base_url('/api/system/backup-status') ?>?year=${year}&month=${month}`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success') {
                    renderCalendar(year, month - 1, result.data); // renderCalendar expects 0-11
                } else {
                    throw new Error(result.message);
                }
            })
            .catch(error => {
                calendarContainer.innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
            });
    }

    prevBtn.addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        fetchAndRenderCalendar();
    });

    nextBtn.addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        fetchAndRenderCalendar();
    });

    fetchAndRenderCalendar(); // Initial load
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>