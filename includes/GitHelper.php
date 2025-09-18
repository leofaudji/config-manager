<?php

class GitHelper
{
    private ?string $repoUrl;
    private string $branch;
    private ?string $sshKeyPath;
    private string $userName;
    private ?string $personalAccessToken;
    private string $userEmail;
    private string $tempPath;
    private string $gitSshCommand;
    private ?string $persistentRepoPath;

    public function __construct()
    {
        $this->repoUrl = get_setting('git_repository_url');
        $this->branch = get_setting('git_branch', 'main');
        $this->sshKeyPath = get_setting('git_ssh_key_path');
        $this->userName = get_setting('git_user_name', 'Config Manager');
        $this->personalAccessToken = get_setting('git_pat');
        $this->userEmail = get_setting('git_user_email', 'bot@config-manager.local');
        $this->tempPath = get_setting('temp_directory_path', sys_get_temp_dir());
        $this->persistentRepoPath = get_setting('git_persistent_repo_path');
    }

    public function isPersistentPath(string $path): bool
    {
        // A path is persistent if the setting is configured and the path matches it.
        return !empty($this->persistentRepoPath) && $path === $this->persistentRepoPath;
    }

    /**
     * Clones a specific repository into a unique temporary directory.
     * @param string $repoUrl The URL of the repository to clone.
     * @param string $branch The branch to clone.
     * @return string The path to the temporary directory where the repo was cloned.
     * @throws Exception
     */
    public function cloneOrPull(string $repoUrl, string $branch = 'main'): string
    {
        // Proactive check: ensure the base temporary path is writable.
        if (!is_writable($this->tempPath)) {
            throw new Exception("The temporary directory ('{$this->tempPath}') is not writable by the web server. Please check its permissions.");
        }

        // Create a unique temporary directory for this clone operation
        $tempDir = rtrim($this->tempPath, '/') . '/git_clone_' . uniqid();
        if (is_dir($tempDir)) {
            $this->cleanup($tempDir);
        }
        // Clone the specific repo into the temp directory
        $isSsh = str_starts_with($repoUrl, 'git@');
        $this->execute("clone --branch " . escapeshellarg($branch) . " --single-branch " . escapeshellarg($repoUrl) . " " . escapeshellarg($tempDir), null, $isSsh);

        return $tempDir;
    }

    /**
     * Tests the connection to a remote Git repository.
     * @param string $repoUrl The URL of the repository to test.
     * @param string|null $sshKeyPath Optional path to the SSH key to use for this specific test.
     * @throws Exception If the connection fails.
     */
    public function testConnection(string $repoUrl, ?string $sshKeyPath = null): void
    {
        // The command `ls-remote` is a lightweight way to check if a repo is accessible.
        $isSsh = str_starts_with($repoUrl, 'git@');
        
        // If a specific key path is provided for the test, use it. Otherwise, use the one from settings.
        $keyPathForTest = $sshKeyPath ?? $this->sshKeyPath;

        $this->execute("ls-remote --heads " . escapeshellarg($repoUrl), null, $isSsh, $keyPathForTest);
    }

