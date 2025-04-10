<?php
header('Content-Type: application/json');

// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Include Google Drive file handler
require_once 'includes/GoogleDriveFileHandler.php';

try {
    if (!isset($_GET['machineId'])) {
        throw new Exception('Machine ID is required');
    }

    $machineId = $_GET['machineId'];
    
    // Initialize Google Drive file handler
    $driveFileHandler = new GoogleDriveFileHandler();
    
    // Check if response file exists in Google Drive
    $content = $driveFileHandler->readFile($machineId, 'response.txt');
    
    if ($content !== null) {
        echo json_encode([
            'success' => true,
            'response' => $content
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'response' => null
        ]);
    }

} catch (Exception $e) {
    error_log('Error in check_response.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
