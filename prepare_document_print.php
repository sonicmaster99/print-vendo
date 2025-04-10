<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/GoogleDriveHandler.php';

// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

try {
    // Check if machine ID is provided
    if (!isset($_GET['machineId'])) {
        throw new Exception('Machine ID is required');
    }

    $machineId = $_GET['machineId'];
    
    // Start session to get document data
    session_start();
    
    // Initialize Google Drive handler
    $driveHandler = new GoogleDriveHandler();
    
    // Check if machine exists in Google Drive
    if (!$driveHandler->isMachineIdValid($machineId)) {
        throw new Exception('Invalid machine ID');
    }
    
    // Get document data from session
    $documentData = isset($_SESSION['document_' . $machineId]) ? $_SESSION['document_' . $machineId] : null;
    
    // If document data is not in session, try to get it from Google Drive
    if (!$documentData) {
        // Look for document files in Google Drive
        $promptFile = $driveHandler->findFile($machineId, 'prompt.txt');
        $responseFile = $driveHandler->findFile($machineId, 'response.txt');
        
        if (!$promptFile || !$responseFile) {
            throw new Exception('Document data not found');
        }
        
        $documentData = [
            'prompt' => $promptFile['content'] ?? 'No prompt available',
            'response' => $responseFile['content'] ?? 'No response available',
            'documentName' => 'Document from Google Drive'
        ];
    }
    
    // Create print file
    $printContent = "Document Analysis Results\n\n";
    $printContent .= "Prompt: " . ($documentData['prompt'] ?? 'No prompt available') . "\n\n";
    $printContent .= "Response:\n" . ($documentData['response'] ?? 'No response available') . "\n\n";
    $printContent .= "Generated on: " . date('Y-m-d H:i:s') . "\n";
    $printContent .= "Machine ID: " . $machineId . "\n";
    
    // Save print file locally
    $tempDir = __DIR__ . '/temp_uploads';
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    $printFilePath = $tempDir . '/print_' . uniqid() . '.txt';
    file_put_contents($printFilePath, $printContent);
    
    // Upload print file to Google Drive
    $printFileResult = $driveHandler->uploadFile($machineId, $printFilePath, 'document_print.txt');
    
    // Create amount_print_request.txt for payment
    $amountPrintRequestPath = $tempDir . '/amount_print_request_' . uniqid() . '.txt';
    $printCost = 5; // Fixed cost for printing (5 pesos)
    file_put_contents($amountPrintRequestPath, "Cost: PHP " . number_format($printCost, 2));
    
    // Upload amount_print_request.txt to Google Drive
    $amountPrintRequestResult = $driveHandler->uploadFile($machineId, $amountPrintRequestPath, 'amount_print_request.txt');
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Document prepared for printing',
        'printCost' => $printCost
    ]);
    
    // Clean up temporary files
    unlink($printFilePath);
    unlink($amountPrintRequestPath);
    
} catch (Exception $e) {
    error_log('Error in prepare_document_print.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
