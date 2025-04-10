<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/GoogleDriveHandler.php';

// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

try {
    // Check if all required parameters are provided
    if (!isset($_POST['machineId']) || !isset($_POST['prompt']) || !isset($_POST['model']) || !isset($_FILES['document'])) {
        throw new Exception('Missing required parameters');
    }

    $machineId = $_POST['machineId'];
    $prompt = $_POST['prompt'];
    $model = $_POST['model'];
    $cost = isset($_POST['cost']) ? $_POST['cost'] : 0;
    $document = $_FILES['document'];

    // Validate machine ID
    $driveHandler = new GoogleDriveHandler();
    if (!$driveHandler->isMachineIdValid($machineId)) {
        throw new Exception('Invalid machine ID');
    }

    // Validate document
    if ($document['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error uploading document: ' . getUploadErrorMessage($document['error']));
    }

    // Create temporary directory if it doesn't exist
    $tempDir = __DIR__ . '/temp_uploads';
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0777, true);
    }

    // Generate unique file names
    $documentId = uniqid('doc_');
    $documentPath = $tempDir . '/' . $documentId . '_' . basename($document['name']);
    $promptPath = $tempDir . '/' . $documentId . '_prompt.txt';
    $costPath = $tempDir . '/' . $documentId . '_cost.txt';
    $amountRequestPath = $tempDir . '/' . $documentId . '_amount_request.txt';

    // Move uploaded document to temporary directory
    if (!move_uploaded_file($document['tmp_name'], $documentPath)) {
        throw new Exception('Failed to save uploaded document');
    }

    // Save prompt to file
    file_put_contents($promptPath, $prompt);

    // Create cost file
    $costContent = "Cost Summary\n-------------\n";
    $costContent .= "Document: " . basename($document['name']) . "\n";
    $costContent .= "Model: GPT-" . $model . "\n";
    $costContent .= "Total Cost: ₱" . number_format($cost, 2) . "\n";
    $costContent .= "-------------\n";
    file_put_contents($costPath, $costContent);

    // Create amount request file
    $amountRequestContent = "Cost: PHP " . number_format($cost, 2);
    file_put_contents($amountRequestPath, $amountRequestContent);

    // Upload files to Google Drive
    $documentUploadResult = $driveHandler->uploadFile($machineId, $documentPath, basename($document['name']));
    $promptUploadResult = $driveHandler->uploadFile($machineId, $promptPath, 'prompt.txt');
    $costUploadResult = $driveHandler->uploadFile($machineId, $costPath, 'cost.txt');
    $amountRequestUploadResult = $driveHandler->uploadFile($machineId, $amountRequestPath, 'amount_request.txt');

    // Save document info to session
    session_start();
    $_SESSION['document_' . $machineId] = [
        'id' => $documentId,
        'name' => basename($document['name']),
        'path' => $documentPath,
        'prompt' => $prompt,
        'model' => $model,
        'cost' => $cost,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    // Process document and generate response (this would be done after payment in production)
    // For demo purposes, we'll generate a response immediately
    $processData = [
        'machineId' => $machineId,
        'documentId' => $documentId
    ];
    
    // Create a new cURL resource
    $ch = curl_init();
    
    // Set URL and other appropriate options
    curl_setopt($ch, CURLOPT_URL, 'http://' . $_SERVER['HTTP_HOST'] . '/printgpt/process_document_response.php');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $processData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Execute the request asynchronously (we don't need to wait for the response)
    $processResponse = curl_exec($ch);
    
    // Close cURL resource
    curl_close($ch);

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Document uploaded successfully',
        'documentId' => $documentId,
        'cost' => $cost
    ]);

    // Clean up temporary files (keep them for now for debugging)
    // unlink($documentPath);
    // unlink($promptPath);
    // unlink($costPath);
    // unlink($amountRequestPath);

} catch (Exception $e) {
    error_log('Error in upload_document.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Helper function to get upload error message
function getUploadErrorMessage($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
            return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
        case UPLOAD_ERR_FORM_SIZE:
            return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
        case UPLOAD_ERR_PARTIAL:
            return 'The uploaded file was only partially uploaded';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Missing a temporary folder';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write file to disk';
        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension stopped the file upload';
        default:
            return 'Unknown upload error';
    }
}
