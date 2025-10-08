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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
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
<body class="text-center">
<script>
    // Apply theme immediately to prevent FOUC (Flash of Unstyled Content)
    (function() {
        const theme = localStorage.getItem('theme') || 'light';
        if (theme === 'dark') {
            document.body.classList.add('dark-mode');
        }
    })();
</script>
    <main class="login-container">
        <div class="jargon-section d-none d-md-flex">
            <h1 class="fw-bold display-6">Deploy Faster, Manage Smarter.</h1>
            <p class="lead mt-3">Accelerate your development workflow by turning complex configurations into simple, repeatable actions.</p>
        </div>
        <div class="form-signin">
                <form action="<?= base_url('/login') ?>" method="POST" id="login-form" class="text-start">
                    <center><img class="mb-4" src="<?= base_url('/assets/img/logo-assistindo.png') ?>" alt="Assistindo Logo" width="72">
                    <h1 class="h3 mb-3 fw-normal"><i class="bi bi-gear-wide-connected"></i> Config Manager</h1>
                    <h2 class="h5 mb-4 fw-light text-muted">Please sign in</h2></center>
                    <div id="error-container">
                        <?php if ($error_message): ?>
                            <div class="alert alert-danger"><?= $error_message ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-floating"><input type="text" class="form-control" id="username" name="username" placeholder="Username" required autofocus><label for="username">Username</label></div>
                    <div class="form-floating mt-2"><input type="password" class="form-control" id="password" name="password" placeholder="Password" required><label for="password">Password</label></div>
                    <button class="w-100 btn btn-lg btn-primary mt-3" type="submit">Sign in</button>
                    <div class="progress mt-3" id="login-progress" style="display: none;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <p class="mt-5 mb-3 text-muted text-center">&copy; <?= date('Y') ?> Assistindo</p>
                </form>
        </div>
    </main>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.getElementById('login-form');
    if (!loginForm) return;

    const submitButton = loginForm.querySelector('button[type="submit"]');
    const progressBarContainer = document.getElementById('login-progress');
    const progressBar = progressBarContainer.querySelector('.progress-bar');
    const errorContainer = document.getElementById('error-container');

    loginForm.addEventListener('submit', function(e) {
        e.preventDefault();

        // Hide previous error
        errorContainer.innerHTML = '';

        // Disable button and show progress bar
        const originalButtonText = submitButton.innerHTML;
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Logging in...';
        progressBarContainer.style.display = 'block';
        progressBar.style.width = '0%';
        progressBar.setAttribute('aria-valuenow', 0);

        // Animate progress bar
        let progress = 0;
        const interval = setInterval(() => {
            progress += 25;
            progressBar.style.width = progress + '%';
            progressBar.setAttribute('aria-valuenow', progress);
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
                progressBar.setAttribute('aria-valuenow', 100);
                setTimeout(() => { window.location.href = data.redirect; }, 400);
            } else {
                throw new Error(data.message || 'An unknown error occurred.');
            }
        })
        .catch(error => {
            clearInterval(interval);
            progressBarContainer.style.display = 'none';
            errorContainer.innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonText;
        });
    });
});
</script>
</body>
</html>