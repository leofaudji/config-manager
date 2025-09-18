<?php

/**
 * This file is now responsible for loading application-specific configurations
 * from the environment and making them available as constants.
 * The bootstrap file should have already loaded the .env variables.
 */

// Define YAML_OUTPUT_PATH from environment, with a fallback.
if (!defined('YAML_OUTPUT_PATH')) {
    define('YAML_OUTPUT_PATH', Config::get('YAML_OUTPUT_PATH', __DIR__ . '/dynamic.yml'));
}

// Define TRAEFIK_API_URL from environment, with a fallback.
if (!defined('TRAEFIK_API_URL')) {
    define('TRAEFIK_API_URL', Config::get('TRAEFIK_API_URL', 'http://localhost:8080'));
}