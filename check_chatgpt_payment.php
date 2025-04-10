<?php
// Set headers to prevent caching
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Content-Type: application/json');

// Include Google Drive file handler
require_once 'includes/GoogleDriveFileHandler.php';

// Error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

try {
    // Get the machine ID from GET request
    $machineId = isset($_GET['machineId']) ? $_GET['machineId'] : '';

    // Validate inputs
    if (empty($machineId)) {
        echo json_encode(['verified' => false, 'message' => 'Machine ID is required']);
        exit;
    }

    // Initialize Google Drive file handler
    $driveFileHandler = new GoogleDriveFileHandler();
    
    // Check if the verification file exists in Google Drive
    $verified = $driveFileHandler->fileExists($machineId, 'ChatGpt_Amount_Satisfied.txt');

    // Return the verification status
    echo json_encode([
        'verified' => $verified,
        'machineId' => $machineId,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    // If verified, we could optionally delete the file from Google Drive
    // This would be similar to how check_payment.php works
    
} catch (Exception $e) {
    error_log('Error in check_chatgpt_payment.php: ' . $e->getMessage());
    echo json_encode([
        'verified' => false,
        'message' => $e->getMessage()
    ]);
}
