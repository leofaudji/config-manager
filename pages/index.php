<?php
// Traefik Manager - Front Controller

// Mulai sesi di setiap permintaan. Ini harus dilakukan sebelum output apa pun.
session_start();

// Muat komponen inti
require_once 'includes/bootstrap.php';
require_once 'includes/Router.php';

// The Router needs the base path, which is now defined as a constant in bootstrap.php
$router = new Router(BASE_PATH);

// --- Definisikan Rute (Routes) ---
// Router akan memeriksa middleware ('guest', 'auth', 'admin')
// lalu me-require file handler yang sesuai.

// Rute untuk tamu (hanya bisa diakses jika belum login)
$router->get('/login', 'login.php', ['guest']);
$router->post('/login', 'actions/auth.php'); // Aksi dari form login

// Rute yang memerlukan otentikasi
$router->get('/', 'dashboard.php', ['auth']);
$router->get('/logout', 'logout.php', ['auth']);
$router->get('/my-profile/change-password', 'pages/change_own_password_form.php', ['auth']); // Form ganti password sendiri

// Rute yang memerlukan akses admin
$router->get('/generate', 'generate_config.php', ['auth', 'admin']);
$router->get('/users', 'pages/user_management.php', ['auth', 'admin']);
$router->get('/users/new', 'pages/user_form.php', ['auth', 'admin']);
$router->get('/users/{id}/edit', 'pages/user_form.php', ['auth', 'admin']);
$router->get('/users/{id}/change-password', 'pages/change_password_form.php', ['auth', 'admin']); // Form ganti password user lain

$router->get('/configurations/new', 'pages/router_form.php', ['auth', 'admin']);
$router->get('/routers', 'pages/router_management.php', ['auth', 'admin']);
$router->get('/history', 'pages/config_history.php', ['auth', 'admin']);
$router->get('/history/compare', 'pages/compare_history.php', ['auth', 'admin']);
$router->get('/history/cleanup', 'pages/cleanup_history.php', ['auth', 'admin']);
$router->get('/logs', 'pages/activity_log.php', ['auth', 'admin']);
$router->get('/stats', 'pages/stats.php', ['auth', 'admin']);
$router->get('/groups', 'pages/group_management.php', ['auth', 'admin']);
$router->get('/middlewares', 'pages/middleware_management.php', ['auth', 'admin']);
$router->get('/services', 'pages/service_management.php', ['auth', 'admin']);
$router->get('/settings', 'pages/settings.php', ['auth', 'admin']);
$router->get('/health-check', 'pages/health_check.php', ['auth', 'admin']);
$router->get('/health-status', 'pages/health_status_dashboard.php', ['auth']);
$router->get('/changelog', 'pages/changelog.php', ['auth']);
$router->get('/health-agent-workflow', 'pages/health_agent_workflow.php', ['auth', 'admin']); // Rute baru untuk diagram
$router->get('/cpu-reader-workflow', 'pages/cpu_reader_workflow.php', ['auth', 'admin']); // Rute baru untuk diagram CPU Reader
$router->get('/traefik-workflow', 'pages/traefik_workflow.php', ['auth', 'admin']);
$router->get('/container-management-workflow', 'pages/container_management_workflow.php', ['auth', 'admin']);
$router->get('/traffic-flow-workflow', 'pages/traffic_flow_workflow.php', ['auth', 'admin']);
$router->get('/cron-jobs', 'pages/cron_management.php', ['auth', 'admin']);
$router->get('/templates', 'pages/template_management.php', ['auth', 'admin']);
$router->get('/traefik-hosts', 'pages/traefik_host_management.php', ['auth', 'admin']);
$router->get('/app-launcher', 'pages/app_launcher.php', ['auth', 'admin']);
$router->get('/hosts', 'pages/host_management.php', ['auth', 'admin']);
$router->get('/swarm/details', 'pages/swarm_details.php', ['auth', 'admin']);

// Rute untuk form edit
$router->get('/routers/new', 'pages/router_form.php', ['auth', 'admin']);
$router->get('/routers/{id}/edit', 'pages/router_form.php', ['auth', 'admin']);
$router->get('/routers/{clone_id}/clone', 'pages/router_form.php', ['auth', 'admin']);
$router->get('/services/new', 'pages/service_form.php', ['auth', 'admin']);
$router->get('/services/{id}/edit', 'pages/service_form.php', ['auth', 'admin']);
$router->get('/services/{clone_id}/clone', 'pages/service_form.php', ['auth', 'admin']);
$router->get('/servers/new', 'pages/server_form.php', ['auth', 'admin']); // Form tambah server ke service
$router->get('/servers/{id}/edit', 'pages/server_form.php', ['auth', 'admin']);
$router->get('/stack-changes', 'pages/stack_changes.php', ['auth', 'admin']);
$router->get('/hosts/new', 'pages/host_form.php', ['auth', 'admin']);
$router->get('/hosts/{id}/edit', 'pages/host_form.php', ['auth', 'admin']);
$router->get('/hosts/{clone_id}/clone', 'pages/host_form.php', ['auth', 'admin']);
$router->get('/hosts/{id}/details', 'pages/host_details.php', ['auth', 'admin']);
$router->get('/hosts/{id}/containers', 'pages/host_containers.php', ['auth', 'admin']);
$router->get('/hosts/{id}/stacks', 'pages/host_stacks.php', ['auth', 'admin']);
$router->get('/hosts/{id}/stacks/new', 'pages/stack_form.php', ['auth', 'admin']);
$router->get('/hosts/{id}/stacks/{stack_db_id}/edit', 'pages/stack_form.php', ['auth', 'admin']);
$router->get('/hosts/{id}/stacks/{stack_db_id}/update', 'pages/app_updater.php', ['auth', 'admin']);
$router->get('/hosts/{id}/deploy/git', 'pages/host_deploy_git.php', ['auth', 'admin']);
$router->get('/hosts/{id}/networks', 'pages/host_networks.php', ['auth', 'admin']);
$router->get('/hosts/{id}/images', 'pages/host_images.php', ['auth', 'admin']);
$router->get('/hosts/{id}/volumes', 'pages/host_volumes.php', ['auth', 'admin']);

