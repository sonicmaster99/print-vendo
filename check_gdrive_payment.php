<?php
header('Content-Type: application/json');

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
    
    // Include Google Drive handler
    if (!file_exists(__DIR__ . '/includes/GoogleDriveHandler.php')) {
        throw new Exception('GoogleDriveHandler.php not found');
    }

    require_once __DIR__ . '/includes/GoogleDriveHandler.php';
    $driveHandler = new GoogleDriveHandler();

    // Validate machine ID
    if (!$driveHandler->isMachineIdValid($machineId)) {
        throw new Exception('Invalid machine ID');
    }

    // Search for Amount_request.txt in the machine's folder
    $requestFileName = $paymentType === 'print' ? 'Amount_print_request.txt' : 'Amount_request.txt';
    $result = $driveHandler->findFile($machineId, $requestFileName);
    
    if ($result && isset($result['content'])) {
        // If file exists and is empty or contains only whitespace
        $content = trim($result['content']);
        $verified = empty($content);
        
        echo json_encode([
            'success' => true,
            'verified' => $verified,
            'fileId' => $result['fileId'],
            'paymentType' => $paymentType
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'verified' => false,
            'fileId' => null,
            'paymentType' => $paymentType
        ]);
    }

} catch (Exception $e) {
    error_log('Error in check_gdrive_payment.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
