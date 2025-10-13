<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 Not Found - Config Manager</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.2.0/flowbite.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="<?= htmlspecialchars($basePath ?? '') ?>/assets/js/tailwind.config.js"></script>
</head>
<body class="bg-gray-100 dark:bg-gray-900">
<script>
    // Tailwind dark mode setup
    if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
</script>
    <main class="flex items-center justify-center min-h-screen">
        <div class="text-center">
            <h1 class="text-8xl font-bold text-primary-600">404</h1>
            <p class="text-3xl font-semibold text-gray-800 dark:text-gray-200 mt-4">
                <span class="text-red-600 dark:text-red-500">Oops!</span> Halaman tidak ditemukan.
            </p>
            <p class="text-lg text-gray-600 dark:text-gray-400 mt-2">
                <?php echo htmlspecialchars($message ?? 'Halaman yang Anda cari tidak ada atau telah dipindahkan.'); ?>
            </p>
            <a href="<?php echo htmlspecialchars($basePath ?? '/'); ?>/" class="mt-6 inline-block px-6 py-3 text-sm font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-700">Kembali ke Dashboard</a>
        </div>
    </main>
</body>
</html>