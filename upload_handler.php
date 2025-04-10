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
    // Log POST data
    error_log('POST data: ' . print_r($_POST, true));
    error_log('FILES data: ' . print_r($_FILES, true));

    // Handle regular file upload
    $machineId = isset($_POST['machineId']) ? $_POST['machineId'] : '';
    $amount = isset($_POST['amount']) ? $_POST['amount'] : '0';
    $fileId = isset($_POST['fileId']) ? $_POST['fileId'] : '';
    $printMode = isset($_POST['printMode']) ? $_POST['printMode'] : 'bw';

    // Calculate cost based on number of pages
    $totalPages = isset($_POST['totalPages']) ? $_POST['totalPages'] : '0';
    $bwPages = isset($_POST['bwPages']) ? $_POST['bwPages'] : '0';
    $colorPages = isset($_POST['colorPages']) ? $_POST['colorPages'] : '0';

    // Calculate cost (3 units per BW page, 5 units per color page)
    $cost = ($bwPages * 3) + ($colorPages * 5);

    // Initialize Google Drive handlers
    $driveHandler = new GoogleDriveHandler();
    $driveFileHandler = new GoogleDriveFileHandler();
    
    // Ensure machine folder exists in Google Drive
    $driveFolderId = $driveFileHandler->ensureMachineFolder($machineId);
    if (!$driveFolderId) {
        throw new Exception('Failed to create or access machine folder in Google Drive');
    }

    // Save cost details to Google Drive
    $costDetails = ['cost' => $cost];
    $driveFileHandler->writeFile($machineId, 'cost_details.json', json_encode($costDetails));

    // Check if we have a file to upload
    if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
        $file = $_FILES['file'];
        $fileName = $file['name'];
        $fileTmpPath = $file['tmp_name'];
        
        // Use fileId in the filename if provided
        if (!empty($fileId)) {
            $fileNameParts = pathinfo($fileName);
            $fileName = $fileId . '.' . $fileNameParts['extension'];
        }
        
        // Upload the file to Google Drive
        $uploadResult = $driveHandler->uploadFile($machineId, $fileTmpPath, $fileName);
        
        // Check if the upload was successful
        if (!$uploadResult || !is_array($uploadResult) || !isset($uploadResult['fileId']) || !isset($uploadResult['webViewLink'])) {
            throw new Exception('Failed to upload file to Google Drive');
        }
        
        // Log the successful upload
        error_log("File uploaded to Google Drive with ID: " . $uploadResult['fileId']);
        
        echo json_encode([
            'success' => true, 
            'cost' => $cost,
            'fileId' => $uploadResult['fileId'],
            'webViewLink' => $uploadResult['webViewLink']
        ]);
    } 
    // If we have cost content but no file (for cost.txt generation)
    else if (isset($_POST['costContent'])) {
        $costContent = $_POST['costContent'];
        
        // Create a cost filename with the fileId if provided
        $costFileName = !empty($fileId) ? "cost_{$fileId}.txt" : "cost_" . time() . ".txt";
        
        // Upload the cost content directly to Google Drive
        $uploadResult = $driveFileHandler->writeFile($machineId, $costFileName, $costContent);
        
        // Log the successful upload
        error_log("Cost file uploaded to Google Drive with ID: " . $uploadResult['fileId']);
        
        echo json_encode([
            'success' => true, 
            'cost' => $cost,
            'costFileId' => $uploadResult['fileId'],
            'webViewLink' => $uploadResult['webViewLink']
        ]);
    }
    // No file to upload, just return cost
    else {
        echo json_encode(['success' => true, 'cost' => $cost]);
    }
} catch (Exception $e) {
    error_log('Error in upload_handler.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
