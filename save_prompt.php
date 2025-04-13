<?php
header('Content-Type: application/json');

// Enable error reporting but log instead of display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Include Google Drive file handler
require_once 'includes/GoogleDriveFileHandler.php';

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// OpenAI API configuration
define('OPENAI_API_KEY', $_ENV['OPENAI_API_KEY']);
define('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions');

try {
    if (!isset($_POST['machineId']) || !isset($_POST['promptText'])) {
        throw new Exception('Machine ID and prompt text are required');
    }

    $machineId = $_POST['machineId'];
    $promptText = $_POST['promptText'];
    
    // Initialize Google Drive file handler
    $driveFileHandler = new GoogleDriveFileHandler();
    
    // Ensure machine folder exists in Google Drive
    $driveFolderId = $driveFileHandler->ensureMachineFolder($machineId);
    if (!$driveFolderId) {
        throw new Exception('Failed to create or access machine folder in Google Drive');
    }

    $preCalculatedCost = isset($_POST['calculatedCost']) ? floatval($_POST['calculatedCost']) : null;
    $preCalculatedInputTokens = isset($_POST['inputTokens']) ? intval($_POST['inputTokens']) : null;
    $preCalculatedOutputTokens = isset($_POST['outputTokens']) ? intval($_POST['outputTokens']) : null;

    if ($preCalculatedCost !== null && $preCalculatedInputTokens !== null && $preCalculatedOutputTokens !== null) {
        $costDetails = [
            'inputTokens' => $preCalculatedInputTokens,
            'outputTokens' => $preCalculatedOutputTokens,
            'inputCostPhp' => round(($preCalculatedInputTokens * 0.0015 / 1000) * 58 * 20, 2),
            'outputCostPhp' => round(($preCalculatedOutputTokens * 0.002 / 1000) * 58 * 20, 2),
            'finalCost' => $preCalculatedCost,
            'timestamp' => date('Y-m-d H:i:s'),
            'isEstimate' => true
        ];
        
        // Save cost details to Google Drive
        $driveFileHandler->writeFile($machineId, 'cost_details.json', json_encode($costDetails));
        
        if (isset($_POST['calculateOnly']) && $_POST['calculateOnly'] === 'true') {
            echo json_encode([
                'success' => true,
                'message' => 'Cost calculated',
                'costDetails' => $costDetails
            ]);
            exit;
        }
    }

    // Save the prompt to Google Drive
    $promptResult = $driveFileHandler->writeFile($machineId, 'prompt.txt', $promptText);
    if (!$promptResult) {
        throw new Exception('Failed to save prompt file to Google Drive');
    }

    // Call OpenAI API to generate response
    $ch = curl_init(OPENAI_API_URL);
    
    $fullResponse = '';
    $messages = [
        ['role' => 'user', 'content' => $promptText]
    ];
    $continueGenerating = true;
    $maxAttempts = 5; // Prevent infinite loops
    $attempts = 0;
    $totalInputTokens = 0;
    $totalOutputTokens = 0;

    while ($continueGenerating && $attempts < $maxAttempts) {
        $data = [
            'model' => 'gpt-3.5-turbo',
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
                ['role' => 'user', 'content' => $promptText],
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
        "Cost calculation: Input tokens=%d (₱%.2f), Output tokens=%d (₱%.2f), Total=₱%d",
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
    
    // Save cost details to Google Drive
    $costResult = $driveFileHandler->writeFile($machineId, 'cost_details.json', json_encode($costDetails));
    if (!$costResult) {
        throw new Exception('Failed to save cost details to Google Drive');
    }

    // Save the complete response to Google Drive
    $responseResult = $driveFileHandler->writeFile($machineId, 'response.txt', $fullResponse);
    if (!$responseResult) {
        throw new Exception('Failed to save response file to Google Drive');
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Prompt saved and response generated'
    ]);

} catch (Exception $e) {
    error_log('Error in save_prompt.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
