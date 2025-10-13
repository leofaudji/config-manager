<?php
// Router sudah menangani sesi dan middleware 'guest' memastikan
// pengguna yang sudah login akan diarahkan dari halaman ini.
require_once 'includes/bootstrap.php'; // Diperlukan untuk base_url()

$error_message = '';
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Config Manager</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.2.0/flowbite.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="<?= base_url('assets/js/tailwind.config.js') ?>"></script>
    <style>
        /* macOS-inspired Theme Variables */
        :root {
            --bs-primary: #007aff;
            --bs-primary-rgb: 0, 122, 255;
            --bs-body-bg: #f0f2f5;
            --bs-body-color: #1d1d1f;
            --bs-border-color: #dcdcdc;
            --bs-card-bg: #ffffff;
            --bs-border-radius-lg: 0.65rem;
        }

        .dark-mode {
            --bs-primary: #0a84ff;
            --bs-primary-rgb: 10, 132, 255;
            --bs-body-bg: #1c1c1e;
            --bs-body-color: #f5f5f7;
            --bs-border-color: #424245;
            --bs-card-bg: #2c2c2e;
        }

        /* Apply variables to form elements */
        .form-control {
            background-color: var(--bs-body-bg); /* Match the page background for a subtle look */
            border-color: var(--bs-border-color);
            color: var(--bs-body-color);
        }
        .form-control:focus {
            background-color: var(--bs-body-bg);
            border-color: var(--bs-primary);
            color: var(--bs-body-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), .25);
        }
        .btn-primary {
            --bs-btn-bg: var(--bs-primary);
            --bs-btn-border-color: var(--bs-primary);
            --bs-btn-hover-bg: #0b5ed7; /* Standard hover for primary */
            --bs-btn-hover-border-color: #0a58ca;
        }

        html, body {
            height: 100%;
        }
        body {
            /* Clean background */
            background-color: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .dark-mode body {
            background-color: #1c1c1e;
        }
        .login-container {
            display: flex;
            width: 100%;
            max-width: 900px;
            min-height: 500px;
            background-color: var(--bs-card-bg);
            border-radius: var(--bs-border-radius-lg);
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }
        .jargon-section {
            background-color: var(--bs-primary);
            color: white;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            width: 45%;
        }
        .form-signin {
            width: 55%;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .form-signin .form-floating, .form-signin button, .form-signin h1, .form-signin h2 {
            animation: slideInUp 0.6s ease-out forwards;
            opacity: 0;
        }
        /* Stagger the animation */
        .form-signin-item.delay-1 { animation-delay: 0.1s; }
        .form-signin-item.delay-2 { animation-delay: 0.2s; }
        .form-signin-item.delay-3 { animation-delay: 0.3s; }
        .form-signin-item.delay-4 { animation-delay: 0.4s; }
        .form-signin h2 { animation-delay: 0.1s; }
        .form-signin .form-floating:nth-of-type(1) { animation-delay: 0.2s; }
        .form-signin .form-floating:nth-of-type(2) { animation-delay: 0.3s; }
        .form-signin button { animation-delay: 0.4s; }

        @keyframes slideInUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        #login-progress {
            height: 10px;
        }
        @media (max-width: 768px) {
            .jargon-section {
                display: none;
            }
            .form-signin {
                width: 100%;
                max-width: 420px;
            }
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-center">
<script>
    // Apply theme immediately to prevent FOUC (Flash of Unstyled Content)
    // --- REVISED: Unified Theme Logic ---
    (function() {
        const isDarkMode = localStorage.getItem('color-theme') === 'dark' || 
                           (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);

        if (isDarkMode) {
            document.documentElement.classList.add('dark');
            document.body.classList.add('dark-mode');
        } else {
            document.documentElement.classList.remove('dark');
            document.body.classList.remove('dark-mode');
        }
    })();
</script>
<main class="flex items-center justify-center min-h-screen p-4">
    <div class="w-full max-w-4xl flex bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden form-signin-item">
        <!-- Jargon Section -->
        <div class="hidden md:flex flex-col justify-center w-2/5 bg-primary-600 p-12 text-white jargon-section">
            <h1 class="font-bold text-4xl">Deploy Faster, Manage Smarter.</h1>
            <p class="mt-4 text-lg text-primary-200">Accelerate your development workflow by turning complex configurations into simple, repeatable actions.</p>
        </div>
        <!-- Form Section -->
        <div class="w-full md:w-3/5 p-8 sm:p-12 flex flex-col justify-center">
            <form action="<?= base_url('/login') ?>" method="POST" id="login-form" class="space-y-6">
                <div class="text-center">
                    <img class="mx-auto mb-4 form-signin-item" src="<?= base_url('/assets/img/logo-assistindo.png') ?>" alt="Assistindo Logo" width="72">
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white form-signin-item delay-1"><i class="bi bi-gear-wide-connected"></i> Config Manager</h1>
                    <h2 class="text-lg font-light text-gray-500 dark:text-gray-400 mt-2 form-signin-item delay-2">Please sign in</h2>
                </div>

                <div id="error-container">
                    <?php if ($error_message): ?>
                        <div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400" role="alert">
                            <?= $error_message ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-signin-item delay-2">
                    <label for="username" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Username</label>
                    <input type="text" name="username" id="username" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary-500 focus:border-primary-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white" placeholder="Username" required autofocus>
                </div>

                <div class="form-signin-item delay-3">
                    <label for="password" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Password</label>
                    <input type="password" name="password" id="password" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary-500 focus:border-primary-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white" placeholder="••••••••" required>
                </div>

                <button type="submit" class="w-full text-white bg-primary-600 hover:bg-primary-700 focus:ring-4 focus:outline-none focus:ring-primary-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center dark:bg-primary-600 dark:hover:bg-primary-700 dark:focus:ring-primary-800 form-signin-item delay-4">Sign in</button>

                <div id="login-progress" class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700" style="display: none;">
                    <div class="bg-primary-600 h-2.5 rounded-full" style="width: 0%"></div>
                </div>

                <p class="text-sm font-light text-gray-500 dark:text-gray-400 text-center">
                    &copy; <?= date('Y') ?> Assistindo
                </p>
            </form>
        </div>
    </div>
</main>
<script src="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.2.0/flowbite.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.getElementById('login-form');
    if (!loginForm) return;

    const submitButton = loginForm.querySelector('button[type="submit"]');
    const progressBarContainer = document.getElementById('login-progress');
    const progressBar = progressBarContainer.querySelector('div');
    const errorContainer = document.getElementById('error-container');

    loginForm.addEventListener('submit', function(e) {
        e.preventDefault();

        // Hide previous error
        errorContainer.innerHTML = '';

        // Disable button and show progress bar
        const originalButtonText = submitButton.innerHTML;
        submitButton.disabled = true;
        submitButton.innerHTML = `
            <svg aria-hidden="true" role="status" class="inline w-4 h-4 me-3 text-white animate-spin" viewBox="0 0 100 101" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M100 50.5908C100 78.2051 77.6142 100.591 50 100.591C22.3858 100.591 0 78.2051 0 50.5908C0 22.9766 22.3858 0.59082 50 0.59082C77.6142 0.59082 100 22.9766 100 50.5908ZM9.08144 50.5908C9.08144 73.1895 27.4013 91.5094 50 91.5094C72.5987 91.5094 90.9186 73.1895 90.9186 50.5908C90.9186 27.9921 72.5987 9.67226 50 9.67226C27.4013 9.67226 9.08144 27.9921 9.08144 50.5908Z" fill="#E5E7EB"/>
            <path d="M93.9676 39.0409C96.393 38.4038 97.8624 35.9116 97.0079 33.5539C95.2932 28.8227 92.871 24.3692 89.8167 20.348C85.8452 15.1192 80.8826 10.7238 75.2124 7.41289C69.5422 4.10194 63.2754 1.94025 56.7698 1.05124C51.7666 0.367541 46.6976 0.446843 41.7345 1.27873C39.2613 1.69328 37.813 4.19778 38.4501 6.62326C39.0873 9.04874 41.5694 10.4717 44.0505 10.1071C47.8511 9.54855 51.7191 9.52689 55.5402 10.0491C60.8642 10.7766 65.9928 12.5457 70.6331 15.2552C75.2735 17.9648 79.3347 21.5619 82.5849 25.841C84.9175 28.9121 86.7997 32.2913 88.1811 35.8758C89.083 38.2158 91.5421 39.6781 93.9676 39.0409Z" fill="currentColor"/>
            </svg>
            Logging in...
        `;
        progressBarContainer.style.display = 'block';
        progressBar.style.width = '0%';

        // Animate progress bar
        let progress = 0;
        const interval = setInterval(() => {
            progress += 25;
            progressBar.style.width = progress + '%';
        }, 200);

        const formData = new FormData(loginForm);

        fetch(loginForm.action, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json().then(data => ({ ok: response.ok, data })))
        .then(({ ok, data }) => {
            if (ok && data.status === 'success') {
                clearInterval(interval);
                progressBar.style.width = '100%';
                setTimeout(() => { window.location.href = data.redirect; }, 400);
            } else {
                throw new Error(data.message || 'An unknown error occurred.');
            }
        })
        .catch(error => {
            clearInterval(interval);
            progressBarContainer.style.display = 'none';
            errorContainer.innerHTML = `<div class="p-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400" role="alert">${error.message}</div>`;
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonText;
        });
    });
});
</script>
</body>
</html>