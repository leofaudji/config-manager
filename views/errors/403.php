<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 Forbidden - Config Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath ?? '') ?>/assets/css/style.css">
    <style>
        .error-container {
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
    </style>
</head>
<body>
<script>
    // Apply theme immediately to prevent FOUC (Flash of Unstyled Content)
    (function() {
        const theme = localStorage.getItem('theme') || 'light';
        if (theme === 'dark') {
            document.body.classList.add('dark-mode');
        }
    })();
</script>
    <div class="container error-container">
        <div>
            <h1 class="display-1 fw-bold text-warning">403</h1>
            <p class="fs-3"> <span class="text-danger">Akses Ditolak.</span></p>
            <p class="lead">
                <?php echo htmlspecialchars($message ?? 'Anda tidak memiliki izin untuk mengakses halaman ini.'); ?>
            </p>
            <a href="<?php echo htmlspecialchars($basePath ?? '/'); ?>/" class="btn btn-primary mt-3">Kembali ke Dashboard</a>
        </div>
    </div>
</body>
</html>