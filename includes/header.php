<?php
if (!(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
// --- Start of non-AJAX wrapper ---
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Config Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css">
    <!-- CodeMirror CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/theme/monokai.min.css">

    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/diff2html@3.4.47/bundles/css/diff2html.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        /* macOS-inspired Theme */
        :root {
            /* Sudut yang lebih bulat untuk nuansa yang lebih lembut seperti macOS */
            --bs-border-radius: 0.5rem;
            --bs-border-radius-lg: 0.65rem;
            --bs-border-radius-sm: 0.4rem;

            --bs-primary: #007aff;
            --bs-primary-rgb: 0, 122, 255;
            --bs-secondary: #8e8e93;
            --bs-secondary-rgb: 142, 142, 147;
            --bs-body-bg: #f5f5f7;
            --bs-body-color: #1d1d1f;
            --bs-border-color: #dcdcdc;
            --bs-border-color-translucent: rgba(0, 0, 0, 0.1);
            --bs-card-bg: #ffffff;
            --bs-card-border-color: var(--bs-border-color);
            --bs-light: #f5f5f7;
            --bs-light-rgb: 245, 245, 247;
            --bs-dark: #343a40;
            --bs-dark-rgb: 52, 58, 64;

            /* Variabel kustom untuk aplikasi */
            --cf-sidebar-bg: #e9ecef;
            --cf-sidebar-link-color: #495057;
            --cf-sidebar-link-hover-bg: #d4dae0;
            --cf-sidebar-link-active-bg: var(--bs-primary);
            --cf-sidebar-link-active-color: #ffffff;
            --cf-top-navbar-bg: var(--bs-card-bg);
            --cf-border-color: var(--bs-border-color);
            --cf-text-color: var(--bs-body-color);
            --cf-text-muted-color: #6c757d;
            --cf-table-striped-bg: var(--bs-card-bg); /* Membuat baris belang cocok dengan latar belakang kartu */
        }

        .dark-mode {
            --bs-primary: #0a84ff;
            --bs-primary-rgb: 10, 132, 255;
            --bs-secondary: #8d8d92;
            --bs-secondary-rgb: 141, 141, 146;
            --bs-body-bg: #1c1c1e;
            --bs-body-color: #f5f5f7;
            --bs-border-color: #424245;
            --bs-border-color-translucent: rgba(255, 255, 255, 0.15);
            --bs-card-bg: #2c2c2e;
            --bs-card-border-color: var(--bs-border-color);

            /* Variabel kustom untuk mode gelap */
            --cf-sidebar-bg: #343a40;
            --cf-sidebar-link-color: #adb5bd;
            --cf-sidebar-link-hover-bg: #495057;
            --cf-sidebar-link-active-bg: var(--bs-primary);
            --cf-sidebar-link-active-color: #ffffff;
            --cf-top-navbar-bg: var(--bs-card-bg);
            --cf-border-color: var(--bs-border-color);
            --cf-text-color: var(--bs-body-color);
            --cf-text-muted-color: #8d8d92;
            --cf-table-striped-bg: var(--bs-card-bg); /* Membuat baris belang cocok dengan latar belakang kartu */
        }

        body {
            /* Menggunakan tumpukan font sistem macOS */
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        /* Menambahkan bayangan halus ke kartu untuk meniru jendela macOS */
        .card {
            box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.08);
            transition: box-shadow 0.2s ease-in-out;
        }

        .card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08), 0 2px 6px rgba(0,0,0,0.12);
        }

        .dark-mode .card {
            box-shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.2);
        }

    </style>
    <link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
    <style>
        .blinking-badge {
            animation: blinker 1.5s linear infinite;
        }

        @keyframes blinker {
            50% {
                opacity: 0.2;
            }
        }

        .btn-pulse {
            animation: pulse-animation 1.5s infinite;
        }

        @keyframes pulse-animation {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
    </style>
    <script>
        const basePath = '<?= BASE_PATH ?>';
    </script>

    <!-- Global Libraries for SLA Report & other potential pages -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.29.1/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.autotable.min.js"></script>

</head>
<body class="">
<div id="loading-bar" class="loading-bar"></div>
<script>
    // On small screens, default to collapsed. On large screens, respect localStorage.
    const isSmallScreen = window.innerWidth <= 992;
    const storedState = localStorage.getItem('sidebar-collapsed');
    if (storedState === 'true' || (storedState === null && isSmallScreen)) {
        document.body.classList.add('sidebar-collapsed');
    }
</script>
<div class="sidebar">
    <a class="navbar-brand" href="<?= base_url('/') ?>"><i class="bi bi-gear-wide-connected"></i> <span class="brand-text">Config Manager</span></a>
    <ul class="sidebar-nav">
        <li class="nav-item">
            <a class="nav-link" href="<?= base_url('/') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Dashboard">
                <span class="icon-wrapper">
                    <i class="bi bi-speedometer2 icon-default"></i>
                    <i class="bi bi-speedometer icon-active"></i>
                </span>
                <span class="nav-link-text">Dashboard</span>
            </a>
        </li>

        <?php if ($_SESSION['role'] === 'admin'): ?>
            <li class="sidebar-header">Traefik</li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/routers') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Routers">
                    <span class="icon-wrapper">
                        <i class="bi bi-sign-turn-right icon-default"></i>
                        <i class="bi bi-sign-turn-right-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Routers</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/services') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Services">
                    <span class="icon-wrapper">
                        <i class="bi bi-hdd-stack icon-default"></i>
                        <i class="bi bi-hdd-stack-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Services</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/middlewares') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Middlewares">
                    <span class="icon-wrapper">
                        <i class="bi bi-puzzle icon-default"></i>
                        <i class="bi bi-puzzle-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Middlewares</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/history') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Deployment History">
                    <span class="icon-wrapper">
                        <i class="bi bi-clock-history icon-default"></i>
                        <i class="bi bi-clock-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Deployment History</span>
                </a>
            </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= base_url('/groups') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Groups">
                <span class="icon-wrapper">
                    <i class="bi bi-collection icon-default"></i>
                    <i class="bi bi-collection-fill icon-active"></i>
                </span>
                <span class="nav-link-text">Groups</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= base_url('/traefik-hosts') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Traefik Hosts">
                <span class="icon-wrapper">
                    <i class="bi bi-hdd-rack icon-default"></i>
                    <i class="bi bi-hdd-rack-fill icon-active"></i>
                </span>
                <span class="nav-link-text">Traefik Hosts</span>
            </a>
        </li>


            <li class="sidebar-header">Container Management</li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/hosts') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Hosts">
                    <span class="icon-wrapper">
                        <i class="bi bi-hdd-network icon-default"></i>
                        <i class="bi bi-hdd-network-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Hosts</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/app-launcher') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="App Launcher">
                    <span class="icon-wrapper">
                        <i class="bi bi-rocket-takeoff icon-default"></i>
                        <i class="bi bi-rocket-takeoff-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">App Launcher</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/stack-changes') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Stack Changes">
                    <span class="icon-wrapper">
                        <i class="bi bi-calendar-week icon-default"></i>
                        <i class="bi bi-calendar-week-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Stack Changes</span>
                </a>
            </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= base_url('/templates') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Config Templates">
                <span class="icon-wrapper">
                    <i class="bi bi-file-earmark-code icon-default"></i>
                    <i class="bi bi-file-earmark-code-fill icon-active"></i>
                </span>
                <span class="nav-link-text">Config Templates</span>
            </a>
        </li>


        <li class="sidebar-header">Monitoring</li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/health-status') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Service Health Status">
                    <span class="icon-wrapper">
                        <i class="bi bi-heart-pulse icon-default"></i>
                        <i class="bi bi-heart-pulse-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Service Health</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/sla-report') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="SLA Report">
                    <span class="icon-wrapper">
                        <i class="bi bi-clipboard-data icon-default"></i>
                        <i class="bi bi-clipboard-data-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">SLA Report</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/host-overview') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Host Overview">
                    <span class="icon-wrapper">
                        <i class="bi bi-display icon-default"></i>
                        <i class="bi bi-display-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Host Overview</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/container-events') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Container Events">
                    <span class="icon-wrapper">
                        <i class="bi bi-activity icon-default"></i>
                        <i class="bi bi-activity icon-active"></i>
                    </span>
                    <span class="nav-link-text">Container Events</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/central-logs') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Centralized Log Viewer">
                    <span class="icon-wrapper">
                        <i class="bi bi-journals icon-default"></i>
                        <i class="bi bi-journals icon-active"></i>
                    </span>
                    <span class="nav-link-text">Centralized Logs</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/resource-hotspots') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Resource Hotspots">
                    <span class="icon-wrapper">
                        <i class="bi bi-fire icon-default"></i>
                        <i class="bi bi-fire icon-active"></i>
                    </span>
                    <span class="nav-link-text">Resource Hotspots</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/network-inspector') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Network Inspector">
                    <span class="icon-wrapper">
                        <i class="bi bi-diagram-3-fill icon-default"></i>
                        <i class="bi bi-diagram-3-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Network Inspector</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/stats') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Statistics">
                    <span class="icon-wrapper">
                        <i class="bi bi-bar-chart-line icon-default"></i>
                        <i class="bi bi-bar-chart-line-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Statistics</span>
                </a>
            </li>

            <li class="sidebar-header">System</li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/users') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Users">
                    <span class="icon-wrapper">
                        <i class="bi bi-people icon-default"></i>
                        <i class="bi bi-people-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/logs') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Log Viewer">
                    <span class="icon-wrapper">
                        <i class="bi bi-journals icon-default"></i>
                        <i class="bi bi-journals icon-active"></i>
                    </span>
                    <span class="nav-link-text">Log Viewer</span>
                </a>
            </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= base_url('/health-check') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Health Check">
                <span class="icon-wrapper">
                    <i class="bi bi-heart-pulse icon-default"></i>
                    <i class="bi bi-heart-pulse-fill icon-active"></i>
                </span>
                <span class="nav-link-text">Health Check</span>
            </a>
        </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/webhook-reports') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Webhook Reports">
                    <span class="icon-wrapper">
                        <i class="bi bi-github icon-default"></i>
                        <i class="bi bi-github icon-active"></i>
                    </span>
                    <span class="nav-link-text">Webhook Reports</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/settings') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="General Settings">
                    <span class="icon-wrapper">
                        <i class="bi bi-sliders icon-default"></i>
                        <i class="bi bi-sliders2 icon-active"></i>
                    </span>
                    <span class="nav-link-text">General Settings</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/cron-jobs') ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="Cron Job Management">
                    <span class="icon-wrapper">
                        <i class="bi bi-clock-history icon-default"></i>
                        <i class="bi bi-clock-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Cron Jobs</span>
                </a>
            </li>
        <?php endif; ?>
    </ul>
</div>

<div class="content-wrapper">
    <nav class="top-navbar d-flex justify-content-between align-items-center">
        <button class="btn" id="sidebar-toggle-btn" title="Toggle sidebar">
            <i class="bi bi-list fs-4"></i>
        </button>
        <div class="d-flex align-items-center">
            <?php if ($_SESSION['role'] === 'admin'): ?>
             <button type="button" class="btn btn-light rounded-circle me-2 position-relative d-flex align-items-center justify-content-center" id="sync-stacks-btn" title="Sync Stacks to Git" style="width: 44px; height: 44px;">
                <i class="bi bi-git fs-4"></i>
                <span id="sync-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display: none;">
                    
                    <span class="visually-hidden">pending changes</span>
                </span>
            </button>
            <button type="button" class="btn btn-light rounded-circle me-2 position-relative d-flex align-items-center justify-content-center" id="unhealthy-alert-btn" style="width: 44px; height: 44px;" data-bs-toggle="dropdown" aria-expanded="false" title="Unhealthy Items Alert">
                <i class="bi bi-heartbreak-fill text-danger fs-4"></i>
                <span id="unhealthy-alert-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-dark">
                    
                    <span class="visually-hidden">Unhealthy items</span>
                </span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="unhealthy-alert-btn" id="unhealthy-alert-dropdown">
                <li><h6 class="dropdown-header">Unhealthy Services / Containers</h6></li>
                <li><hr class="dropdown-divider"></li>
                <div id="unhealthy-alert-items-container" style="max-height: 400px; overflow-y: auto;">
                    <!-- Alert items will be injected here -->
                </div>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-center" href="<?= base_url('/health-status') ?>">View Health Status</a></li>
            </ul>
            <button type="button" class="btn btn-light rounded-circle me-2 position-relative d-flex align-items-center justify-content-center" id="sla-alert-btn" style="width: 44px; height: 44px;" data-bs-toggle="dropdown" aria-expanded="false" title="SLA Violation Alert">
                <i class="bi bi-shield-exclamation text-warning fs-4"></i>
                <span id="sla-alert-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                    
                    <span class="visually-hidden">SLA violations</span>
                </span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="sla-alert-btn" id="sla-alert-dropdown">
                <li><h6 class="dropdown-header">Items Below Minimum SLA Target</h6></li>
                <li><hr class="dropdown-divider"></li>
                <div id="sla-alert-items-container" style="max-height: 400px; overflow-y: auto;">
                    <!-- Alert items will be injected here -->
                </div>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-center" href="<?= base_url('/sla-report') ?>">View Full Report</a></li>
            </ul>
            <a href="<?= base_url('/groups') ?>" id="deploy-notification-btn" class="btn btn-light rounded-circle me-2 position-relative d-flex align-items-center justify-content-center" style="display: none; width: 44px; height: 44px;" title="Pending Changes to Deploy">
                <i class="bi bi-cloud-upload-fill text-primary fs-4"></i>
            </a>
            <?php endif; ?>
            <div class="nav-item dropdown">
                <a href="#" class="btn btn-light rounded-circle d-flex align-items-center justify-content-center" id="appMenuDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="Menu" style="width: 44px; height: 44px;">
                    <i class="bi bi-grid-3x3-gap-fill fs-4"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="appMenuDropdown" style="min-width: 280px;">
                    <li><h6 class="dropdown-header">Quick Actions</h6></li>
                    <li><a class="dropdown-item" href="<?= base_url('/app-launcher') ?>"><i class="bi bi-rocket-launch-fill me-2 text-muted"></i>Launch New App</a></li>
                    <li><a class="dropdown-item" href="<?= base_url('/routers/new') ?>"><i class="bi bi-plus-circle-fill me-2 text-muted"></i>Add New Router</a></li>
                    <li><hr class="dropdown-divider"></li>

                    <li><h6 class="dropdown-header">Monitoring</h6></li>
                    <li><a class="dropdown-item" href="<?= base_url('/sla-report') ?>"><i class="bi bi-clipboard-data-fill me-2 text-muted"></i>SLA Report</a></li>
                    <li><a class="dropdown-item" href="<?= base_url('/central-logs') ?>"><i class="bi bi-journals me-2 text-muted"></i>Centralized Logs</a></li>
                    <li><a class="dropdown-item" href="<?= base_url('/logs') ?>"><i class="bi bi-journals me-2 text-muted"></i>Activity Logs</a></li>
                    <li><hr class="dropdown-divider"></li>

                    <li><h6 class="dropdown-header">System & Management</h6></li>
                    <li><a class="dropdown-item" href="<?= base_url('/settings') ?>"><i class="bi bi-sliders me-2 text-muted"></i>General Settings</a></li>
                    <li><a class="dropdown-item" href="<?= base_url('/users') ?>"><i class="bi bi-people-fill me-2 text-muted"></i>User Management</a></li>
                    <li><hr class="dropdown-divider"></li>

                    <li><h6 class="dropdown-header">Hi, <?= htmlspecialchars($_SESSION['username']) ?>!</h6></li>
                    <li><a class="dropdown-item" href="<?= base_url('/my-profile/change-password') ?>"><i class="bi bi-key-fill me-2 text-muted"></i>Change Password</a></li>
                    <li><a class="dropdown-item no-spa" href="<?= base_url('/logout') ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="main-content">
<?php
} // --- End of non-AJAX wrapper ---
?>