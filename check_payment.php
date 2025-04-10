<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/GoogleDriveHandler.php';

// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

try {
    if (!isset($_GET['machineId'])) {
        throw new Exception('Machine ID is required');
    }

    $machineId = $_GET['machineId'];
    $paymentType = isset($_GET['type']) ? $_GET['type'] : 'regular';
    
    // Initialize Google Drive handler
    $driveHandler = new GoogleDriveHandler();
    
    // Check if machine exists in Google Drive
    if (!$driveHandler->isMachineIdValid($machineId)) {
        throw new Exception('Invalid machine ID');
    }

    // Look for payment file in Google Drive
    $fileName = $paymentType === 'print' ? 'Amount_print_paid.txt' : 'Amount_paid.txt';
    $fileResult = $driveHandler->findFile($machineId, $fileName);
    
    // Check if the file result is valid
    if (!is_array($fileResult) && $fileResult !== null) {
        error_log('Invalid file result from Google Drive: ' . print_r($fileResult, true));
        $fileResult = null;
    }
    
    $paid = ($fileResult !== null);
    
    // Return the payment status
    echo json_encode([
        'success' => true,
        'paid' => $paid,
        'machineId' => $machineId,
        'paymentType' => $paymentType,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    // If paid, delete the payment file from Google Drive
    if ($paid && isset($fileResult['fileId'])) {
        $driveHandler->deleteFile($fileResult['fileId']);
    }
    
} catch (Exception $e) {
    error_log('Error in check_payment.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
