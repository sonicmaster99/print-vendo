<?php
/**
 * PDF to HTML Converter
 * 
 * This script converts uploaded PDF files to HTML format, preserving the layout
 * and formatting as closely as possible to the original PDF.
 * 
 * It uses two methods:
 * 1. pdf2htmlEX (if available) for exact layout preservation
 * 2. Custom fallback method using Smalot PDF Parser for basic layout preservation
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Allow larger file uploads
ini_set('upload_max_filesize', '20M');
ini_set('post_max_size', '20M');
ini_set('max_execution_time', 300);

// Required libraries
require_once __DIR__ . '/vendor/autoload.php';

// Response array
$response = [
    'success' => false,
    'message' => '',
    'htmlFile' => '',
    'title' => '',
    'author' => '',
    'pageCount' => 0,
    'usedExactLayout' => false
];

// Check if file was uploaded
if (!isset($_FILES['pdfFile']) || $_FILES['pdfFile']['error'] !== UPLOAD_ERR_OK) {
    $response['message'] = 'No file uploaded or upload error occurred.';
    echo json_encode($response);
    exit;
}

// Validate file type
$fileType = $_FILES['pdfFile']['type'];
if ($fileType !== 'application/pdf') {
    $response['message'] = 'Invalid file type. Please upload a PDF file.';
    echo json_encode($response);
    exit;
}

// Create upload and output directories if they don't exist
$uploadDir = __DIR__ . '/uploads/';
$outputDir = __DIR__ . '/converted/';

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (!file_exists($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// Generate unique filename
$timestamp = time();
$filename = pathinfo($_FILES['pdfFile']['name'], PATHINFO_FILENAME);
$filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename); // Sanitize filename
$pdfPath = $uploadDir . $filename . '_' . $timestamp . '.pdf';
$htmlPath = $outputDir . $filename . '_' . $timestamp . '.html';
$htmlRelativePath = 'converted/' . $filename . '_' . $timestamp . '.html';

// Move uploaded file to upload directory
if (!move_uploaded_file($_FILES['pdfFile']['tmp_name'], $pdfPath)) {
    $response['message'] = 'Failed to move uploaded file.';
    echo json_encode($response);
    exit;
}

// Check if we should try to preserve exact layout
$preserveExactLayout = isset($_POST['preserveExactLayout']) && $_POST['preserveExactLayout'] === '1';

// Extract PDF metadata using Smalot PDF Parser
try {
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($pdfPath);
    
    // Get document details
    $details = $pdf->getDetails();
    $response['title'] = isset($details['Title']) ? $details['Title'] : 'Untitled';
    $response['author'] = isset($details['Author']) ? $details['Author'] : 'Unknown';
    $response['pageCount'] = count($pdf->getPages());
    
    // Try to convert using pdf2htmlEX for exact layout preservation if requested
    if ($preserveExactLayout) {
        $conversionSuccess = convertWithPdf2HtmlEX($pdfPath, $outputDir, $htmlPath);
        if ($conversionSuccess) {
            $response['success'] = true;
            $response['htmlFile'] = $htmlRelativePath;
            $response['message'] = 'PDF successfully converted to HTML with exact layout preservation.';
            $response['usedExactLayout'] = true;
            echo json_encode($response);
            exit;
        }
    }
    
    // Fallback to custom method if pdf2htmlEX is not available or failed
    $conversionSuccess = convertWithCustomMethod($pdf, $htmlPath);
    if ($conversionSuccess) {
        $response['success'] = true;
        $response['htmlFile'] = $htmlRelativePath;
        $response['message'] = 'PDF successfully converted to HTML with basic layout preservation.';
        $response['usedExactLayout'] = false;
    } else {
        $response['message'] = 'Failed to convert PDF to HTML.';
    }
    
} catch (Exception $e) {
    $response['message'] = 'Error parsing PDF: ' . $e->getMessage();
}

echo json_encode($response);
exit;

/**
 * Convert PDF to HTML using pdf2htmlEX (external command-line tool)
 * This method provides the best layout preservation
 * 
 * @param string $pdfPath Path to the PDF file
 * @param string $outputDir Output directory
 * @param string $htmlPath Path where the HTML file should be saved
 * @return bool True if conversion was successful, false otherwise
 */