// --- API & Action Routes (untuk form submissions dan AJAX) ---

$router->get('/api/data', 'get_data.php', ['auth']);
$router->get('/api/logs', 'api/activity_log_handler.php', ['auth', 'admin']);
$router->get('/api/stats', 'api/stats_handler.php', ['auth', 'admin']);
$router->get('/api/dashboard-stats', 'api/dashboard_stats_handler.php', ['auth']);
$router->get('/api/configurations/preview', 'api/preview_handler.php', ['auth', 'admin']);
$router->get('/api/health-check', 'api/health_check_handler.php', ['auth', 'admin']);
$router->get('/api/swarm/nodes', 'api/swarm_nodes_handler.php', ['auth', 'admin']);
$router->get('/api/health-status', 'api/health_status_handler.php', ['auth']);
$router->get('/api/templates/{id}', 'api/template_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/{id}/containers', 'api/host_detail_handler.php', ['auth', 'admin']);
$router->get('/api/cron', 'api/cron_handler.php', ['auth', 'admin']);
$router->get('/api/cron/log', 'api/cron_log_handler.php', ['auth', 'admin']);
$router->post('/api/cron', 'api/cron_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/{id}/stacks', 'api/host_stack_handler.php', ['auth', 'admin']);
$router->post('/api/hosts/{id}/stacks', 'api/host_stack_handler.php', ['auth', 'admin']);
$router->get('/api/stack-changes', 'api/stack_changes_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/{id}/containers/{container_id}/stats', 'api/container_stats_stream_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/{host_id}/stacks/{stack_name}/spec', 'api/host_stack_handler.php', ['auth', 'admin']);
$router->post('/api/git/test', 'api/git_test_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/{host_id}/stacks/{stack_name}/details', 'api/host_stack_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/{host_id}/tasks/{task_id}/logs', 'api/task_log_handler.php', ['auth', 'admin']);
$router->post('/api/stacks/sync-to-git', 'api/sync_stacks_to_git_handler.php', ['auth', 'admin']);
$router->get('/api/stacks/check-git-diff', 'api/check_git_diff_handler.php', ['auth', 'admin']);
$router->post('/api/git/test-compose-path', 'api/git_compose_test_handler.php', ['auth', 'admin']);
$router->post('/api/app-launcher/deploy', 'api/app_launcher_handler.php', ['auth', 'admin']);
$router->post('/api/app-launcher/preview', 'api/app_launcher_preview_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/{id}/stats', 'api/host_dashboard_stats_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/{id}/helper/{path}', 'api/host_agent_handler.php', ['auth', 'admin']); // Untuk agent-status
$router->get('/api/hosts/{id}/deployment-logs', 'api/host_deployment_log_handler.php', ['auth', 'admin']);
$router->post('/api/hosts/{id}/helper/{path}', 'api/host_agent_handler.php', ['auth', 'admin']); // Untuk agent-action
$router->get('/api/hosts/{id}/networks', 'api/network_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/{id}/images', 'api/network_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/{id}/volumes', 'api/network_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/{id}/volumes/{name}', 'api/network_handler.php', ['auth', 'admin']);
$router->post('/api/hosts/{id}/containers/prune', 'api/network_handler.php', ['auth', 'admin']);
$router->post('/api/hosts/{id}/volumes', 'api/network_handler.php', ['auth', 'admin']);
$router->post('/api/hosts/{id}/volumes/prune', 'api/network_handler.php', ['auth', 'admin']);
$router->post('/api/hosts/{id}/images', 'api/network_handler.php', ['auth', 'admin']);
$router->post('/api/hosts/{id}/images/prune', 'api/network_handler.php', ['auth', 'admin']);
$router->post('/api/hosts/{id}/networks', 'api/network_handler.php', ['auth', 'admin']);
$router->post('/api/hosts/{id}/networks/prune', 'api/network_handler.php', ['auth', 'admin']);
$router->get('/api/dockerhub/search', 'api/dockerhub_handler.php', ['auth', 'admin']);
$router->get('/api/dockerhub/tags', 'api/dockerhub_tags_handler.php', ['auth', 'admin']);
$router->post('/api/hosts/{id}/containers/{container_id}/check-update', 'api/container_update_check_handler.php', ['auth', 'admin']);
$router->post('/api/webhook/deploy', 'api/webhook_handler.php'); // No auth middleware, security is via token
$router->post('/api/webhook/regenerate-token', 'api/webhook_token_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/{id}/containers/{container_id}/logs', 'api/container_log_handler.php', ['auth', 'admin']);
$router->post('/api/health/report', 'api/health_report_handler.php'); // For health agent callbacks
$router->post('/api/log/ingest', 'api/log_ingest_handler.php'); // For receiving logs from agents
$router->post('/api/history/cleanup', 'api/cleanup_handler.php', ['auth', 'admin']);
$router->post('/api/routers/bulk-move', 'api/router_bulk_handler.php', ['auth', 'admin']);
$router->post('/api/routers/bulk-delete', 'api/router_bulk_handler.php', ['auth', 'admin']);
$router->get('/api/services/status', 'actions/get_service_status.php', ['auth']);

