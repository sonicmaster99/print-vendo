<?php
header('Content-Type: application/json');

// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Include Google Drive file handler
require_once 'includes/GoogleDriveFileHandler.php';

try {
    // Validate required parameters
    $requiredParams = ['machineId', 'content', 'fileId', 'pageInfo', 'printType'];
    foreach ($requiredParams as $param) {
        if (!isset($_POST[$param])) {
            throw new Exception("Missing required parameter: $param");
        }
    }

    $machineId = $_POST['machineId'];
    $content = $_POST['content'];
    $fileId = $_POST['fileId'];
    $pageInfo = json_decode($_POST['pageInfo'], true);
    $printType = $_POST['printType'];
    
    if (!$pageInfo) {
        throw new Exception('Invalid page info format');
    }

    // Initialize Google Drive file handler
    $driveFileHandler = new GoogleDriveFileHandler();
    
    // Ensure machine folder exists in Google Drive
    $driveFolderId = $driveFileHandler->ensureMachineFolder($machineId);
    if (!$driveFolderId) {
        throw new Exception('Failed to create or access machine folder in Google Drive');
    }
    
    // File paths and IDs
    $driveFileIds = [];
    $driveViewLinks = [];
    
    // Save content file to Google Drive
    $contentFileName = $fileId . '.txt';
    $contentResult = $driveFileHandler->writeFile($machineId, $contentFileName, $content);
    if (!$contentResult) {
        throw new Exception('Failed to save content file to Google Drive');
    }
    $driveFileIds['content'] = $contentResult['fileId'];
    $driveViewLinks['content'] = $contentResult['webViewLink'];

    // Save PDF file if provided
    if (isset($_FILES['pdfFile']) && $_FILES['pdfFile']['error'] === UPLOAD_ERR_OK) {
        $pdfFileName = $fileId . '.pdf';
        $pdfResult = $driveFileHandler->uploadFile(
            $machineId, 
            $_FILES['pdfFile']['tmp_name'], 
            $pdfFileName
        );
        if (!$pdfResult) {
            throw new Exception('Failed to save PDF file to Google Drive');
        }
        $driveFileIds['pdf'] = $pdfResult['fileId'];
        $driveViewLinks['pdf'] = $pdfResult['webViewLink'];
    }

    // Save cost file if provided
    if (isset($_FILES['costFile']) && $_FILES['costFile']['error'] === UPLOAD_ERR_OK) {
        $costResult = $driveFileHandler->uploadFile(
            $machineId, 
            $_FILES['costFile']['tmp_name'], 
            'cost.txt'
        );
        if (!$costResult) {
            throw new Exception('Failed to save cost file to Google Drive');
        }
        $driveFileIds['cost'] = $costResult['fileId'];
        $driveViewLinks['cost'] = $costResult['webViewLink'];
    }

    // Get the total cost from pageInfo
    $totalCost = isset($pageInfo['totalCost']) ? $pageInfo['totalCost'] : 0;
    
    // Format the total cost with 2 decimal places
    $amountContent = number_format($totalCost, 2, '.', '');
    
    // Log the amount for debugging
    error_log("Creating Amount_print_request.txt with content: " . $amountContent);
    
    // Create Amount_print_request.txt with the actual amount content
    // This is separate from the regular ChatGPT Amount_request.txt file
    $printRequestResult = $driveFileHandler->writeFile(
        $machineId, 
        'Amount_print_request.txt', 
        $amountContent
    );
    
    if (!$printRequestResult) {
        throw new Exception('Failed to create print request file in Google Drive');
    }
    
    $driveFileIds['printRequest'] = $printRequestResult['fileId'];
    $driveViewLinks['printRequest'] = $printRequestResult['webViewLink'];

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Document prepared for printing',
        'data' => [
            'machineId' => $machineId,
            'fileId' => $fileId,
            'driveFileIds' => $driveFileIds,
            'driveViewLinks' => $driveViewLinks,
            'pageInfo' => $pageInfo,
            'printType' => $printType
        ]
    ]);

} catch (Exception $e) {
    error_log('Error in prepare_print.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