function convertWithPdf2HtmlEX($pdfPath, $outputDir, $htmlPath) {
    // Check if pdf2htmlEX is available
    $cmd = 'where pdf2htmlEX 2>&1';
    exec($cmd, $output, $returnVar);
    
    if ($returnVar !== 0) {
        // pdf2htmlEX not found, try alternative paths
        $possiblePaths = [
            'C:\\Program Files\\pdf2htmlEX\\pdf2htmlEX.exe',
            'C:\\pdf2htmlEX\\pdf2htmlEX.exe',
            '/usr/bin/pdf2htmlEX',
            '/usr/local/bin/pdf2htmlEX'
        ];
        
        $found = false;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $cmd = escapeshellarg($path);
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return false; // pdf2htmlEX not found
        }
    } else {
        $cmd = 'pdf2htmlEX';
    }
    
    // Build the command with enhanced options for exact layout preservation
    $outputFilename = basename($htmlPath);
    
    // Core options for exact layout preservation
    $cmd .= ' --zoom 1.5';                   // Higher zoom for better text clarity
    $cmd .= ' --fit-width 0';                // Don't fit to width to preserve exact layout
    $cmd .= ' --embed-font 1';               // Embed fonts
    $cmd .= ' --embed-css 1';                // Embed CSS
    $cmd .= ' --embed-image 1';              // Embed images
    $cmd .= ' --embed-javascript 0';         // Don't embed JavaScript
    $cmd .= ' --split-pages 0';              // Keep all pages in one file
    $cmd .= ' --dest-dir ' . escapeshellarg($outputDir);
    
    // Advanced options for exact positioning
    $cmd .= ' --optimize-text 0';            // Don't optimize text to preserve exact positioning
    $cmd .= ' --correct-text-visibility 1';  // Ensure text is visible
    $cmd .= ' --bg-format png';              // Use PNG for background images
    $cmd .= ' --hdpi 300';                   // Higher DPI for better quality
    $cmd .= ' --vdpi 300';                   // Higher DPI for better quality
    $cmd .= ' --data-dir ' . escapeshellarg($outputDir);
    $cmd .= ' --tmp-dir ' . escapeshellarg(sys_get_temp_dir());
    
    // Text and font handling for exact reproduction
    $cmd .= ' --fallback 1';                 // Use fallback fonts if needed
    $cmd .= ' --tounicode 1';                // Use ToUnicode mapping
    $cmd .= ' --css-draw 0';                 // Don't use CSS drawing
    $cmd .= ' --font-format woff2';          // Use WOFF2 for better compression
    $cmd .= ' --decompose-ligature 1';       // Decompose ligatures
    $cmd .= ' --auto-hint 0';                // Don't use auto-hinting to preserve exact font rendering
    $cmd .= ' --stretch-narrow-glyph 0';     // Don't stretch glyphs
    $cmd .= ' --squeeze-wide-glyph 0';       // Don't squeeze glyphs
    
    // Positioning options
    $cmd .= ' --process-type3 1';            // Process Type 3 fonts
    $cmd .= ' --process-outline 0';          // Don't process outline
    $cmd .= ' --printing 1';                 // Enable printing
    $cmd .= ' --no-drm 1';                   // No DRM
    
    // Final command parts
    $cmd .= ' ' . escapeshellarg($pdfPath);
    $cmd .= ' ' . escapeshellarg($outputFilename);
    $cmd .= ' 2>&1';
    
    // Execute the command
    exec($cmd, $output, $returnVar);
    
    // Check if conversion was successful
    if ($returnVar !== 0) {
        error_log('pdf2htmlEX conversion failed: ' . implode("\n", $output));
        return false;
    }
    
    // Add additional CSS for exact positioning and mobile responsiveness
    $htmlContent = file_get_contents($htmlPath);
    
    // CSS for exact positioning and responsiveness
    $exactLayoutCSS = '
    <style>
        /* Preserve exact positioning */
        .pdf-html-container {
            position: relative;
            margin: 0 auto;
            transform-origin: top left;
        }
        
        /* Ensure text doesn\'t reflow */
        .pdf-text, .pdf-text-block, .pd {
            position: absolute !important;
            white-space: pre !important;
            transform-origin: 0% 0% !important;
        }
        
        /* Responsive scaling for different devices while maintaining exact layout */
        @media (max-width: 1200px) {
            body .pdf-html-container {
                transform: scale(0.9);
                margin: 0 auto;
            }
        }
        @media (max-width: 992px) {
            body .pdf-html-container {
                transform: scale(0.8);
                margin: 0 auto;
            }
        }
        @media (max-width: 768px) {
            body .pdf-html-container {
                transform: scale(0.7);
                margin: 0 auto;
            }
        }
        @media (max-width: 576px) {
            body .pdf-html-container {
                transform: scale(0.6);
                margin: 0 auto;
            }
        }
        
        /* Print styles */
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .pdf-html-container {
                transform: none !important;
            }
        }
        
        /* Fix for form fields */
        .form-field {
            position: relative !important;
            display: inline-block;
            border-bottom: 1px solid #000;
            min-width: 150px;
        }
    </style>';
    
    // Add our custom CSS right before the closing head tag
    $htmlContent = str_replace('</head>', $exactLayoutCSS . '</head>', $htmlContent);
    
    // Write the modified HTML back to the file
    file_put_contents($htmlPath, $htmlContent);
    
    return true;
}

/**
 * Convert PDF to HTML using custom method with Smalot PDF Parser
 * This is a fallback method when pdf2htmlEX is not available
 * 
 * @param object $pdf Parsed PDF object
 * @param string $htmlPath Path where the HTML file should be saved
 * @return bool True if conversion was successful, false otherwise
 */
