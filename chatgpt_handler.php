<?php
header('Content-Type: application/json');

// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Include Google Drive file handler
require_once 'includes/GoogleDriveFileHandler.php';

try {
    // Log POST data
    error_log('POST data: ' . print_r($_POST, true));

    // Check for token usage
    $machineId = isset($_POST['machineId']) ? $_POST['machineId'] : '';
    $amount = isset($_POST['amount']) ? $_POST['amount'] : '0';

    // Initialize Google Drive file handler
    $driveFileHandler = new GoogleDriveFileHandler();
    
    // Read cost details file from Google Drive
    $costDetailsContent = $driveFileHandler->readFile($machineId, 'cost_details.json');
    $actualTokens = null;
    
    if ($costDetailsContent !== null) {
        $actualTokens = json_decode($costDetailsContent, true);
    }

    if ($actualTokens) {
        // Update the amount with actual cost
        $amount = $actualTokens['costPhp'] ?? $actualTokens['finalCost'] ?? $amount;
        echo json_encode(['success' => true, 'amount' => $amount, 'tokens' => $actualTokens]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Token details not found']);
    }
} catch (Exception $e) {
    error_log('Error in chatgpt_handler.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