$router->post('/users/new', 'api/user_handler.php', ['auth', 'admin']);
$router->post('/users/{id}/edit', 'api/user_handler.php', ['auth', 'admin']);
$router->post('/users/{id}/delete', 'api/user_handler.php', ['auth', 'admin']);
$router->post('/users/{id}/change-password', 'api/user_handler.php', ['auth', 'admin']);
$router->post('/my-profile/change-password', 'api/profile_handler.php', ['auth']);
$router->post('/configurations/new', 'api/router_handler.php', ['auth', 'admin']);
$router->post('/routers/new', 'api/router_handler.php', ['auth', 'admin']);
$router->post('/routers/{id}/edit', 'api/router_handler.php', ['auth', 'admin']);
$router->post('/routers/{id}/delete', 'api/router_handler.php', ['auth', 'admin']);
$router->post('/services/new', 'api/service_handler.php', ['auth', 'admin']);
$router->post('/services/{id}/edit', 'api/service_handler.php', ['auth', 'admin']);
$router->post('/services/{id}/delete', 'api/service_handler.php', ['auth', 'admin']);
$router->post('/servers/new', 'api/server_handler.php', ['auth', 'admin']);
$router->post('/servers/{id}/edit', 'api/server_handler.php', ['auth', 'admin']);
$router->post('/servers/{id}/delete', 'api/server_handler.php', ['auth', 'admin']);
$router->post('/groups/new', 'api/group_handler.php', ['auth', 'admin']);
$router->post('/groups/{id}/edit', 'api/group_handler.php', ['auth', 'admin']);
$router->post('/groups/{id}/delete', 'api/group_handler.php', ['auth', 'admin']);
$router->post('/middlewares/new', 'api/middleware_handler.php', ['auth', 'admin']);
$router->post('/api/hosts/{id}/containers/{container_id}/{action}', 'api/container_action_handler.php', ['auth', 'admin']);
$router->post('/middlewares/{id}/edit', 'api/middleware_handler.php', ['auth', 'admin']);
$router->post('/middlewares/{id}/delete', 'api/middleware_handler.php', ['auth', 'admin']);
$router->get('/api/hosts/list', 'api/host_list_handler.php', ['auth', 'admin']);
$router->post('/api/swarm/join', 'api/join_swarm_handler.php', ['auth', 'admin']);
$router->post('/api/nodes/action', 'api/node_action_handler.php', ['auth', 'admin']);
$router->post('/templates/new', 'api/template_handler.php', ['auth', 'admin']);
$router->post('/templates/{id}/edit', 'api/template_handler.php', ['auth', 'admin']);
$router->post('/hosts/new', 'api/host_handler.php', ['auth', 'admin']);
$router->post('/hosts/{id}/edit', 'api/host_handler.php', ['auth', 'admin']);
$router->post('/hosts/{id}/test', 'api/host_test_connection.php', ['auth', 'admin']);
$router->post('/hosts/{id}/delete', 'api/host_handler.php', ['auth', 'admin']);
$router->post('/traefik-hosts/new', 'api/traefik_host_handler.php', ['auth', 'admin']);
$router->post('/traefik-hosts/{id}/edit', 'api/traefik_host_handler.php', ['auth', 'admin']);
$router->post('/traefik-hosts/{id}/delete', 'api/traefik_host_handler.php', ['auth', 'admin']);
$router->post('/templates/{id}/delete', 'api/template_handler.php', ['auth', 'admin']);
$router->post('/settings', 'api/settings_handler.php', ['auth', 'admin']);
$router->post('/history/{id}/deploy', 'actions/deploy_config.php', ['auth', 'admin']);
$router->get('/history/{id}/content', 'actions/get_history_content.php', ['auth', 'admin']);
$router->get('/history/{id}/download', 'actions/download_history.php', ['auth', 'admin']);
$router->post('/history/{id}/archive', 'actions/archive_history.php', ['auth', 'admin']);

// Jalankan router
$router->dispatch();