function convertWithCustomMethod($pdf, $htmlPath) {
    try {
        // Start building HTML content
        $htmlContent = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($pdf->getDetails()['Title'] ?? 'Converted PDF') . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .pdf-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 40px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .pdf-page {
            position: relative;
            margin-bottom: 30px;
            border-bottom: 1px solid #eee;
            padding-bottom: 30px;
        }
        .pdf-text {
            margin-bottom: 10px;
            position: relative;
        }
        .pdf-text-block {
            position: relative;
            margin-bottom: 15px;
        }
        h1, h2, h3 {
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        h1 {
            font-size: 24px;
        }
        h2 {
            font-size: 20px;
        }
        h3 {
            font-size: 18px;
        }
        p {
            margin-bottom: 10px;
        }
        .page-number {
            text-align: center;
            font-size: 12px;
            color: #777;
            margin-top: 20px;
        }
        /* Document title styling */
        .document-title {
            text-align: center;
            font-weight: bold;
            font-size: 26px;
            margin: 20px 0 30px 0;
        }
        /* Section headings */
        .section-heading {
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 25px;
            margin-bottom: 15px;
        }
        /* Form fields styling */
        .form-field {
            border-bottom: 1px solid #ccc;
            display: inline-block;
            min-width: 150px;
            margin: 0 5px;
        }
        @media print {
            body {
                background-color: #fff;
            }
            .pdf-container {
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="pdf-container">';
        
        // Process each page
        $pages = $pdf->getPages();
        $pageNumber = 1;
        
        foreach ($pages as $page) {
            $htmlContent .= '<div class="pdf-page" id="page-' . $pageNumber . '">';
            
            // Extract text from the page
            $text = $page->getText();
            
            // Process text to identify potential headers, paragraphs, etc.
            $lines = explode("\n", $text);
            $currentParagraph = '';
            $inParagraph = false;
            $firstLine = true;
            
            foreach ($lines as $line) {
                $line = trim($line);
                
                // Skip empty lines
                if (empty($line)) {
                    if ($inParagraph) {
                        $htmlContent .= '<p class="pdf-text">' . htmlspecialchars($currentParagraph) . '</p>';
                        $currentParagraph = '';
                        $inParagraph = false;
                    }
                    continue;
                }
                
                // Check if this is the document title (usually the first non-empty line)
                if ($firstLine && $pageNumber === 1 && preg_match('/^[A-Z\s\-]+$/', $line)) {
                    $htmlContent .= '<p class="pdf-text document-title">' . htmlspecialchars($line) . '</p>';
                    $firstLine = false;
                    continue;
                }
                $firstLine = false;
                
                // Check if line is a potential header
                if (strlen($line) < 100 && (preg_match('/^[A-Z\s]+$/', $line) || preg_match('/^[IVX]+\.\s/', $line) || preg_match('/^\d+\.\s/', $line))) {
                    // If we were building a paragraph, close it
                    if ($inParagraph) {
                        $htmlContent .= '<p class="pdf-text">' . htmlspecialchars($currentParagraph) . '</p>';
                        $currentParagraph = '';
                        $inParagraph = false;
                    }
                    
                    // Determine header level
                    $headerLevel = 2;
                    if (strlen($line) < 30) {
                        $headerLevel = 1;
                    } else if (strlen($line) > 60) {
                        $headerLevel = 3;
                    }
                    
                    // Add section-heading class for uppercase headers
                    $headerClass = preg_match('/^[A-Z\s\-]+$/', $line) ? ' class="pdf-text section-heading"' : ' class="pdf-text"';
                    
                    $htmlContent .= '<h' . $headerLevel . $headerClass . '>' . htmlspecialchars($line) . '</h' . $headerLevel . '>';
                } else {
                    // Check for form fields (lines with underscores)
                    if (strpos($line, '____') !== false) {
                        // Replace underscores with styled spans
                        $line = preg_replace('/_{3,}/', '<span class="form-field">&nbsp;</span>', $line);
                        $htmlContent .= '<p class="pdf-text">' . $line . '</p>';
                    } else {
                        // Treat as paragraph text
                        if ($inParagraph) {
                            $currentParagraph .= ' ' . $line;
                        } else {
                            $currentParagraph = $line;
                            $inParagraph = true;
                        }
                    }
                }
            }
            
            // Close any open paragraph
            if ($inParagraph) {
                $htmlContent .= '<p class="pdf-text">' . htmlspecialchars($currentParagraph) . '</p>';
            }
            
            // Add page number
            $htmlContent .= '<div class="page-number">Page ' . $pageNumber . '</div>';
            $htmlContent .= '</div>'; // Close pdf-page
            
            $pageNumber++;
        }
        
        // Close HTML structure
        $htmlContent .= '    </div>
</body>
</html>';
        
        // Write HTML content to file
        file_put_contents($htmlPath, $htmlContent);
        
        return true;
    } catch (Exception $e) {
        error_log('Custom conversion failed: ' . $e->getMessage());
        return false;
    }
}
?>
