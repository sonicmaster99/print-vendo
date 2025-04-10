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
    // Get the machine ID and amount from POST request
    $machineId = isset($_POST['machineId']) ? $_POST['machineId'] : '';
    $amount = isset($_POST['amount']) ? $_POST['amount'] : 0;

    // Validate inputs
    if (empty($machineId)) {
        echo json_encode(['success' => false, 'message' => 'Machine ID is required']);
        exit;
    }

    // Initialize Google Drive file handler
    $driveFileHandler = new GoogleDriveFileHandler();
    
    // Ensure machine folder exists in Google Drive
    $driveFolderId = $driveFileHandler->ensureMachineFolder($machineId);
    if (!$driveFolderId) {
        throw new Exception('Failed to create or access machine folder in Google Drive');
    }
    
    // Save the ChatGpt_Amount.txt file to Google Drive
    $result = $driveFileHandler->writeFile($machineId, 'ChatGpt_Amount.txt', $amount);
    
    if (!$result) {
        throw new Exception('Failed to save cost information to Google Drive');
    }

    // Return success response
    echo json_encode([
        'success' => true, 
        'message' => 'Cost information saved successfully to Google Drive',
        'fileId' => $result['fileId'],
        'webViewLink' => $result['webViewLink']
    ]);
    
} catch (Exception $e) {
    error_log('Error in save_chatgpt_cost.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}