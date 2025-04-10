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
    $hasContent = false;
    $responseContent = '';
    
    // Check if we have response content in session
    if ($documentData && isset($documentData['response']) && !empty($documentData['response'])) {
        $hasContent = true;
        $responseContent = $documentData['response'];
        error_log("Found response content in session for machine ID: $machineId");
    } else {
        // Try to get response from Google Drive
        $responseFile = $driveHandler->findFile($machineId, 'response.txt');
        if ($responseFile && isset($responseFile['content']) && !empty($responseFile['content'])) {
            $hasContent = true;
            $responseContent = $responseFile['content'];
            error_log("Found response content in Google Drive for machine ID: $machineId");
            
            // Save to session for future use
            if (!$documentData) {
                $documentData = [];
            }
            $documentData['response'] = $responseContent;
            $_SESSION['document_' . $machineId] = $documentData;
        } else {
            error_log("No response content found for machine ID: $machineId");
        }
    }
    
    // Check if the content is meaningful (not just whitespace or very short)
    if ($hasContent) {
        $trimmedContent = trim($responseContent);
        if (strlen($trimmedContent) < 10) {
            $hasContent = false;
            error_log("Response content is too short for machine ID: $machineId");
        }
    }
    
    // Return verification result
    echo json_encode([
        'success' => true,
        'hasContent' => $hasContent,
        'contentLength' => strlen($responseContent ?? ''),
        'message' => $hasContent ? 'Document content verified' : 'No document content available'
    ]);
    
} catch (Exception $e) {
    error_log('Error in verify_document_content.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'hasContent' => false,
        'message' => $e->getMessage()
    ]);
}
?>
