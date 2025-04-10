<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/GoogleDriveHandler.php';
require_once __DIR__ . '/vendor/autoload.php';

// Import Dompdf classes explicitly
use Dompdf\Dompdf;
use Dompdf\Options;

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
    $downloadDirectly = isset($_GET['download']) && $_GET['download'] === 'true';
    
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
        try {
            // Look for document files in Google Drive
            $promptFile = $driveHandler->findFile($machineId, 'prompt.txt');
            $responseFile = $driveHandler->findFile($machineId, 'response.txt');
            
            // Check if both files were found
            if (!$promptFile || !$responseFile) {
                throw new Exception('Document data not found or incomplete');
            }
            
            $documentData = [
                'prompt' => isset($promptFile['content']) ? $promptFile['content'] : 'No prompt available',
                'response' => isset($responseFile['content']) ? $responseFile['content'] : 'No response available',
                'documentName' => 'Document from Google Drive'
            ];
        } catch (Exception $e) {
            error_log('Error retrieving document data: ' . $e->getMessage());
            throw new Exception('Failed to retrieve document data: ' . $e->getMessage());
        }
    }
    
    // Create HTML content for PDF
    $htmlContent = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Document Analysis Results</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                margin: 20px;
            }
            h1 {
                color: #2c3e50;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }
            h2 {
                color: #3498db;
                margin-top: 20px;
            }
            .meta {
                color: #7f8c8d;
                font-size: 0.9em;
                margin-top: 30px;
                border-top: 1px solid #eee;
                padding-top: 10px;
            }
        </style>
    </head>
    <body>
        <h1>Document Analysis Results</h1>
        
        <h2>Your Prompt</h2>
        <p>' . htmlspecialchars($documentData['prompt'] ?? 'No prompt available') . '</p>
        
        <h2>ChatGPT Response</h2>
        <div>' . nl2br(htmlspecialchars($documentData['response'] ?? 'No response available')) . '</div>
        
        <div class="meta">
            <p>Generated on: ' . date('Y-m-d H:i:s') . '</p>
            <p>Machine ID: ' . htmlspecialchars($machineId) . '</p>
        </div>
    </body>
    </html>
    ';
    
    // Configure Dompdf
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('isRemoteEnabled', false);
    
    // Create Dompdf instance
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($htmlContent);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Save PDF file locally
    $tempDir = __DIR__ . '/temp_uploads';
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    $documentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $documentData['name'] ?? 'document');
    $pdfFileName = 'analysis_' . $documentName . '_' . date('Ymd_His') . '.pdf';
    $pdfFilePath = $tempDir . '/' . $pdfFileName;
    file_put_contents($pdfFilePath, $dompdf->output());
    
    // Upload PDF file to Google Drive
    $pdfFileResult = $driveHandler->uploadFile($machineId, $pdfFilePath, $pdfFileName);
    
    // If direct download is requested, provide a temporary URL
    $downloadUrl = null;
    if ($downloadDirectly) {
        // Create a temporary download URL
        $downloadUrl = 'download_document.php?file=' . urlencode($pdfFileName) . '&machineId=' . urlencode($machineId);
        
        // Store the file path in session for download
        $_SESSION['download_' . $machineId . '_' . $pdfFileName] = $pdfFilePath;
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Document saved as PDF',
        'pdfLink' => $pdfFileResult['webViewLink'] ?? null,
        'downloadUrl' => $downloadUrl
    ]);
    
    // Don't delete the file if it's going to be downloaded
    if (!$downloadDirectly) {
        unlink($pdfFilePath);
    }
    
} catch (Exception $e) {
    error_log('Error in save_document_pdf.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
