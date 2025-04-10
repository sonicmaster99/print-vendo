<?php
// Set proper content type header before any output
header('Content-Type: application/json');

// Enable error reporting for debugging but capture errors instead of displaying them
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// OpenAI API configuration
define('OPENAI_API_KEY', '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');
define('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions');

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

    // Log the start of the script
    error_log('Upload script started at ' . date('Y-m-d H:i:s'));

    // Configuration
    $uploadBaseDir = 'temp_uploads/'; // Temporary storage directory
    $useGoogleDrive = true; // Set to true to enable Google Drive uploads

    // Log POST data
    error_log('POST data: ' . print_r($_POST, true));
    error_log('FILES data: ' . print_r($_FILES, true));

    // Check if this is a ChatGPT files saving request
    if (isset($_POST['action']) && $_POST['action'] === 'saveChatGPTFiles') {
        error_log('Processing ChatGPT files save request');
        $machineId = $_POST['machineId'];
        $fileId = $_POST['fileId'];
        $amount = $_POST['amount'];

        // Create machine folder name
        $machineFolder = 'machine_' . strtolower($machineId);
        $machineDir = $uploadBaseDir . $machineFolder . '/';

        // Ensure directories exist
        if (!file_exists($uploadBaseDir)) {
            error_log('Creating upload directory: ' . $uploadBaseDir);
            if (!mkdir($uploadBaseDir, 0777, true)) {
                throw new Exception('Failed to create upload directory: ' . $uploadBaseDir);
            }
        }

        if (!file_exists($machineDir)) {
            error_log('Creating machine directory: ' . $machineDir);
            if (!mkdir($machineDir, 0777, true)) {
                throw new Exception('Failed to create machine directory: ' . $machineDir);
            }
        }

        // Handle amount file
        $amountText = "";
        $costFilePath = $machineDir . 'Amount_request.txt';
        if (file_put_contents($costFilePath, $amountText) === false) {
            throw new Exception('Failed to write cost to file: ' . $costFilePath);
        }

        // Handle prompt file
        if (!isset($_FILES['promptFile'])) {
            throw new Exception('No prompt file uploaded');
        }

        $promptFile = $_FILES['promptFile'];
        if ($promptFile['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Prompt file upload failed with error code: ' . $promptFile['error']);
        }

        $promptPath = $machineDir . $fileId . '.txt';
        if (!move_uploaded_file($promptFile['tmp_name'], $promptPath)) {
            throw new Exception('Failed to move prompt file');
        }

        // Read the prompt content
        $promptContent = file_get_contents($promptPath);
        if ($promptContent === false) {
            throw new Exception('Failed to read prompt file');
        }

        // Call OpenAI API to generate response
        $ch = curl_init(OPENAI_API_URL);
        
        $data = [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'user', 'content' => $promptContent]
            ],
            'temperature' => 0.7,
            'max_tokens' => 2000,
            'top_p' => 1.0,
            'frequency_penalty' => 0,
            'presence_penalty' => 0
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
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('OpenAI API request failed with status ' . $httpCode);
        }

        $responseData = json_decode($response, true);
        if (!isset($responseData['choices'][0]['message']['content'])) {
            throw new Exception('Invalid response from OpenAI API');
        }

        $generatedResponse = $responseData['choices'][0]['message']['content'];
        
        // Save the response
        $responseFile = $machineDir . 'response.txt';
        if (file_put_contents($responseFile, $generatedResponse) === false) {
            throw new Exception('Failed to save response file');
        }

        // Upload both files to Google Drive if enabled
        $driveFileIds = [];
        $driveViewLinks = [];

        if ($useGoogleDrive && file_exists(__DIR__ . '/includes/GoogleDriveHandler.php')) {
            try {
                require_once __DIR__ . '/includes/GoogleDriveHandler.php';
                $driveHandler = new GoogleDriveHandler();

                // Upload prompt file
                $promptResult = $driveHandler->uploadFile(
                    $machineId,
                    $promptPath,
                    $fileId . '.txt'
                );

                if ($promptResult && isset($promptResult['id'])) {
                    $driveFileIds['prompt'] = $promptResult['id'];
                    $driveViewLinks['prompt'] = $promptResult['webViewLink'];
                }

                // Upload amount file
                $amountResult = $driveHandler->uploadFile(
                    $machineId,
                    $costFilePath,
                    'Amount_request.txt'
                );

                if ($amountResult && isset($amountResult['id'])) {
                    $driveFileIds['amount'] = $amountResult['id'];
                    $driveViewLinks['amount'] = $amountResult['webViewLink'];
                }

                // Upload response file
                $responseResult = $driveHandler->uploadFile(
                    $machineId,
                    $responseFile,
                    'response.txt'
                );

                if ($responseResult && isset($responseResult['id'])) {
                    $driveFileIds['response'] = $responseResult['id'];
                    $driveViewLinks['response'] = $responseResult['webViewLink'];
                }

            } catch (Exception $e) {
                error_log('Google Drive upload error: ' . $e->getMessage());
                // Continue without Google Drive upload
            }
        }

        // Return success response with file information
        echo json_encode([
            'success' => true,
            'message' => 'Files saved successfully',
            'files' => [
                'prompt' => $fileId . '.txt',
                'amount' => 'Amount_request.txt',
                'response' => 'response.txt'
            ],
            'driveFileIds' => $driveFileIds,
            'driveViewLinks' => $driveViewLinks
        ]);
        exit;
    }

    // Handle regular file upload
    // Get form data
    $machineId = isset($_POST['machineId']) ? $_POST['machineId'] : '';
    $fileId = isset($_POST['fileId']) ? $_POST['fileId'] : '';
    $pageInfo = isset($_POST['pageInfo']) ? $_POST['pageInfo'] : '{}';
    $printMode = isset($_POST['printMode']) ? $_POST['printMode'] : 'bw';

    error_log("machineId: $machineId, fileId: $fileId, printMode: $printMode");

    if (empty($machineId) || empty($fileId)) {
        throw new Exception('Missing required parameters: machineId and fileId are required');
    }

    // Create machine folder name
    $machineFolder = 'machine_' . strtolower($machineId);

    // Create temporary storage directory if it doesn't exist
    if (!file_exists($uploadBaseDir)) {
        error_log('Creating upload directory: ' . $uploadBaseDir);
        if (!mkdir($uploadBaseDir, 0777, true)) {
            throw new Exception('Failed to create upload directory: ' . $uploadBaseDir);
        }
    }

    // Create machine-specific directory if it doesn't exist
    $machineDir = $uploadBaseDir . $machineFolder . '/';
    if (!file_exists($machineDir)) {
        error_log('Creating machine directory: ' . $machineDir);
        if (!mkdir($machineDir, 0777, true)) {
            throw new Exception('Failed to create machine directory: ' . $machineDir);
        }
    }

    // Check if file was uploaded
    if (!isset($_FILES['file'])) {
        throw new Exception('No file uploaded');
    }

    if (!is_uploaded_file($_FILES['file']['tmp_name'])) {
        throw new Exception('Invalid file upload');
    }

    $file = $_FILES['file'];
    error_log('File upload details: ' . print_r($file, true));

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = 'File upload failed: ';
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
                $errorMessage .= 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $errorMessage .= 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMessage .= 'The uploaded file was only partially uploaded';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMessage .= 'No file was uploaded';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $errorMessage .= 'Missing a temporary folder';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $errorMessage .= 'Failed to write file to disk';
                break;
            case UPLOAD_ERR_EXTENSION:
                $errorMessage .= 'A PHP extension stopped the file upload';
                break;
            default:
                $errorMessage .= 'Unknown upload error';
        }
        throw new Exception($errorMessage);
    }

    // Get file extension and create new filename
    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newFileName = $fileId . '.' . $fileExtension;
    $tempPath = $machineDir . $newFileName;

    error_log("Moving uploaded file from {$file['tmp_name']} to $tempPath");

    // Move uploaded file to destination
    if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
        throw new Exception('Failed to move uploaded file to destination. Check folder permissions.');
    }

    error_log('File moved successfully to: ' . $tempPath);

    // Initialize response data
    $responseData = [
        'success' => true,
        'message' => 'File uploaded successfully',
        'fileId' => $fileId,
        'localPath' => $tempPath
    ];

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

                // Upload to Google Drive
                $driveResult = $driveHandler->uploadFile($machineId, $tempPath, $newFileName);

                // Get drive file ID and view link
                $driveFileId = $driveResult['fileId'];
                $driveViewLink = $driveResult['webViewLink'];

                error_log("Google Drive upload successful. File ID: $driveFileId, Link: $driveViewLink");

                // Add Google Drive info to response
                $responseData['driveFileId'] = $driveFileId;
                $responseData['driveViewLink'] = $driveViewLink;
                $responseData['message'] = 'File uploaded successfully to Google Drive';

                // Delete local file after successful upload to Google Drive
                if ($driveFileId) {
                    error_log("Deleting local file after successful Google Drive upload: $tempPath");
                    @unlink($tempPath);
                    $tempPath = 'GOOGLE_DRIVE:' . $driveFileId;
                }
            } catch (Exception $driveError) {
                // Log the Google Drive error but continue with local storage
                error_log('Google Drive upload failed, falling back to local storage: ' . $driveError->getMessage());
                $responseData['driveError'] = $driveError->getMessage();
                $responseData['message'] = 'File uploaded successfully (local storage only - Google Drive upload failed: ' . $driveError->getMessage() . ')';
            }
        } else {
            error_log('GoogleDriveHandler.php not found, skipping Google Drive upload');
            $responseData['message'] = 'File uploaded successfully (local storage only - Google Drive not configured)';
        }
    }

    // Create metadata
    $metadata = [
        'id' => $fileId,
        'machineId' => $machineId,
        'machineFolder' => $machineFolder,
        'originalName' => $file['name'],
        'storedName' => $newFileName,
        'size' => $file['size'],
        'type' => $file['type'],
        'printMode' => $printMode,
        'pageInfo' => json_decode($pageInfo, true),
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'completed',
        'localPath' => $tempPath
    ];

    // Add Google Drive information if available
    if ($driveFileId) {
        $metadata['driveFileId'] = $driveFileId;
        $metadata['driveViewLink'] = $driveViewLink;
    }

    // Save metadata to file
    $metadataPath = $machineDir . $fileId . '_metadata.json';
    error_log('Saving metadata to: ' . $metadataPath);

    if (file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT)) === false) {
        throw new Exception('Failed to save metadata file: ' . $metadataPath);
    }

    error_log('Upload completed successfully');

    // Return success response
    echo json_encode($responseData);
} catch (Exception $e) {
    // Log error
    error_log('Upload error: ' . $e->getMessage());

    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug_info' => [
            'post_data' => $_POST,
            'files_data' => isset($_FILES) ? array_keys($_FILES) : 'No files',
            'php_version' => PHP_VERSION
        ]
    ]);
}
