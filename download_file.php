<?php
// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

try {
    // Get the file parameter
    $fileName = isset($_GET['file']) ? $_GET['file'] : null;
    
    if (!$fileName) {
        throw new Exception('No file specified');
    }
    
    // Sanitize the filename to prevent directory traversal
    $fileName = basename($fileName);
    
    // Construct the file path
    $filePath = __DIR__ . '/temp_uploads/' . $fileName;
    
    // Check if the file exists
    if (!file_exists($filePath)) {
        error_log("File not found: $filePath");
        throw new Exception('File not found: ' . $fileName);
    }
    
    // Get the file size
    $fileSize = filesize($filePath);
    if ($fileSize < 100) {
        error_log("File is too small: $filePath ($fileSize bytes)");
        throw new Exception('File is empty or corrupted: ' . $fileName);
    }
    
    // Log the download
    error_log("Serving file: $fileName, size: $fileSize bytes");
    
    // Set headers for file download
    header('Content-Description: File Transfer');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . $fileSize);
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    // Clear output buffer
    if (ob_get_level()) ob_end_clean();
    flush();
    
    // Read the file in chunks to handle large files
    readfile($filePath);
    exit;
    
} catch (Exception $e) {
    error_log('Error in download_file.php: ' . $e->getMessage());
    
    // Return error as JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
