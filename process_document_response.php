<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/GoogleDriveHandler.php';
require_once __DIR__ . '/includes/GoogleDriveFileHandler.php';

// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// OpenAI API configuration
define('OPENAI_API_KEY', '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');
define('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions');

try {
    // Check if all required parameters are provided
    if (!isset($_POST['machineId']) || !isset($_POST['documentId'])) {
        throw new Exception('Missing required parameters');
    }

    $machineId = $_POST['machineId'];
    $documentId = $_POST['documentId'];
    
    // Start session to get document data
    session_start();
    
    // Initialize Google Drive handlers
    $driveHandler = new GoogleDriveHandler();
    $fileHandler = new GoogleDriveFileHandler();
    
    // Check if machine exists in Google Drive
    if (!$driveHandler->isMachineIdValid($machineId)) {
        throw new Exception('Invalid machine ID');
    }
    
    // Get document data from session
    $documentData = isset($_SESSION['document_' . $machineId]) ? $_SESSION['document_' . $machineId] : null;
    
    if (!$documentData) {
        // Try to get document data from Google Drive
        $promptFile = $driveHandler->findFile($machineId, 'prompt.txt');
        if (!$promptFile) {
            throw new Exception('Document data not found');
        }
        
        // Create document data from Google Drive files
        $documentData = [
            'prompt' => $promptFile['content'] ?? '',
            'name' => 'Document from Google Drive',
            'model' => '3.5' // Default to GPT-3.5
        ];
        
        // Look for the document in temp_uploads
        $tempDir = __DIR__ . '/temp_uploads';
        $possiblePaths = glob($tempDir . '/*.pdf');
        $documentContent = '';
        
        if (!empty($possiblePaths)) {
            // Use the first PDF file found
            $localPath = $possiblePaths[0];
            $documentData['name'] = basename($localPath);
            
            // Extract text from PDF
            $documentContent = extractTextFromPDF($localPath);
            error_log("Extracted text from local PDF: " . substr($documentContent, 0, 100) . "...");
        } else {
            // If no PDF found, use a default message
            error_log("No PDF document found in temp_uploads directory");
            $documentContent = "No document content available. Please upload a document.";
        }
        
        $documentData['content'] = $documentContent;
    } else {
        // If we have document data in session, check if we have content
        if (empty($documentData['content'])) {
            // Try to extract content from the document path
            if (isset($documentData['path']) && file_exists($documentData['path'])) {
                $documentData['content'] = extractTextFromPDF($documentData['path']);
                error_log("Extracted text from session PDF: " . substr($documentData['content'], 0, 100) . "...");
            } else {
                // Look for the document in temp_uploads
                $tempDir = __DIR__ . '/temp_uploads';
                $possiblePaths = glob($tempDir . '/*');
                
                foreach ($possiblePaths as $path) {
                    if (strpos($path, $documentId) !== false && pathinfo($path, PATHINFO_EXTENSION) === 'pdf') {
                        $documentData['content'] = extractTextFromPDF($path);
                        error_log("Found and extracted text from temp PDF: " . substr($documentData['content'], 0, 100) . "...");
                        break;
                    }
                }
                
                // If still no content, try any PDF in the directory
                if (empty($documentData['content'])) {
                    $pdfFiles = glob($tempDir . '/*.pdf');
                    if (!empty($pdfFiles)) {
                        $documentData['content'] = extractTextFromPDF($pdfFiles[0]);
                        error_log("Using first available PDF: " . basename($pdfFiles[0]));
                    }
                }
            }
        }
    }
    
    // Get prompt and document content
    $prompt = $documentData['prompt'] ?? '';
    $documentName = $documentData['name'] ?? 'Unknown document';
    $documentContent = $documentData['content'] ?? '';
    $model = $documentData['model'] ?? '3.5';
    
    // Log document information for debugging
    error_log("Processing document: $documentName");
    error_log("Document content length: " . strlen($documentContent));
    error_log("Document content sample: " . substr($documentContent, 0, 100) . "...");
    
    // Determine which model to use based on the selected model
    $modelName = 'gpt-3.5-turbo';
    if ($model === '4.0') {
        $modelName = 'gpt-4';
    } elseif ($model === '4.5') {
        $modelName = 'gpt-4-turbo';
    }
    
    // Prepare the prompt with document content
    $fullPrompt = "Document Name: $documentName\n\nDocument Content:\n$documentContent\n\nUser Prompt: $prompt\n\nPlease analyze this document based on the user's prompt.";
    
    // Call OpenAI API to generate response
    $ch = curl_init(OPENAI_API_URL);
    
    $fullResponse = '';
    $messages = [
        ['role' => 'user', 'content' => $fullPrompt]
    ];
    $continueGenerating = true;
    $maxAttempts = 5; // Prevent infinite loops
    $attempts = 0;
    $totalInputTokens = 0;
    $totalOutputTokens = 0;

    while ($continueGenerating && $attempts < $maxAttempts) {
        $data = [
            'model' => $modelName,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'top_p' => 1.0,
            'frequency_penalty' => 0.1,
            'presence_penalty' => 0.1,
            'stop' => null
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . OPENAI_API_KEY
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            curl_close($ch);
            throw new Exception('OpenAI API request failed with status ' . $httpCode);
        }

        $responseData = json_decode($response, true);
        if (!isset($responseData['choices'][0]['message']['content'])) {
            curl_close($ch);
            throw new Exception('Invalid response from OpenAI API');
        }

        // Track token usage
        if (isset($responseData['usage'])) {
            $totalInputTokens += $responseData['usage']['prompt_tokens'];
            $totalOutputTokens += $responseData['usage']['completion_tokens'];
        }

        $currentResponse = $responseData['choices'][0]['message']['content'];
        $fullResponse .= $currentResponse;

        // Check if the response seems complete
        if (preg_match('/(?:\.|!|\?)\s*$/', trim($currentResponse))) {
            $continueGenerating = false;
        } else {
            // Add the current response as context and ask for continuation
            $messages = [
                ['role' => 'user', 'content' => $fullPrompt],
                ['role' => 'assistant', 'content' => $fullResponse],
                ['role' => 'user', 'content' => 'Please continue from where you left off.']
            ];
        }

        $attempts++;
    }

    curl_close($ch);

    // Calculate final cost
    $usdPerInputToken = 0.0015 / 1000;  // $0.0015 per 1K input tokens
    $usdPerOutputToken = 0.002 / 1000;   // $0.002 per 1K output tokens
    $usdToPhp = 58;
    $markup = 20;

    // Calculate costs in USD
    $inputCostUsd = $totalInputTokens * $usdPerInputToken;
    $outputCostUsd = $totalOutputTokens * $usdPerOutputToken;
    
    // Convert to PHP with markup
    $totalCostPhp = ($inputCostUsd + $outputCostUsd) * $usdToPhp * $markup;
    
    // Calculate final cost without minimum enforcement
    $finalCost = ceil($totalCostPhp);

    // Debug log the cost calculation
    error_log(sprintf(
        "Document analysis cost calculation: Input tokens=%d (₱%.2f), Output tokens=%d (₱%.2f), Total=₱%d",
        $totalInputTokens,
        $inputCostUsd * $usdToPhp * $markup,
        $totalOutputTokens,
        $outputCostUsd * $usdToPhp * $markup,
        $finalCost
    ));

    // Save cost details
    $costDetails = [
        'inputTokens' => $totalInputTokens,
        'outputTokens' => $totalOutputTokens,
        'inputCostPhp' => round($inputCostUsd * $usdToPhp * $markup, 2),
        'outputCostPhp' => round($outputCostUsd * $usdToPhp * $markup, 2),
        'finalCost' => $finalCost,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Save cost details to Google Drive using the file handler
    $costJson = json_encode($costDetails);
    $costResult = $fileHandler->writeFile($machineId, 'cost_details.json', $costJson);
    if (!$costResult) {
        throw new Exception('Failed to save cost details to Google Drive');
    }
    
    // Save response to session
    $_SESSION['document_' . $machineId]['response'] = $fullResponse;
    
    // Upload response to Google Drive using the file handler
    $responseResult = $fileHandler->writeFile($machineId, 'response.txt', $fullResponse);
    if (!$responseResult) {
        throw new Exception('Failed to save response to Google Drive');
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Document processed successfully',
        'response' => $fullResponse
    ]);
    
} catch (Exception $e) {
    error_log('Error in process_document_response.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Extract text from a PDF file
 * 
 * @param string $pdfPath Path to the PDF file
 * @return string Extracted text
 */
function extractTextFromPDF($pdfPath) {
    // Check if the file exists
    if (!file_exists($pdfPath)) {
        error_log("PDF file not found: $pdfPath");
        return "PDF file not found";
    }
    
    // Check if the file is a PDF
    if (strtolower(pathinfo($pdfPath, PATHINFO_EXTENSION)) !== 'pdf') {
        error_log("File is not a PDF: $pdfPath");
        return "File is not a PDF";
    }
    
    // Try to extract text using pdftotext if available
    if (function_exists('shell_exec')) {
        $command = "pdftotext \"$pdfPath\" -";
        $text = shell_exec($command);
        
        if ($text) {
            return $text;
        }
    }
    
    // Fallback: Try to read the PDF directly
    $content = file_get_contents($pdfPath);
    
    // Basic text extraction (very limited)
    $text = '';
    if (preg_match_all('/\/Text\s*\[(.*?)\]/', $content, $matches)) {
        foreach ($matches[1] as $match) {
            $text .= $match . ' ';
        }
    }
    
    // If we couldn't extract any text, return a message
    if (empty($text)) {
        // Try to extract text between stream and endstream tags
        if (preg_match_all('/stream(.*?)endstream/s', $content, $streams)) {
            foreach ($streams[1] as $stream) {
                // Try to decode the stream
                $decoded = @gzuncompress($stream);
                if ($decoded) {
                    $text .= $decoded . ' ';
                } else {
                    // Try to extract any readable text
                    $text .= preg_replace('/[^\x20-\x7E]/', ' ', $stream) . ' ';
                }
            }
        }
    }
    
    // If we still couldn't extract any text, return a message
    if (empty($text)) {
        error_log("Could not extract text from PDF: $pdfPath");
        return "This appears to be a PDF file, but the text could not be extracted. The document might be scanned or contain images of text.";
    }
    
    return $text;
}
