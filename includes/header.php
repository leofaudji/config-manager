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
<body class="" 
      data-notification-interval="<?= (int)get_setting('header_notification_interval', 30) * 1000 ?>"
      data-theme="<?= $_COOKIE['theme'] ?? 'light' ?>"
>
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
    <a class="navbar-brand" href="<?= base_url('/') ?>">
        <img src="<?= base_url('/assets/img/logo-assistindo.png') ?>" alt="Assistindo Logo" class="brand-logo">
        <span class="brand-text">Config Manager</span>
    </a>
    <div class="sidebar-search-wrapper">
        <i class="bi bi-search search-icon"></i>
        <input type="text" id="sidebar-search-input" class="form-control form-control-sm" placeholder="Search menu..." autocomplete="off">
        <button id="sidebar-search-clear" class="btn btn-sm" style="display: none;" title="Clear search"><i class="bi bi-x-lg"></i></button>
    </div>
    <ul class="sidebar-nav">
        <?php
            // Define request_path here to be available for active link logic
            $request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (BASE_PATH && strpos($request_path, BASE_PATH) === 0) {
                $request_path = substr($request_path, strlen(BASE_PATH));
            }
        ?>
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
            <?php
            $open_menus_cookie = isset($_COOKIE['sidebar_open_menus']) ? json_decode($_COOKIE['sidebar_open_menus'], true) : [];
            $container_mgmt_active = str_starts_with($request_path, '/hosts') || str_starts_with($request_path, '/app-launcher') || str_starts_with($request_path, '/stack-changes') || str_starts_with($request_path, '/templates');
            $container_mgmt_expanded = $container_mgmt_active || in_array('container-submenu', $open_menus_cookie);
            ?>
            <li class="nav-item">
                <a class="nav-link <?= !$container_mgmt_expanded ? 'collapsed' : '' ?>" href="#" data-bs-toggle="collapse" data-bs-target="#container-submenu" aria-expanded="<?= $container_mgmt_expanded ? 'true' : 'false' ?>">
                    <span class="icon-wrapper">
                        <i class="bi bi-box-seam icon-default"></i>
                        <i class="bi bi-box-seam-fill icon-active"></i>
                    </span>
                    <span class="nav-link-text">Container Management</span>
                    <i class="bi bi-chevron-right submenu-arrow"></i>
                </a>
                <div class="collapse <?= $container_mgmt_expanded ? 'show' : '' ?>" id="container-submenu">
                    <ul class="sidebar-submenu">
                        <li class="nav-item">
                            <a class="nav-link d-flex justify-content-between align-items-center" href="<?= base_url('/hosts') ?>">
                                <span>Hosts</span><span class="badge bg-danger rounded-pill" id="sidebar-down-hosts-badge" style="display: none;"></span>
                            </a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/app-launcher') ?>">App Launcher</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/templates') ?>">Config Templates</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/stack-changes') ?>">Stack Changes</a></li>
                    </ul>
                </div>
            </li>

            <?php
            $traefik_active = str_starts_with($request_path, '/routers') || str_starts_with($request_path, '/services') || str_starts_with($request_path, '/middlewares') || str_starts_with($request_path, '/history') || str_starts_with($request_path, '/groups') || str_starts_with($request_path, '/traefik-hosts');
            $traefik_expanded = $traefik_active || in_array('traefik-submenu', $open_menus_cookie);
            ?>
            <li class="nav-item">
                <a class="nav-link <?= !$traefik_expanded ? 'collapsed' : '' ?>" href="#" data-bs-toggle="collapse" data-bs-target="#traefik-submenu" aria-expanded="<?= $traefik_expanded ? 'true' : 'false' ?>">
                    <span class="icon-wrapper">
                        <i class="bi bi-bezier2 icon-default"></i>
                        <i class="bi bi-bezier2 icon-active"></i>
                    </span>
                    <span class="nav-link-text">Traefik</span>
                    <i class="bi bi-chevron-right submenu-arrow"></i>
                </a>
                <div class="collapse <?= $traefik_expanded ? 'show' : '' ?>" id="traefik-submenu">
                    <ul class="sidebar-submenu">
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/routers') ?>">Routers</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/services') ?>">Services</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/middlewares') ?>">Middlewares</a></li>
                        <li class="nav-item"><a class="nav-link d-flex justify-content-between align-items-center" href="<?= base_url('/groups') ?>"><span>Groups</span><span class="badge bg-primary rounded-pill p-1" id="sidebar-pending-changes-badge" style="display: none;" title="Pending Changes"><i class="bi bi-circle-fill"></i></span></a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/traefik-hosts') ?>">Traefik Hosts</a></li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= base_url('/history') ?>">Deployment History</a>
                        </li>
                    </ul>
                </div>
            </li>

            <?php
            $monitoring_active = str_starts_with($request_path, '/health-status') || str_starts_with($request_path, '/sla-report') || str_starts_with($request_path, '/incident-reports') || str_starts_with($request_path, '/host-overview') || str_starts_with($request_path, '/container-events') || str_starts_with($request_path, '/central-logs') || str_starts_with($request_path, '/resource-hotspots') || str_starts_with($request_path, '/network-inspector') || str_starts_with($request_path, '/stats');
            $monitoring_expanded = $monitoring_active || in_array('monitoring-submenu', $open_menus_cookie);
            ?>
            <li class="nav-item">
                <a class="nav-link <?= !$monitoring_expanded ? 'collapsed' : '' ?>" href="#" data-bs-toggle="collapse" data-bs-target="#monitoring-submenu" aria-expanded="<?= $monitoring_expanded ? 'true' : 'false' ?>">
                    <span class="icon-wrapper">
                        <i class="bi bi-activity icon-default"></i>
                        <i class="bi bi-activity icon-active"></i>
                    </span>
                    <span class="nav-link-text">Monitoring</span>
                    <i class="bi bi-chevron-right submenu-arrow"></i>
                </a>
                <div class="collapse <?= $monitoring_expanded ? 'show' : '' ?>" id="monitoring-submenu">
                    <ul class="sidebar-submenu">
                        <li class="nav-item">
                            <a class="nav-link d-flex justify-content-between align-items-center" href="<?= base_url('/health-status') ?>">
                                <span>Service Health</span><span class="badge bg-danger rounded-pill" id="sidebar-unhealthy-badge" style="display: none;"></span>
                            </a></li>
                        <li class="nav-item">
                            <a class="nav-link d-flex justify-content-between align-items-center" id="sla-report-link" href="<?= base_url('/sla-report') ?>">
                                <span>SLA Report</span><span class="badge bg-warning rounded-pill" id="sidebar-sla-badge" style="display: none;"></span>
                            </a></li>
                        <li class="nav-item"><a class="nav-link d-flex justify-content-between align-items-center" href="<?= base_url('/incident-reports') ?>">
                                <span>Incident Reports</span><span class="badge bg-primary rounded-pill" id="sidebar-incident-badge" style="display: none;"></span>
                            </a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/host-overview') ?>">Host Overview</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/container-events') ?>">Container Events</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/central-logs') ?>">Centralized Logs</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/resource-hotspots') ?>">Resource Hotspots</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/network-inspector') ?>">Network Inspector</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/stats') ?>">Statistics</a></li>
                    </ul>
                </div>
            </li>

            <?php
            $system_active = str_starts_with($request_path, '/users') || str_starts_with($request_path, '/logs') || str_starts_with($request_path, '/health-check') || str_starts_with($request_path, '/webhook-reports') || str_starts_with($request_path, '/settings') || str_starts_with($request_path, '/cron-jobs') || str_starts_with($request_path, '/backup-restore');
            $system_expanded = $system_active || in_array('system-submenu', $open_menus_cookie);
            ?>
            <li class="nav-item">
                <a class="nav-link <?= !$system_expanded ? 'collapsed' : '' ?>" href="#" data-bs-toggle="collapse" data-bs-target="#system-submenu" aria-expanded="<?= $system_expanded ? 'true' : 'false' ?>">
                    <span class="icon-wrapper">
                        <i class="bi bi-shield-shaded icon-default"></i>
                        <i class="bi bi-shield-shaded icon-active"></i>
                    </span>
                    <span class="nav-link-text">System</span>
                    <i class="bi bi-chevron-right submenu-arrow"></i>
                </a>
                <div class="collapse <?= $system_expanded ? 'show' : '' ?>" id="system-submenu">
                    <ul class="sidebar-submenu">
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/users') ?>">Users</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/logs') ?>">Log Viewer</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/health-check') ?>">Health Check</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/webhook-reports') ?>">Webhook Reports</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('/cron-jobs') ?>">Cron Jobs</a></li>
                        <li class="nav-item"><a class="nav-link d-flex justify-content-between align-items-center" href="<?= base_url('/backup-restore') ?>">
                            <span>Backup & Restore</span><span id="sidebar-backup-status-badge" class="badge rounded-pill p-1" style="display: none;"><i class="bi bi-circle-fill"></i></span>
                        </a></li>
                    </ul>
                </div>
            </li>
        <?php endif; ?>
    </ul>
    <ul class="sidebar-nav mt-auto mb-0 sidebar-footer">
        <li class="nav-item sidebar-footer-item">
             <a class="nav-link" href="<?= base_url('/settings') ?>">
                <span class="icon-wrapper">
                    <i class="bi bi-sliders2 icon-default"></i>
                    <i class="bi bi-sliders2 icon-active"></i>
                </span>
                <span class="nav-link-text">General Settings</span>
            </a>
        </li>
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
            <a href="<?= base_url('/backup-restore') ?>" class="btn btn-light rounded-circle me-2 position-relative d-flex align-items-center justify-content-center" id="backup-status-btn" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Automatic Backup Status" style="width: 44px; height: 44px;">
                <i id="backup-status-icon" class="bi bi-database-down fs-4 text-secondary"></i>
            </a>
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
            <button type="button" class="btn btn-light rounded-circle me-2 position-relative d-flex align-items-center justify-content-center" id="incident-alert-btn" style="width: 44px; height: 44px;" data-bs-toggle="dropdown" aria-expanded="false" title="Open Incidents">
                <i class="bi bi-shield-fill-exclamation text-primary fs-4"></i>
                <span id="incident-alert-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-dark">
                    <span class="visually-hidden">Open incidents</span>
                </span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="incident-alert-btn" id="incident-alert-dropdown">
                <li><h6 class="dropdown-header">Open Incidents</h6></li>
                <li><hr class="dropdown-divider"></li>
                <div id="incident-alert-items-container" style="max-height: 400px; overflow-y: auto;"></div>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-center" href="<?= base_url('/incident-reports') ?>">View All Incidents</a></li>
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
                    <li><a class="dropdown-item no-spa" id="logout-link" href="<?= base_url('/logout') ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="main-content">
<?php
} // --- End of non-AJAX wrapper ---
?>