<?php
// Set headers to prevent caching
header('Content-Type: application/json');

// Error reporting for debugging but capture errors instead of displaying them
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Start output buffering to catch any unexpected output
ob_start();

try {
    // Ensure we always return JSON, even if there's a fatal error
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Fatal PHP error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'],
            ]);
            exit;
        }
    });

    // Configuration
    $uploadBaseDir = 'uploads/'; // Base storage directory
    $useGoogleDrive = true; // Set to true to enable Google Drive uploads

    // Get the machine ID, content, and color mode from POST request
    $machineId = isset($_POST['machineId']) ? $_POST['machineId'] : '';
    $content = isset($_POST['content']) ? $_POST['content'] : '';
    $colorMode = isset($_POST['colorMode']) ? $_POST['colorMode'] : 'bw';

    // Validate inputs
    if (empty($machineId)) {
        throw new Exception('Machine ID is required');
    }

    if (empty($content)) {
        throw new Exception('Document content is required');
    }

    // Create uploads directory if it doesn't exist
    $uploadsDir = __DIR__ . '/' . $uploadBaseDir . $machineId;
    if (!file_exists($uploadsDir)) {
        if (!mkdir($uploadsDir, 0777, true)) {
            throw new Exception('Failed to create upload directory: ' . $uploadsDir);
        }
    }

    // Generate a unique filename
    $timestamp = time();
    $fileId = 'legal_document_' . $timestamp;
    $filename = $uploadsDir . '/' . $fileId . '.pdf';
    $htmlFilename = $uploadsDir . '/' . $fileId . '.html';

    // Create a complete HTML document
    $htmlContent = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Legal Document</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.5;
                color: #000;
            }
            h4 {
                text-align: center;
                margin-bottom: 20px;
            }
            p {
                margin-bottom: 10px;
            }
        </style>
    </head>
    <body>' . $content . '</body>
    </html>';

    // Save HTML version for backup
    file_put_contents($htmlFilename, $htmlContent);

    // Initialize response data
    $responseData = [
        'success' => true,
        'message' => 'Document generated successfully',
        'fileId' => $fileId,
        'localPath' => $filename
    ];

    // Try to generate PDF
    $pdfGenerated = false;

    // Try to use mPDF if available
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        try {
            require_once __DIR__ . '/vendor/autoload.php';
            
            // Check if mPDF class exists before trying to use it
            if (class_exists('\Mpdf\Mpdf')) {
                // Create mPDF instance
                $mpdf = new \Mpdf\Mpdf([
                    'mode' => 'utf-8',
                    'format' => 'A4',
                    'margin_left' => 15,
                    'margin_right' => 15,
                    'margin_top' => 16,
                    'margin_bottom' => 16
                ]);
                
                // Set document metadata
                $mpdf->SetTitle('Legal Document');
                $mpdf->SetAuthor('Document Generator');
                
                // Write the content to the PDF
                $mpdf->WriteHTML($htmlContent);
                
                // Save the PDF
                $mpdf->Output($filename, 'F');
                
                $pdfGenerated = true;
                $responseData['message'] = 'PDF generated successfully';
            } else {
                // mPDF class doesn't exist even though vendor/autoload.php exists
                error_log('mPDF class not found. To install mPDF, run: composer require mpdf/mpdf');
                $responseData['pdfError'] = 'mPDF library not installed. To install, run: composer require mpdf/mpdf';
                $responseData['message'] = 'Generated HTML document (PDF library not installed)';
            }
        } catch (Exception $e) {
            // Log the error but continue with HTML
            error_log('mPDF error: ' . $e->getMessage());
            $responseData['pdfError'] = $e->getMessage();
            $responseData['message'] = 'Generated HTML document (PDF generation failed)';
        }
    } else {
        // No vendor/autoload.php found
        error_log('Composer autoload not found. To set up PDF generation:
        1. Install Composer from https://getcomposer.org/
        2. Run: composer require mpdf/mpdf
        ');
        $responseData['pdfError'] = 'PDF generation requires Composer and mPDF. See server logs for installation instructions.';
        $responseData['message'] = 'Generated HTML document (PDF library not available)';
    }

    // Try to upload to Google Drive if enabled
    $driveFileId = null;
    $driveViewLink = null;

    if ($useGoogleDrive) {
        error_log('Attempting to upload to Google Drive');

        // Check if GoogleDriveHandler.php exists
        if (file_exists(__DIR__ . '/includes/GoogleDriveHandler.php')) {
            try {
                // Check PHP version compatibility before attempting to use Google Drive
                if (version_compare(PHP_VERSION, '8.0.0', '<')) {
                    throw new Exception('PHP version incompatibility: Google Drive integration requires PHP 8.0.0 or higher. Current version: ' . PHP_VERSION);
                }
                
                // Check if vendor/autoload.php exists
                if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
                    throw new Exception('Composer dependencies not installed. Run "composer install" to use Google Drive integration.');
                }
                
                // Try to include the GoogleDriveHandler
                require_once __DIR__ . '/includes/GoogleDriveHandler.php';

                // Initialize Google Drive handler
                $driveHandler = new GoogleDriveHandler();
                
                // Determine which file to upload (PDF or HTML)
                $fileToUpload = $pdfGenerated ? $filename : $htmlFilename;
                $fileName = basename($fileToUpload);
                
                // Verify file exists before attempting upload
                if (!file_exists($fileToUpload)) {
                    throw new Exception("File not found: $fileToUpload");
                }
                
                error_log("Uploading file: $fileToUpload");
                
                // Upload the file to Google Drive - note the correct parameter order: machineId, filePath, fileName
                $uploadResult = $driveHandler->uploadFile($machineId, $fileToUpload, $fileName);
                
                if ($uploadResult && isset($uploadResult['fileId'])) {
                    $driveFileId = $uploadResult['fileId'];
                    $driveViewLink = isset($uploadResult['webViewLink']) ? $uploadResult['webViewLink'] : null;
                    
                    $responseData['driveFileId'] = $driveFileId;
                    $responseData['driveViewLink'] = $driveViewLink;
                    $responseData['message'] .= ' and uploaded to Google Drive';
                    
                    error_log("Successfully uploaded to Google Drive. File ID: $driveFileId");
                } else {
                    throw new Exception('Google Drive upload failed: Invalid response from Drive API');
                }
                
            } catch (Exception $e) {
                error_log('Google Drive upload error: ' . $e->getMessage());
                $responseData['driveError'] = 'Failed to upload file to Google Drive: ' . $e->getMessage();
            }
        } else {
            $responseData['driveError'] = 'Google Drive integration not available: GoogleDriveHandler.php not found';
            error_log('GoogleDriveHandler.php not found in ' . __DIR__ . '/includes/');
        }
    }

    // Clean up - remove verification file as the process is complete
    $verificationFile = $uploadsDir . '/ChatGpt_Amount_Satisfied.txt';
    if (file_exists($verificationFile)) {
        unlink($verificationFile);
    }

    // Clear any output buffered content
    ob_end_clean();
    
    // Return success response
    echo json_encode($responseData);
    
} catch (Exception $e) {
    // Log error
    error_log('PDF generation error: ' . $e->getMessage());
    
    // Clear any output buffered content
    ob_end_clean();
    
    // Return error response
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
