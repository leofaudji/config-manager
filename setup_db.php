<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
<div class="container mt-5">
    <div class="card shadow-sm">
        <div class="card-header bg-dark text-white">
            <h3><i class="bi bi-database-fill-gear"></i> Config Manager - Database Setup</h3>
        </div>
        <div class="card-body">
            <ul class="list-group">
<?php

function log_message($message, $is_success = true, $details = '') {
    $status_class = $is_success ? 'success' : 'danger';
    $icon = $is_success ? 'check-circle-fill' : 'x-circle-fill';
    echo "<li class=\"list-group-item d-flex justify-content-between align-items-center\">{$message} <span class=\"text-{$status_class}\"><i class=\"bi bi-{$icon}\"></i></span></li>";
}

function log_error_and_die($message, $error_details) {
    log_message($message, false);
    echo '</ul></div><div class="card-footer"><div class="alert alert-danger mb-0"><strong>Detail Error:</strong> ' . htmlspecialchars($error_details) . '</div></div></div></div></body></html>';
    die();
}

// --- Database Configuration ---
require_once 'includes/Config.php';
try {
    Config::load(__DIR__ . '/.env');
} catch (\Exception $e) {
    log_error_and_die('Gagal memuat file .env', 'Pastikan file .env ada di direktori root dan dapat dibaca. Error: ' . $e->getMessage());
}

$db_server = Config::get('DB_SERVER');
$db_username = Config::get('DB_USERNAME');
$db_password = Config::get('DB_PASSWORD');
$db_name = Config::get('DB_NAME');

// --- SQL Statements ---
$default_password_hash = password_hash('password', PASSWORD_DEFAULT);
$webhook_token = bin2hex(random_bytes(32)); // Generate a secure random token

$sql = "
DROP TABLE IF EXISTS `activity_log`, `config_history`, `router_middleware`, `servers`, `routers`, `middlewares`, `transports`, `container_health_status`, `service_health_status`, `services`, `groups`, `users`, `settings`, `configuration_templates`, `stack_change_log`, `application_stacks`, `docker_hosts`, `host_stats_history`,`traefik_hosts`, `container_stats`,`container_health_history`;

