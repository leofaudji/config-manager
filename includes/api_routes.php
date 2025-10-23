<?php
// File: /includes/api_routes.php

/** @var Router $router */

// --- API Routes ---

// Endpoint untuk mendapatkan status dinamis untuk sidebar/UI.
$router->get('/api/sidebar/status', 'api/sidebar/status.php', ['auth']);

// Endpoint untuk pencarian global
$router->get('/api/search', 'api/global_search_handler.php', ['auth']);

// Endpoint untuk membersihkan cache pencarian
$router->post('/api/system/clear-cache', 'api/system_cache_clear_handler.php', ['auth']);

// Tambahkan rute API lainnya di sini...