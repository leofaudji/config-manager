<?php
$host_id = $host['id'] ?? 0;
require_once __DIR__ . '/DockerClient.php';
$is_swarm_node = false;
$swarm_status_text = '';
try {
    $dockerClientForNav = new DockerClient($host);
    $dockerInfoForNav = $dockerClientForNav->getInfo();
    if (isset($dockerInfoForNav['Swarm']['LocalNodeState']) && $dockerInfoForNav['Swarm']['LocalNodeState'] !== 'inactive') {
        $is_swarm_node = true;
        if (isset($dockerInfoForNav['Swarm']['ControlAvailable']) && $dockerInfoForNav['Swarm']['ControlAvailable']) {
            $swarm_status_text = 'This node is a <strong>Manager</strong> in a Swarm cluster.';
        } else {
            $swarm_status_text = 'This node is a <strong>Worker</strong> in a Swarm cluster.';
        }
    }
} catch (Exception $e) {
    // Ignore connection errors for this informational check
}
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2"><i class="bi bi-hdd-network-fill"></i> <?= htmlspecialchars($host['name']) ?></h1>
        <p class="text-muted mb-0">Managing host at <code><?= htmlspecialchars($host['docker_api_url']) ?></code></p>
        <?php if ($is_swarm_node): ?><p class="small text-info mb-0 mt-1"><i class="bi bi-info-circle-fill"></i> <?= $swarm_status_text ?> Resources shown are specific to this node only.</p><?php endif; ?>
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