CREATE TABLE `stack_change_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host_id` int(11) NOT NULL,
  `stack_name` varchar(255) NOT NULL,
  `change_type` enum('created','updated','deleted') NOT NULL,
  `details` text DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT NULL,
  `changed_by` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `host_id_created_at` (`host_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','viewer') NOT NULL DEFAULT 'viewer',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `traefik_host_id` int(11) DEFAULT NULL COMMENT 'The Traefik host this group belongs to, if any.',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `traefik_host_id` (`traefik_host_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `docker_hosts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `docker_api_url` varchar(255) NOT NULL COMMENT 'e.g., tcp://192.168.1.100:2375',
  `description` text DEFAULT NULL,
  `tls_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `ca_cert_path` varchar(255) DEFAULT NULL,
  `client_cert_path` varchar(255) DEFAULT NULL,
  `client_key_path` varchar(255) DEFAULT NULL,
  `default_volume_path` varchar(255) DEFAULT NULL COMMENT 'Base path for application volumes on this host',
  `default_health_check_command` varchar(255) DEFAULT NULL COMMENT 'Fallback command to run inside containers for health checks',
  `registry_url` varchar(255) DEFAULT NULL,
  `registry_username` varchar(255) DEFAULT NULL,
  `registry_password` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_cpu_report_at` timestamp NULL DEFAULT NULL,
  `last_report_at` timestamp NULL DEFAULT NULL,
  `agent_status` varchar(20) DEFAULT 'Unknown',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `traefik_hosts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `host_stats_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host_id` int(11) NOT NULL,
  `cpu_usage_percent` decimal(5,2) NOT NULL,
  `memory_usage_bytes` bigint(20) NOT NULL,
  `memory_limit_bytes` bigint(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `host_id_created_at` (`host_id`,`created_at`),
  CONSTRAINT `host_stats_history_ibfk_1` FOREIGN KEY (`host_id`) REFERENCES `docker_hosts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `container_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host_id` int(11) NOT NULL,
  `container_id` varchar(64) NOT NULL,
  `container_name` varchar(255) NOT NULL,
  `cpu_usage` decimal(5,2) NOT NULL,
  `memory_usage` bigint(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `host_id_container_id_created_at` (`host_id`,`container_id`,`created_at`),
  CONSTRAINT `container_stats_ibfk_1` FOREIGN KEY (`host_id`) REFERENCES `docker_hosts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `application_stacks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host_id` int(11) NOT NULL,
  `stack_name` varchar(255) NOT NULL,
  `source_type` enum('git','image','hub','builder','editor') NOT NULL,
  `compose_file_path` varchar(255) NOT NULL,
  `deployment_details` text DEFAULT NULL COMMENT 'JSON-encoded POST data from original deployment',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `host_id_stack_name` (`host_id`,`stack_name`),
  CONSTRAINT `application_stacks_ibfk_1` FOREIGN KEY (`host_id`) REFERENCES `docker_hosts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stack_change_log_ibfk_1` FOREIGN KEY (`host_id`) REFERENCES `docker_hosts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `configuration_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `config_data` text NOT NULL COMMENT 'JSON containing template settings',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `pass_host_header` tinyint(1) NOT NULL DEFAULT 1,
  `load_balancer_method` varchar(50) NOT NULL DEFAULT 'roundRobin',
  `description` text DEFAULT NULL,
  `health_check_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `health_check_type` enum('http','docker','command','tcp') NOT NULL DEFAULT 'http',
  `target_stack_id` int(11) DEFAULT NULL,
  `health_check_endpoint` varchar(255) DEFAULT NULL,
  `health_check_interval` int(11) NOT NULL DEFAULT 30 COMMENT 'in seconds',
  `health_check_timeout` int(11) NOT NULL DEFAULT 5 COMMENT 'in seconds',
  `unhealthy_threshold` int(11) NOT NULL DEFAULT 3 COMMENT 'failures to be marked unhealthy',
  `healthy_threshold` int(11) NOT NULL DEFAULT 2 COMMENT 'successes to be marked healthy',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `fk_target_stack` (`target_stack_id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `service_health_status` (
  `service_id` int(11) NOT NULL,
  `status` enum('healthy','unhealthy','unknown') NOT NULL DEFAULT 'unknown',
  `consecutive_failures` int(11) NOT NULL DEFAULT 0,
  `consecutive_successes` int(11) NOT NULL DEFAULT 0,
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `last_log` text DEFAULT NULL,
  PRIMARY KEY (`service_id`),
  KEY `status` (`status`),
  CONSTRAINT `service_health_status_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `container_health_status` (
  `container_id` varchar(64) NOT NULL,
  `host_id` int(11) NOT NULL,
  `container_name` varchar(255) DEFAULT NULL,
  `status` enum('healthy','unhealthy','unknown') NOT NULL DEFAULT 'unknown',
  `consecutive_failures` int(11) NOT NULL DEFAULT 0,
  `consecutive_successes` int(11) NOT NULL DEFAULT 0,
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `last_log` text DEFAULT NULL,
  PRIMARY KEY (`container_id`),
  KEY `host_id` (`host_id`),
  CONSTRAINT `container_health_status_ibfk_1` FOREIGN KEY (`host_id`) REFERENCES `docker_hosts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `routers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `rule` varchar(255) NOT NULL,
  `entry_points` varchar(100) NOT NULL DEFAULT 'web',
  `service_name` varchar(100) NOT NULL,
  `tls` tinyint(1) NOT NULL DEFAULT 0,
  `cert_resolver` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `group_id` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),  
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `group_id` (`group_id`),
  FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_id` int(11) NOT NULL,
  `url` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `service_id` (`service_id`),
  FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `middlewares` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` varchar(50) NOT NULL COMMENT 'e.g., headers, rateLimit, basicAuth',
  `config_json` text NOT NULL COMMENT 'Middleware parameters as JSON',
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `router_middleware` (
  `router_id` int(11) NOT NULL,
  `middleware_id` int(11) NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 10,
  PRIMARY KEY (`router_id`,`middleware_id`),
  KEY `middleware_id` (`middleware_id`),
  FOREIGN KEY (`router_id`) REFERENCES `routers` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`middleware_id`) REFERENCES `middlewares` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `transports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `insecure_skip_verify` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `config_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `yaml_content` text NOT NULL,
  `generated_by` varchar(100) DEFAULT 'system',
  `status` enum('draft','active','archived') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `host_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `host_id` (`host_id`),
  KEY `username` (`username`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`host_id`) REFERENCES `docker_hosts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('default_group_id', '1'),
('history_cleanup_days', '30'),
('cron_log_retention_days', '7'),
('host_stats_history_cleanup_days', '7'),
('default_compose_path', '/var/www/html/compose-files'),
('yaml_output_path', '/var/www/html/config-manager/traefik-configs'),
('default_git_compose_path', 'docker-compose.yml'),
('active_traefik_host_id', '1'),
('webhook_secret_token', '{$webhook_token}'),
('auto_deploy_enabled', '1');

-- Default user: admin, password: password, role: admin
INSERT INTO `users` (`username`, `password`, `role`) VALUES ('admin', '{$default_password_hash}', 'admin');

-- Default group
INSERT INTO `groups` (`name`) VALUES ('General');

-- Default Traefik Host
INSERT INTO `traefik_hosts` (`id`, `name`, `description`) VALUES (1, 'Global', 'Configurations that apply to all hosts.');

-- Initial Data based on dynamic.yml
INSERT INTO `services` (`name`, `pass_host_header`) VALUES ('service-api-car', 1);
SET @svc_id = LAST_INSERT_ID();
INSERT INTO `routers` (`name`, `rule`, `entry_points`, `service_name`) VALUES ('router-api-car', 'Host(`car-api.sis1.dev`)', 'web', 'service-api-car');
INSERT INTO `servers` (`service_id`, `url`) VALUES (@svc_id, 'http://10.2.2.169:8085');

INSERT INTO `services` (`name`, `pass_host_header`) VALUES ('service-api-log', 1);
SET @svc_id = LAST_INSERT_ID();
INSERT INTO `routers` (`name`, `rule`, `entry_points`, `service_name`) VALUES ('router-api-log', 'Host(`api-log.sis1.dev`)', 'web', 'service-api-log');
INSERT INTO `servers` (`service_id`, `url`) VALUES (@svc_id, 'http://10.2.2.169:3000');

INSERT INTO `services` (`name`, `pass_host_header`) VALUES ('service-api-token', 1);
SET @svc_id = LAST_INSERT_ID();
INSERT INTO `routers` (`name`, `rule`, `entry_points`, `service_name`) VALUES ('router-api-token', 'Host(`get-token.sis1.dev`)', 'web', 'service-api-token');
INSERT INTO `servers` (`service_id`, `url`) VALUES (@svc_id, 'http://10.2.2.169:4000');

INSERT INTO `services` (`name`, `pass_host_header`) VALUES ('service-demo', 1);
SET @svc_id = LAST_INSERT_ID();
INSERT INTO `routers` (`name`, `rule`, `entry_points`, `service_name`) VALUES ('router-demo', 'Host(`demo.sis1.dev`)', 'web', 'service-demo');
INSERT INTO `servers` (`service_id`, `url`) VALUES (@svc_id, 'http://10.1.3.121:80');

INSERT INTO `services` (`name`, `pass_host_header`) VALUES ('service-flutter', 1);
SET @svc_id = LAST_INSERT_ID();
INSERT INTO `routers` (`name`, `rule`, `entry_points`, `service_name`) VALUES ('router-flutter', 'Host(`flutter.sis1.dev`)', 'web', 'service-flutter');
INSERT INTO `servers` (`service_id`, `url`) VALUES (@svc_id, 'http://10.2.3.122:80');

INSERT INTO `services` (`name`, `pass_host_header`) VALUES ('service-ibank-sejati', 1);
SET @svc_id = LAST_INSERT_ID();
INSERT INTO `routers` (`name`, `rule`, `entry_points`, `service_name`) VALUES ('router-ibank-sejati', 'Host(`ibank-sejati.sis1.dev`)', 'web', 'service-ibank-sejati');
INSERT INTO `servers` (`service_id`, `url`) VALUES (@svc_id, 'http://10.1.2.194');

INSERT INTO `services` (`name`, `pass_host_header`) VALUES ('service-minio', 1);
SET @svc_id = LAST_INSERT_ID();
INSERT INTO `routers` (`name`, `rule`, `entry_points`, `service_name`) VALUES ('router-minio-storage', 'Host(`miniostorage.sis1.dev`)', 'web', 'service-minio');
INSERT INTO `servers` (`service_id`, `url`) VALUES (@svc_id, 'http://10.1.7.74:9001');

INSERT INTO `services` (`name`, `pass_host_header`) VALUES ('service-minio-api', 1);
SET @svc_id = LAST_INSERT_ID();
INSERT INTO `routers` (`name`, `rule`, `entry_points`, `service_name`) VALUES ('router-minio-api', 'Host(`minioapi.sis1.dev`)', 'web', 'service-minio-api');
INSERT INTO `servers` (`service_id`, `url`) VALUES (@svc_id, 'http://10.1.7.74:9000');

INSERT INTO `services` (`name`, `pass_host_header`) VALUES ('service-nbs', 1);
SET @svc_id = LAST_INSERT_ID();
INSERT INTO `routers` (`name`, `rule`, `entry_points`, `service_name`) VALUES ('router-nbs', 'Host(`nbs.sis1.dev`)', 'web', 'service-nbs');
INSERT INTO `servers` (`service_id`, `url`) VALUES (@svc_id, 'http://10.1.8.66:80');

INSERT INTO `services` (`name`, `pass_host_header`) VALUES ('service-test', 1);
SET @svc_id = LAST_INSERT_ID();
INSERT INTO `routers` (`name`, `rule`, `entry_points`, `service_name`) VALUES ('router-test', 'Host(`test.sis1.dev`)', 'web', 'service-test');
INSERT INTO `servers` (`service_id`, `url`) VALUES (@svc_id, 'http://10.1.12.164:80'), (@svc_id, 'http://10.1.12.165:80'), (@svc_id, 'http://10.1.12.166:80'), (@svc_id, 'http://10.1.12.168:80'), (@svc_id, 'http://10.1.12.170:80'), (@svc_id, 'http://10.1.12.171:80'), (@svc_id, 'http://10.1.12.172:80');

INSERT INTO `services` (`name`, `pass_host_header`) VALUES ('service-test2', 1);
SET @svc_id = LAST_INSERT_ID();
INSERT INTO `routers` (`name`, `rule`, `entry_points`, `service_name`) VALUES ('router-test2', 'Host(`test2.sis1.dev`)', 'web', 'service-test2');
INSERT INTO `servers` (`service_id`, `url`) VALUES (@svc_id, 'http://10.2.2.2:80'), (@svc_id, 'http://10.2.2.22:80');

INSERT INTO `services` (`name`, `pass_host_header`) VALUES ('service-test3', 1);
SET @svc_id = LAST_INSERT_ID();
INSERT INTO `routers` (`name`, `rule`, `entry_points`, `service_name`) VALUES ('router-test33', 'Host(`test3.sis1.dev`)', 'web', 'service-test3');
INSERT INTO `servers` (`service_id`, `url`) VALUES (@svc_id, 'http://10.10.10.10:80');

-- New Default Data
INSERT INTO `docker_hosts` (`id`, `name`, `docker_api_url`, `description`, `tls_enabled`, `default_volume_path`) VALUES (1, 'PC-Faudji', 'tcp://10.2.2.2:2375', 'Local Docker socket managed by the application server.', 0, '/opt/stacks');
INSERT INTO `docker_hosts` (`id`, `name`, `docker_api_url`, `description`, `tls_enabled`, `default_volume_path`) VALUES (2, 'PC-Rizal', 'tcp://10.2.2.22:2375', 'Local Docker socket managed by the application server.', 0, '/opt/stacks');

INSERT INTO `transports` (`name`, `insecure_skip_verify`) VALUES ('dsm-transport', 1);

ALTER TABLE `groups` ADD CONSTRAINT `groups_ibfk_1` FOREIGN KEY (`traefik_host_id`) REFERENCES `traefik_hosts` (`id`) ON DELETE SET NULL;

ALTER TABLE `services` ADD CONSTRAINT `fk_target_stack` FOREIGN KEY (`target_stack_id`) REFERENCES `application_stacks` (`id`) ON DELETE SET NULL;

ALTER TABLE `application_stacks`
ADD COLUMN `autoscaling_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = disabled, 1 = enabled' AFTER `deployment_details`,
ADD COLUMN `autoscaling_min_replicas` INT NULL DEFAULT 1 AFTER `autoscaling_enabled`,
ADD COLUMN `autoscaling_max_replicas` INT NULL DEFAULT 1 AFTER `autoscaling_min_replicas`,
ADD COLUMN `autoscaling_cpu_threshold_up` INT NULL DEFAULT 80 COMMENT 'Scale up if host CPU > this value' AFTER `autoscaling_max_replicas`,
ADD COLUMN `autoscaling_cpu_threshold_down` INT NULL DEFAULT 20 COMMENT 'Scale down if host CPU < this value' AFTER `autoscaling_cpu_threshold_up`;

ALTER TABLE `host_stats_history` 
CHANGE COLUMN `cpu_usage_percent` `container_cpu_usage_percent` DECIMAL(5,2) NOT NULL COMMENT 'Aggregated CPU usage of all containers relative to total host cores (can be > 100%)',
ADD COLUMN `host_cpu_usage_percent` DECIMAL(5,2) NULL COMMENT 'Overall host CPU usage (0-100%)' AFTER `container_cpu_usage_percent`;

ALTER TABLE `docker_hosts` 
ADD COLUMN `swarm_manager_id` INT(11) NULL DEFAULT NULL AFTER `registry_password`,
ADD CONSTRAINT `fk_swarm_manager` FOREIGN KEY (`swarm_manager_id`) REFERENCES `docker_hosts` (`id`) ON DELETE SET NULL;

ALTER TABLE `application_stacks`
ADD COLUMN `deploy_placement_constraint` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Placement constraint for swarm deployment (e.g., node.role==worker)' AFTER `autoscaling_cpu_threshold_down`;

ALTER TABLE `docker_hosts` ADD `swarm_status` VARCHAR(20) NULL DEFAULT 'unreachable' AFTER `description`;

ALTER TABLE `docker_hosts` ADD `host_uptime_seconds` BIGINT NULL DEFAULT NULL COMMENT 'Host uptime in seconds, reported by the agent.' AFTER `swarm_status`;

ALTER TABLE `application_stacks` ADD `last_webhook_triggered_at` DATETIME NULL DEFAULT NULL;

ALTER TABLE `config_history`
ADD COLUMN `group_id` INT(11) NULL DEFAULT NULL AFTER `status`,
ADD CONSTRAINT `fk_config_history_group` FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE SET NULL;

CREATE TABLE `container_health_history` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `host_id` INT NOT NULL,
  `container_id` VARCHAR(255) NOT NULL,
  `container_name` VARCHAR(255) NOT NULL,
  `status` VARCHAR(20) NOT NULL COMMENT 'e.g., healthy, unhealthy, unknown, stopped',
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME NULL,
  `duration_seconds` INT NULL,
  INDEX `idx_host_container_start` (`host_id`, `container_id`, `start_time`),
  FOREIGN KEY (`host_id`) REFERENCES `docker_hosts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


";

// --- Execution Logic ---
$conn_setup = new mysqli($db_server, $db_username, $db_password);
if ($conn_setup->connect_error) {
    log_error_and_die("Koneksi ke MySQL server Gagal", $conn_setup->connect_error);
}
log_message("Berhasil terhubung ke MySQL server.");

if ($conn_setup->query("CREATE DATABASE IF NOT EXISTS `" . $db_name . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
    log_message("Database '" . $db_name . "' berhasil dibuat atau sudah ada.");
} else {
    log_error_and_die("Error membuat database", $conn_setup->error);
}
$conn_setup->select_db($db_name);

if ($conn_setup->multi_query($sql)) {
    while ($conn_setup->more_results() && $conn_setup->next_result()) {;}
    log_message("Struktur tabel dan data awal berhasil dibuat.");
} else {
    log_error_and_die("Error saat setup tabel", $conn_setup->error);
}

$conn_setup->close();

$base_path_setup = dirname($_SERVER['SCRIPT_NAME']);
$login_url = rtrim($base_path_setup, '/') . '/login';
?>
            </ul>
        </div>
        <div class="card-footer">
            <div class="alert alert-success mb-0">
                <h4 class="alert-heading">Setup Selesai!</h4>
                <p>Database telah berhasil dikonfigurasi. User default adalah <strong>admin</strong> dengan password <strong>password</strong> dan role <strong>admin</strong>.</p>
                <hr>
                <p class="mb-0"><strong>TINDAKAN PENTING:</strong> Untuk keamanan, mohon hapus file <strong>setup_db.php</strong> ini dari server Anda, lalu <a href="<?= htmlspecialchars($login_url) ?>" class="alert-link">klik di sini untuk login</a>.</p>
            </div>
        </div>
    </div>
</div>
</body>
</html>