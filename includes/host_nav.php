<?php
$host_id = $host['id'] ?? 0;
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2"><i class="bi bi-hdd-network-fill"></i> <?= htmlspecialchars($host['name']) ?></h1>
        <p class="text-muted mb-0">Managing host at <code><?= htmlspecialchars($host['docker_api_url']) ?></code></p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="<?= base_url('/hosts') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to All Hosts
        </a>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= ($active_page === 'dashboard') ? 'active' : '' ?>" href="<?= base_url('/hosts/' . $host_id . '/details') ?>">Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($active_page === 'stacks') ? 'active' : '' ?>" href="<?= base_url('/hosts/' . $host_id . '/stacks') ?>">Stacks</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($active_page === 'containers') ? 'active' : '' ?>" href="<?= base_url('/hosts/' . $host_id . '/containers') ?>">Containers</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($active_page === 'images') ? 'active' : '' ?>" href="<?= base_url('/hosts/' . $host_id . '/images') ?>">Images</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($active_page === 'networks') ? 'active' : '' ?>" href="<?= base_url('/hosts/' . $host_id . '/networks') ?>">Networks</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($active_page === 'volumes') ? 'active' : '' ?>" href="<?= base_url('/hosts/' . $host_id . '/volumes') ?>">Volumes</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($active_page === 'edit') ? 'active' : '' ?>" href="<?= base_url('/hosts/' . $host_id . '/edit') ?>">Settings</a>
    </li>
</ul>