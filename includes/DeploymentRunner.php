<?php

require_once __DIR__ . '/AppLauncherHelper.php';

class DeploymentRunner
{
    /**
     * Executes a deployment process in the background.
     *
     * This method uses pcntl_fork to create a child process that handles the
     * time-consuming deployment task. The parent process returns immediately,
     * allowing the initial request (e.g., from a webhook) to complete quickly.
     *
     * @param array $post_data The deployment parameters, typically from a form or webhook.
     * @return void
     * @throws Exception if forking fails or the pcntl extension is not available.
     */
    public static function runInBackground(array $post_data): void
    {
        if (!function_exists('pcntl_fork')) {
            // Log the error and fall back to synchronous execution if pcntl is not available.
            error_log("Config Manager Warning: 'pcntl' extension not available. Webhook deployment will run synchronously.");
            AppLauncherHelper::executeDeployment($post_data);
            return;
        }

        $pid = pcntl_fork();

        if ($pid == -1) {
            // Fork failed
            throw new Exception("Could not fork process to run deployment in the background.");
        } elseif ($pid) {
            // This is the parent process.
            // It can return immediately. The child process will continue execution.
            return;
        } else {
            // This is the child process.
            // It will execute the long-running deployment task.

            // --- FIX: Re-establish database connection in the child process ---
            // The child process inherits the parent's DB connection, which is often stale or invalid.
            Database::getInstance()->reconnect();

            try {
                AppLauncherHelper::executeDeployment($post_data);
            } finally {
                // Ensure the log file is always closed before the child process exits.
                AppLauncherHelper::closeLogFile();
            }
            // Important: The child process must exit to not continue the parent's script execution.
            exit();
        }
    }
}