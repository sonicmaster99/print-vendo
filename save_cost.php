<?php
header('Content-Type: application/json');

// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Include Google Drive file handler
require_once 'includes/GoogleDriveFileHandler.php';

try {
    // Debug received data
    error_log('REQUEST METHOD: ' . $_SERVER['REQUEST_METHOD']);
    error_log('CONTENT TYPE: ' . $_SERVER['CONTENT_TYPE'] ?? 'Not set');
    error_log('$_POST data: ' . print_r($_POST, true));
    error_log('$_GET data: ' . print_r($_GET, true));
    error_log('Raw input: ' . file_get_contents('php://input'));
    
    // Look for parameters in either POST or GET
    $request = array_merge($_GET, $_POST);
    error_log('Combined request data: ' . print_r($request, true));
    
    // Get parameters with fallbacks to prevent errors
    $machineId = isset($request['machineId']) ? $request['machineId'] : '';
    $amount = isset($request['amount']) ? $request['amount'] : '0';
    
    // Initialize Google Drive file handler
    $driveFileHandler = new GoogleDriveFileHandler();
    
    // Ensure machine folder exists in Google Drive
    $driveFolderId = $driveFileHandler->ensureMachineFolder($machineId);
    if (!$driveFolderId) {
        throw new Exception('Failed to create or access machine folder in Google Drive');
    }
    
    // Check for actual token counts from cost_details.json
    $actualTokens = null;
    $costDetailsContent = $driveFileHandler->readFile($machineId, 'cost_details.json');
    if ($costDetailsContent !== null) {
        $actualTokens = json_decode($costDetailsContent, true);
    }
    
    // Always generate some basic content even if not provided
    if (isset($request['costContent']) && !empty($request['costContent'])) {
        $costContent = urldecode($request['costContent']);
        
        // If we have actual token counts, append them to the cost content
        if ($actualTokens) {
            $costContent .= "\n\nActual Token Usage:\n";
            $costContent .= "Input Tokens: {$actualTokens['inputTokens']}\n";
            $costContent .= "Output Tokens: {$actualTokens['outputTokens']}\n";
            $costContent .= "Final Cost: ₱{$actualTokens['finalCost']}\n";
            $costContent .= "-------------";
            
            // Update the amount with actual cost
            $amount = $actualTokens['finalCost'];
        }
    } else {
        // Generate fallback cost content
        $fileId = isset($request['fileId']) ? $request['fileId'] : 'unknown';
        $totalPages = isset($request['totalPages']) ? $request['totalPages'] : '0';
        $bwPages = isset($request['bwPages']) ? $request['bwPages'] : '0';
        $colorPages = isset($request['colorPages']) ? $request['colorPages'] : '0';
        
        $costContent = "Document Cost Summary\n";
        $costContent .= "-----------------------\n";
        $costContent .= "File ID: {$fileId}\n";
        $costContent .= "Machine ID: {$machineId}\n";
        $costContent .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
        $costContent .= "Total Pages: {$totalPages}\n";
        $costContent .= "Black & White Pages: {$bwPages}\n";
        $costContent .= "Color Pages: {$colorPages}\n";
        $costContent .= "Total Cost: ₱{$amount}\n";
        $costContent .= "-----------------------";
        
        error_log('Generated fallback cost content: ' . $costContent);
    }
    
    // Save cost.txt to Google Drive
    $costResult = $driveFileHandler->writeFile($machineId, 'cost.txt', $costContent);
    if (!$costResult) {
        throw new Exception('Failed to save cost file to Google Drive');
    }

    // Save Amount_request.txt with the amount to Google Drive
    $amountResult = $driveFileHandler->writeFile($machineId, 'Amount_request.txt', $amount);
    if (!$amountResult) {
        throw new Exception('Failed to save amount file to Google Drive');
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Cost and amount files saved to Google Drive',
        'data' => [
            'costInfo' => $costContent,
            'driveFileIds' => [
                'cost' => $costResult['fileId'],
                'amount' => $amountResult['fileId']
            ],
            'driveViewLinks' => [
                'cost' => $costResult['webViewLink'],
                'amount' => $amountResult['webViewLink']
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log('Error in save_cost.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
