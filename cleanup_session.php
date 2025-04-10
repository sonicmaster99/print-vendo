<?php
header('Content-Type: application/json');

// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Include the Google Drive handlers
require_once __DIR__ . '/includes/GoogleDriveHandler.php';
require_once __DIR__ . '/includes/GoogleDriveFileHandler.php';

try {
    // Check if machine ID is provided
    if (!isset($_POST['machineId'])) {
        throw new Exception('Machine ID is required');
    }

    $machineId = $_POST['machineId'];
    
    // Validate machine ID format
    if (empty($machineId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $machineId)) {
        throw new Exception('Invalid machine ID format');
    }

    // Debug log
    error_log("Cleaning up session for machine ID: $machineId");

    // Initialize Google Drive handlers
    $driveHandler = new GoogleDriveHandler();
    $driveFileHandler = new GoogleDriveFileHandler();
    
    // Validate machine ID against Google Drive
    if (!$driveHandler->isMachineIdValid($machineId)) {
        throw new Exception('Invalid machine ID. This machine is not registered.');
    }

    // Get all files in the Google Drive folder for this machine
    $deletedFiles = $driveHandler->deleteAllMachineFiles($machineId);
    
    // Clean up local files if they exist
    $localPath = __DIR__ . "/machines/$machineId";
    $localFilesDeleted = 0;
    
    if (file_exists($localPath) && is_dir($localPath)) {
        $localFilesDeleted = cleanLocalDirectory($localPath);
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Session cleaned up successfully',
        'machineId' => $machineId,
        'driveFilesDeleted' => $deletedFiles,
        'localFilesDeleted' => $localFilesDeleted
    ]);
    
} catch (Exception $e) {
    error_log("Cleanup error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Recursively delete all files in a directory but keep the directory structure
 * 
 * @param string $dir Directory path
 * @return int Number of files deleted
 */
function cleanLocalDirectory($dir) {
    $count = 0;
    
    if (!is_dir($dir)) {
        return $count;
    }
    
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') continue;
        
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            // Recursively clean subdirectories
            $count += cleanLocalDirectory($path);
        } else {
            // Delete file
            if (unlink($path)) {
                $count++;
            }
        }
    }
    
    return $count;
}
