<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/AppLauncherHelper.php';

// Set global variable for log file handle used by AppLauncherHelper's stream functions
global $log_file_handle;
$log_file_handle = null;

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
    stream_message("---");
    stream_message("Deployment finished successfully!", "SUCCESS");
    echo "_DEPLOYMENT_COMPLETE_";

} catch (Exception $e) {
    // The exception message is already streamed by AppLauncherHelper, so we just mark failure.
    // stream_message($e->getMessage(), 'ERROR');
    echo "_DEPLOYMENT_FAILED_";
} finally {
    // Cleanup is handled within AppLauncherHelper::executeDeployment
}
?>