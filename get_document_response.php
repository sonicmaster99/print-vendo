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
        
        if (!$promptFile) {
            throw new Exception('Document data not found');
        }
        
        $documentData = [
            'prompt' => $promptFile['content'] ?? 'No prompt available',
            'response' => $responseFile['content'] ?? 'No response available yet',
            'documentName' => 'Document from Google Drive'
        ];
    } else {
        // If we have document data in session but no response yet, check Google Drive for response
        if (!isset($documentData['response'])) {
            $responseFile = $driveHandler->findFile($machineId, 'response.txt');
            if ($responseFile) {
                $documentData['response'] = $responseFile['content'];
                // Update session data
                $_SESSION['document_' . $machineId]['response'] = $responseFile['content'];
            }
        }
    }
    
    // Return document data
    echo json_encode([
        'success' => true,
        'documentName' => $documentData['name'] ?? 'Unknown document',
        'prompt' => $documentData['prompt'] ?? 'No prompt available',
        'response' => $documentData['response'] ?? 'Your document is still being processed. The response will appear here once it\'s ready.',
        'model' => $documentData['model'] ?? '3.5',
        'timestamp' => $documentData['timestamp'] ?? date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log('Error in get_document_response.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
