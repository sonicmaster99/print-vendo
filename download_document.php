<?php
require_once __DIR__ . '/includes/GoogleDriveHandler.php';

// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Create temp_uploads directory if it doesn't exist
if (!file_exists(__DIR__ . '/temp_uploads')) {
    mkdir(__DIR__ . '/temp_uploads', 0777, true);
    error_log("Created temp_uploads directory");
}

// Check if this is a direct download request or an AJAX request
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === 'true';

try {
    // Check if file and machine ID are provided (accept both GET and POST)
    $machineId = $_POST['machineId'] ?? $_GET['machineId'] ?? null;
    $responseText = $_POST['responseText'] ?? null;
    
    if (!$machineId) {
        throw new Exception('Missing required machine ID parameter');
    }
    
    // Log the request for debugging
    error_log("Download request for machineId: $machineId, AJAX: " . ($isAjax ? 'true' : 'false'));
    
    // Initialize Google Drive handler
    $driveHandler = new GoogleDriveHandler();
    
    // Check if machine exists in Google Drive
    if (!$driveHandler->isMachineIdValid($machineId)) {
        throw new Exception('Invalid machine ID');
    }
    
    // First, check if we already have a PDF for this machine ID
    $existingPdfFiles = glob(__DIR__ . '/temp_uploads/analysis_' . $machineId . '_*.pdf');
    $fileFound = false;
    $localPdfPath = null;
    
    if (!empty($existingPdfFiles)) {
        // Sort by modification time (newest first)
        usort($existingPdfFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // Use the most recent PDF
        $localPdfPath = $existingPdfFiles[0];
        $pdfFileName = basename($localPdfPath);
        
        error_log("Found existing PDF file at: $localPdfPath");
        
        // Check if the file has content
        if (filesize($localPdfPath) > 100) {
            $fileFound = true;
            error_log("Using existing PDF file: $pdfFileName");
        } else {
            // File exists but is too small, might be corrupted
            unlink($localPdfPath);
            error_log("Existing PDF file is too small, deleting: $localPdfPath");
        }
    }
    
    // If no existing PDF found, generate a new one
    if (!$fileFound) {
        // Generate a unique filename based on current date/time and machine ID
        $pdfFileName = 'analysis_' . $machineId . '_' . date('Y-m-d_His') . '.pdf';
        $localPdfPath = __DIR__ . '/temp_uploads/' . $pdfFileName;
        
        // Get response text - either from Google Drive or from POST parameter
        $responseContent = null;
        
        // First try to get from POST parameter (edited response)
        if ($responseText && !empty($responseText)) {
            $responseContent = $responseText;
            error_log("Using provided response text with length: " . strlen($responseContent));
        }
        // Then try to get from Google Drive if not in POST
        else {
            $responseFile = $driveHandler->findFile($machineId, 'response.txt');
            if ($responseFile && isset($responseFile['content']) && !empty($responseFile['content'])) {
                $responseContent = $responseFile['content'];
                error_log("Using response.txt from Google Drive with length: " . strlen($responseContent));
            }
        }
        
        if (!$responseContent) {
            throw new Exception('No response content found for this machine ID');
        }
        
        // Create PDF with the response content
        error_log("Generating PDF from text with length: " . strlen($responseContent));
        
        // Use a more reliable PDF generation method
        $pdfContent = generatePdf($responseContent, $machineId);
        
        if (!$pdfContent || strlen($pdfContent) < 100) {
            // Try alternative PDF generation method
            error_log("Primary PDF generation failed, trying fallback method");
            $pdfContent = createBasicPdf($responseContent, $machineId);
            
            if (!$pdfContent || strlen($pdfContent) < 100) {
                throw new Exception('Failed to generate PDF content using both methods');
            }
        }
        
        // Save the PDF to temp_uploads
        if (file_put_contents($localPdfPath, $pdfContent) === false) {
            throw new Exception('Failed to save PDF to local path');
        }
        
        error_log("Generated and saved new PDF to local path: $localPdfPath with size: " . filesize($localPdfPath));
        $fileFound = true;
        
        // Upload the generated PDF to Google Drive
        $uploadResult = $driveHandler->uploadContent($machineId, $pdfFileName, $pdfContent, 'application/pdf');
        
        if ($uploadResult && isset($uploadResult['id'])) {
            error_log("PDF uploaded to Google Drive with ID: " . $uploadResult['id']);
        } else {
            error_log("Failed to upload PDF to Google Drive");
        }
    }
    
    if (!$fileFound || !$localPdfPath || !file_exists($localPdfPath)) {
        throw new Exception('No content available to generate PDF. Please ensure the document has been processed.');
    }
    
    // Double-check that the file exists and has content
    if (filesize($localPdfPath) < 100) {
        throw new Exception('PDF file is empty or corrupted. Please try again.');
    }
    
    // If this is an AJAX request, return the file path for client-side download
    if ($isAjax) {
        // Return the file info as JSON
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'filePath' => basename($localPdfPath),
            'fileName' => $pdfFileName,
            'fileSize' => filesize($localPdfPath),
            'downloadUrl' => 'download_file.php?file=' . urlencode(basename($localPdfPath))
        ]);
        exit;
    }
    
    // Otherwise, serve the file directly
    $fileSize = filesize($localPdfPath);
    error_log("Serving PDF file with size: $fileSize bytes");
    
    // Set headers for file download
    header('Content-Description: File Transfer');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdfFileName . '"');
    header('Content-Length: ' . $fileSize);
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    // Clear output buffer
    if (ob_get_level()) ob_end_clean();
    flush();
    
    // Use readfile() for more reliable file serving
    readfile($localPdfPath);
    exit;
    
} catch (Exception $e) {
    error_log('Error in download_document.php: ' . $e->getMessage());
    
    // Return error as JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Generate a PDF file from text content
 * 
 * @param string $text The text content
 * @param string $machineId The machine ID (for document title)
 * @return string PDF content
 */
function generatePdf($text, $machineId) {
    try {
        // Create a simple HTML wrapper for the content
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Document Analysis - Machine ID: ' . htmlspecialchars($machineId) . '</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
                h1 { color: #333; }
                p { margin-bottom: 20px; }
            </style>
        </head>
        <body>
            <h1>Document Analysis</h1>
            <h2>Machine ID: ' . htmlspecialchars($machineId) . '</h2>
            <div class="content">' . nl2br(htmlspecialchars($text)) . '</div>
        </body>
        </html>';
        
        // Try to use DOMPDF if available
        if (class_exists('Dompdf\Dompdf')) {
            error_log("Using DOMPDF for PDF generation");
            
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            
            return $dompdf->output();
        }
        // Try to use mPDF if available
        else if (class_exists('Mpdf\Mpdf')) {
            error_log("Using mPDF for PDF generation");
            
            $mpdf = new \Mpdf\Mpdf();
            $mpdf->WriteHTML($html);
            
            return $mpdf->Output('', 'S');
        }
        // Fall back to basic PDF generation
        else {
            error_log("No PDF library available, using basic PDF generation");
            return createBasicPdf($text, $machineId);
        }
    } catch (Exception $e) {
        error_log("Error generating PDF: " . $e->getMessage());
        return createBasicPdf($text, $machineId);
    }
}

/**
 * Create a basic PDF file from text content
 * 
 * @param string $text The text content
 * @param string $machineId The machine ID (for document title)
 * @return string PDF content
 */
function createBasicPdf($text, $machineId) {
    try {
        // Try to use a simple manual PDF generation approach
        error_log("Using manual PDF generation");
        
        // Create a minimal valid PDF
        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>\nendobj\n";
        $pdf .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /Name /F1 /BaseFont /Helvetica >>\nendobj\n";
        
        // Escape special characters in the text
        $escapedText = str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $text);
        $escapedText = "Document Analysis\n\nMachine ID: $machineId\n\n$escapedText";
        
        // Split text into lines to avoid exceeding PDF line length limits
        $lines = explode("\n", $escapedText);
        $content = "";
        $lineCount = 0;
        
        // Add each line to the PDF content
        foreach ($lines as $line) {
            // Skip if line position would be off the page
            if ($lineCount > 45) {
                // Start a new page (simplified approach)
                $content .= "ET\nBT /F1 12 Tf 50 700 Td\n";
                $lineCount = 0;
            }
            
            // Add the line with proper positioning
            $content .= "BT /F1 12 Tf 50 " . (700 - 15 * $lineCount) . " Td ($line) Tj ET\n";
            $lineCount++;
        }
        
        $pdf .= "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream\nendobj\n";
        $pdf .= "xref\n0 6\n0000000000 65535 f\n0000000010 00000 n\n0000000056 00000 n\n0000000111 00000 n\n0000000212 00000 n\n0000000293 00000 n\n";
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . strlen($pdf) . "\n%%EOF";
        
        return $pdf;
    } catch (Exception $e) {
        error_log("Error in createBasicPdf: " . $e->getMessage());
        
        // Return an absolute minimal PDF if all else fails
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Resources<<>>/Contents 4 0 R/Parent 2 0 R>>endobj 4 0 obj<</Length 21>>stream\nBT /F1 12 Tf (Error generating PDF) Tj ET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f\n0000000010 00000 n\n0000000053 00000 n\n0000000102 00000 n\n0000000199 00000 n\ntrailer\n<</Size 5/Root 1 0 R>>\nstartxref\n270\n%%EOF";
    }
}