    /**
     * Clones the repository if it doesn't exist locally, or pulls the latest changes if it does.
     * @throws Exception
     */
    public function setupRepository(): string
    {
        if (empty($this->repoUrl)) {
            throw new Exception("Git repository URL is not configured in settings.");
        }

        $isSsh = str_starts_with($this->repoUrl, 'git@');

        // Use the persistent path if configured.
        if (empty($this->persistentRepoPath)) {
            // Fallback to old temporary directory logic if persistent path is not set.
            $repoPath = rtrim($this->tempPath, '/') . '/config-manager-git_' . uniqid();
            if (is_dir($repoPath)) {
                $this->cleanup($repoPath);
            }
            $this->execute("clone --branch {$this->branch} --single-branch " . escapeshellarg($this->repoUrl) . " " . escapeshellarg($repoPath), null, $isSsh);
        } else {
            // New logic with persistent repository
            $repoPath = $this->persistentRepoPath;

            if (!is_dir($repoPath . '/.git')) {
                // If the directory doesn't exist or is not a git repo, clone it.
                if (is_dir($repoPath)) {
                    $this->cleanup($repoPath); // Cleanup if it's just an empty/invalid directory
                }
                // Ensure the parent directory exists and is writable before cloning.
                $parentDir = dirname($repoPath);
                if (!is_dir($parentDir)) {
                    @mkdir($parentDir, 0755, true);
                }
                // After attempting to create, check if it's a directory and writable.
                if (!is_dir($parentDir) || !is_writable($parentDir)) {
                    throw new Exception("The parent directory for the persistent repository ('{$parentDir}') is not writable by the web server. Please check its permissions or ownership.");
                }
                $this->execute("clone --branch {$this->branch} --single-branch " . escapeshellarg($this->repoUrl) . " " . escapeshellarg($repoPath), null, $isSsh);
            } else {
                // If it exists, fetch latest changes and reset to ensure a clean state.
                $this->execute("fetch origin", $repoPath, $isSsh);
                $this->execute("reset --hard origin/" . escapeshellarg($this->branch), $repoPath, $isSsh);
                $this->execute("pull origin " . escapeshellarg($this->branch), $repoPath, $isSsh);
                // Clean any untracked files from previous operations to ensure a pristine state for diffing
                $this->execute("clean -fdx", $repoPath, $isSsh);
            }
        }

        // Explicitly set permissions to ensure writability inside the cloned repo
        if (!@chmod($repoPath, 0755) && !is_writable($repoPath)) {
            throw new Exception("Repository directory '{$repoPath}' is not writable and permissions could not be set.");
        }

        // Configure user for this repository
        if (empty($this->userName) || empty($this->userEmail)) {
            throw new Exception("Git User Name and Git User Email must be configured in Settings for commit operations.");
        }
        $this->execute("config user.name " . escapeshellarg($this->userName), $repoPath);
        $this->execute("config user.email " . escapeshellarg($this->userEmail), $repoPath);

        return $repoPath;
    }

    /**
     * Writes the YAML content to the file, adds, commits, and pushes it.
     * @param string $repoPath The path to the local repository.
     * @param string $commitMessage The commit message.
     * @throws Exception
     */
    public function commitAndPush(string $repoPath, string $commitMessage): void
    {
        if (!is_dir($repoPath)) {
            throw new Exception("Repository path does not exist: {$repoPath}");
        }
        $isSsh = str_starts_with($this->repoUrl, 'git@');
        // Add all changes (new files, modified files, deleted files)
        $this->execute("add -A", $repoPath, $isSsh);
        
        // Check for changes before committing
        $statusOutput = $this->execute("status --porcelain", $repoPath, $isSsh);
        if (empty($statusOutput)) {
            // No changes to commit
            return;
        }

        $this->execute("commit -m " . escapeshellarg($commitMessage), $repoPath, $isSsh);

        $this->execute("push origin {$this->branch}", $repoPath, $isSsh);
    }

    /**
     * Removes a directory from the repository, commits, and pushes the change.
     * @param string $repoPath The path to the local repository.
     * @param string $directoryToRemove The relative path of the directory to remove inside the repo.
     * @param string $commitMessage The commit message.
     * @throws Exception
     */
    public function removeAndPush(string $repoPath, string $directoryToRemove, string $commitMessage): void
    {
        if (!is_dir($repoPath)) {
            throw new Exception("Repository path does not exist: {$repoPath}");
        }

        $fullPathToRemove = $repoPath . '/' . $directoryToRemove;

        if (file_exists($fullPathToRemove)) {
            // Physically remove the directory from the working tree.
            $this->cleanup($fullPathToRemove);

            // The existing commitAndPush method will stage the deletion and push.
            $this->commitAndPush($repoPath, $commitMessage);
        } else {
            error_log("GitHelper: Path '{$directoryToRemove}' not found in repo working tree at '{$repoPath}', skipping removal from Git.");
        }
    }

