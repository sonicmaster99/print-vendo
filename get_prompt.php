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
    
    // Read prompt file from Google Drive
    $content = $driveFileHandler->readFile($machineId, 'prompt.txt');
    
    if ($content === null) {
        throw new Exception('Prompt file not found or could not be read');
    }

    echo json_encode([
        'success' => true,
        'content' => $content,
        'file' => 'prompt.txt'
    ]);

} catch (Exception $e) {
    error_log('Error in get_prompt.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
