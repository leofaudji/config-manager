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
     * @return int|null The Process ID (PID) of the background worker, or null on failure.
     * @throws Exception if forking fails or the pcntl extension is not available.
     */
    public static function runInBackground(array $post_data): ?int
    {
        // --- REFACTORED: Use shell_exec for a more reliable and universal background process ---
        // This approach avoids dependencies on the 'pcntl' extension and is more suitable for web environments.

        // 1. Serialize the deployment data to pass it to the background script.
        // Using base64 encoding is a safe way to pass complex data as a command-line argument.
        $encoded_data = base64_encode(json_encode($post_data));

        // 2. Define the path to the PHP executable and the worker script.
        $php_executable = PHP_BINARY; // A reliable constant for the current PHP executable path.
        $worker_script = PROJECT_ROOT . '/jobs/deployment_worker.php';

        // 3. Build the shell command.
        //    - We pass the encoded data as an argument. 
        //    - We redirect stdout and stderr to the log file specified in the post_data.
        //    - The final '&' runs the process in the background.
        $log_file_path = $post_data['log_file_path'] ?? '/dev/null'; // Default to /dev/null if not provided
        

        // --- NEW: Get PID of the background process ---
        // We wrap the command in curly braces and use `echo $!` to get the PID of the backgrounded process.
        // The output of this command will be the PID.
        $command = $php_executable . ' ' . escapeshellarg($worker_script) . ' ' . escapeshellarg($encoded_data) 
                 . ' > ' . escapeshellarg($log_file_path) . ' 2>&1 &';
        $command_with_pid = '{ ' . $command . ' echo $!; }';

        // `exec` is used here instead of `shell_exec` because we need to capture the output (the PID).
        $pid = (int)exec($command_with_pid);

        return $pid > 0 ? $pid : null; 
    }
}