    /**
     * Gets the output of `git diff` for the working directory.
     * @param string $repoPath The path to the local repository.
     * @param bool $staged Whether to show staged changes instead of working directory changes.
     * @return string The diff output.
     * @throws Exception
     */
    public function getDiff(string $repoPath, bool $staged = false): string
    {
        if (!is_dir($repoPath)) {
            throw new Exception("Repository path does not exist: {$repoPath}");
        }
        $isSsh = str_starts_with($this->repoUrl, 'git@');
        $command = $staged ? "diff --staged" : "diff";
        return $this->execute($command, $repoPath, $isSsh);
    }

    /**
     * Gets the output of `git status --porcelain`.
     * @param string $repoPath The path to the local repository.
     * @return string The status output.
     * @throws Exception
     */
    public function getStatus(string $repoPath): string
    {
        if (!is_dir($repoPath)) {
            throw new Exception("Repository path does not exist: {$repoPath}");
        }
        $isSsh = str_starts_with($this->repoUrl, 'git@');
        return $this->execute("status --porcelain", $repoPath, $isSsh);
    }

    /**
     * Removes the temporary local repository.
     * @param string $path The specific path to remove.
     */
    public function cleanup(string $path): void
    {
        if (is_dir($path)) {
            $command = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? "rmdir /s /q" : "rm -rf";
            shell_exec($command . " " . escapeshellarg($path));
        }
    }

    /**
     * Executes a Git command.
     * @param string $command The git command to run (e.g., "clone ...", "pull ...").
     * @param string|null $workingDir The directory to run the command in.
     * @param bool $isSsh Whether to use the SSH command wrapper.
     * @param string|null $overrideSshKeyPath A specific SSH key to use for this command only.
     * @return string The output from the command.
     * @throws Exception If the command fails.
     */
    public function execute(string $command, ?string $workingDir = null, bool $isSsh = false, ?string $overrideSshKeyPath = null): string
    {
        $originalDir = null;
        if ($workingDir) {
            $originalDir = getcwd();
            if (!@chdir($workingDir)) {
                throw new Exception("Could not change directory to {$workingDir}");
            }
        }

        $fullCommand = "git {$command} 2>&1";
        if ($isSsh) {
            $keyPathToUse = $overrideSshKeyPath ?? $this->sshKeyPath;
            if (empty($keyPathToUse)) {
                throw new Exception("SSH key path is not configured, which is required for SSH URLs.");
            }
            if (!file_exists($keyPathToUse)) {
                throw new Exception("SSH key not found at: {$keyPathToUse}");
            }
            $this->gitSshCommand = "ssh -i " . escapeshellarg($keyPathToUse) . " -o IdentitiesOnly=yes -o StrictHostKeyChecking=no";
            $fullCommand = "GIT_SSH_COMMAND='{$this->gitSshCommand}' " . $fullCommand;
        }

        // For HTTPS URLs, use a credential helper to provide the token securely for every command.
        if (!$isSsh && !empty($this->personalAccessToken)) {
            // This command tells git to use the provided username and token for this operation only.
            // The username can be anything when using a PAT; 'token' is a common convention.
            $credentialHelperCmd = "git -c credential.helper='!f() { echo \"username=token\"; echo \"password=" . escapeshellarg($this->personalAccessToken) . "\"; }; f' ";
            // Prepend this to the git command, replacing the original 'git'
            $fullCommand = $credentialHelperCmd . substr($fullCommand, 4);
        }
        
        exec($fullCommand, $output, $return_var);

        if ($originalDir) {
            chdir($originalDir);
        }

        $outputString = implode("\n", $output);

        if ($return_var !== 0) {
            $error_message = "Git command failed: [{$fullCommand}]. Output: {$outputString}";
            // Check for common permission-related error messages
            if (stripos($outputString, 'Permission denied') !== false || stripos($outputString, 'fatal: could not create work tree') !== false) {
                $error_message .= "\n\nHint: This often indicates a file permission issue. Please ensure the web server user (e.g., 'www-data') has write permissions on the target directory.";
            }

            throw new Exception($error_message);
        }

        return $outputString;
    }
}