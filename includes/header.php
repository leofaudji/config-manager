<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Config Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/diff2html@3.4.47/bundles/css/diff2html.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
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
    </style>
    <script>
        const basePath = '<?= BASE_PATH ?>';
    </script>
</head>
<body class="">
<script>
    // On small screens, default to collapsed. On large screens, respect localStorage.
    const isSmallScreen = window.innerWidth <= 992;
    const storedState = localStorage.getItem('sidebar-collapsed');
    if (storedState === 'true' || (storedState === null && isSmallScreen)) {
        document.body.classList.add('sidebar-collapsed');
    }
</script>
<div class="sidebar">
    <a class="navbar-brand" href="<?= base_url('/') ?>"><i class="bi bi-gear-wide-connected"></i> Config Manager</a>
    <ul class="sidebar-nav">
        <li class="nav-item">
            <a class="nav-link" href="<?= base_url('/') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
        </li>

        <?php if ($_SESSION['role'] === 'admin'): ?>
            <li class="sidebar-header">Container</li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/hosts') ?>"><i class="bi bi-hdd-network-fill"></i> Hosts</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/app-launcher') ?>"><i class="bi bi-rocket-launch-fill"></i> App Launcher</a>
            </li>

            <li class="sidebar-header">Traefik</li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/routers') ?>"><i class="bi bi-sign-turn-right"></i> Routers</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/services') ?>"><i class="bi bi-hdd-stack-fill"></i> Services</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/middlewares') ?>"><i class="bi bi-puzzle-fill"></i> Middlewares</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/templates') ?>"><i class="bi bi-file-earmark-code-fill"></i> Config Templates</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/history') ?>"><i class="bi bi-clock-history"></i> Deployment History</a>
            </li>

            <li class="sidebar-header">System</li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/groups') ?>"><i class="bi bi-collection-fill"></i> Groups</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/users') ?>"><i class="bi bi-people-fill"></i> Users</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/logs') ?>"><i class="bi bi-card-list"></i> Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/settings') ?>"><i class="bi bi-sliders"></i> General Settings</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/health-check') ?>"><i class="bi bi-heart-pulse-fill"></i> Health Check</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= base_url('/stats') ?>"><i class="bi bi-bar-chart-line-fill"></i> Statistics</a>
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
             <button type="button" class="btn btn-dark me-2 position-relative" id="sync-stacks-btn">
                <i class="bi bi-git"></i> Sync Stacks to Git
                <span id="sync-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display: none;">
                    0
                    <span class="visually-hidden">pending changes</span>
                </span>
            </button>
             <a href="<?= base_url('/generate') ?>" class="btn btn-success"><i class="bi bi-rocket-takeoff"></i> Generate & Deploy</a>
            <?php endif; ?>
            <div class="nav-item dropdown ms-3">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                    <li><a class="dropdown-item" href="<?= base_url('/my-profile/change-password') ?>"><i class="bi bi-key-fill me-2"></i>Change My Password</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= base_url('/logout') ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="main-content">