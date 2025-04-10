<?php
header('Content-Type: application/json');

// Include Google Drive file handler
require_once 'includes/GoogleDriveFileHandler.php';

try {
    if (!isset($_GET['machineId'])) {
        throw new Exception('Machine ID is required');
    }

    $machineId = $_GET['machineId'];
    
    // Initialize Google Drive file handler
    $driveFileHandler = new GoogleDriveFileHandler();
    
    // Read cost details file from Google Drive
    $costDetailsContent = $driveFileHandler->readFile($machineId, 'cost_details.json');
    
    if ($costDetailsContent !== null) {
        $costDetails = json_decode($costDetailsContent, true);
        if ($costDetails === null) {
            throw new Exception('Invalid cost details format');
        }
        
        echo json_encode([
            'success' => true,
            'costDetails' => $costDetails
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Cost details not found'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
