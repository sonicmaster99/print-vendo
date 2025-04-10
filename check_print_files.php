<?php
header('Content-Type: application/json');

// Include Google Drive file handler
require_once 'includes/GoogleDriveFileHandler.php';

// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

try {
    // Get the machine ID from GET request
    $machineId = isset($_GET['machineId']) ? $_GET['machineId'] : '';

    // Validate inputs
    if (empty($machineId)) {
        echo json_encode(['success' => false, 'message' => 'Machine ID is required']);
        exit;
    }

    // Initialize Google Drive file handler
    $driveFileHandler = new GoogleDriveFileHandler();
    
    // List of files to check
    $filesToCheck = [
        'Amount_request.txt',
        'Amount_print_request.txt',
        'Amount_paid.txt',
        'Amount_print_paid.txt',
        'cost.txt'
    ];
    
    $results = [];
    
    // Check each file
    foreach ($filesToCheck as $fileName) {
        $fileExists = $driveFileHandler->fileExists($machineId, $fileName);
        
        if ($fileExists) {
            // Get file content if it exists
            $fileContent = $driveFileHandler->readFile($machineId, $fileName);
            $results[$fileName] = [
                'exists' => true,
                'content' => $fileContent
            ];
        } else {
            $results[$fileName] = [
                'exists' => false
            ];
        }
    }

    // Return the results
    echo json_encode([
        'success' => true,
        'machineId' => $machineId,
        'files' => $results,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log('Error in check_print_files.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
