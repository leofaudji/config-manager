<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/AppLauncherHelper.php';

// --- Streaming Setup ---
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
@ini_set('zlib.output_compression', 0);
if (ob_get_level() > 0) {
    for ($i = 0; $i < ob_get_level(); $i++) {
        ob_end_flush();
    }
}
if (ob_get_level() > 0) { for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); } }
ob_implicit_flush(1);

try {
    // Call the centralized deployment logic
    AppLauncherHelper::executeDeployment($_POST);
    AppLauncherHelper::stream_message("---");
    AppLauncherHelper::stream_message("Deployment finished successfully!", "SUCCESS");
    echo "_DEPLOYMENT_COMPLETE_";

} catch (Throwable $e) { // Catch any throwable error
    AppLauncherHelper::stream_message($e->getMessage(), 'ERROR');
    echo "_DEPLOYMENT_FAILED_";
} finally {
    // Ensure the log file is always closed at the end of the script.
    AppLauncherHelper::closeLogFile();
}
?>