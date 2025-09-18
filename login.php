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
        html, body {
            height: 100%;
        }
        body {
            display: flex;
            align-items: center;
            padding-top: 40px;
            padding-bottom: 40px;
        }
        .form-signin {
            width: 100%;
            max-width: 500px; /* Increased width by ~50% from original */
            padding: 15px;
            margin: auto;
        }
        #login-progress {
            height: 10px;
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
    <main class="form-signin">
            <div class="card shadow-lg">
                <div class="card-body p-5">
                <form action="<?= base_url('/login') ?>" method="POST" id="login-form">
                    <h1 class="h3 mb-3 fw-normal"><i class="bi bi-gear-wide-connected"></i> Config Manager</h1>
                    <h2 class="h5 mb-3 fw-normal">Please sign in</h2>
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
                    <p class="mt-5 mb-3 text-muted">&copy; <?= date('Y') ?></p>
                </form>
            </div>
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