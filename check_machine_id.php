<?php
header('Content-Type: application/json');

// Error handling
try {
    // Check if machine ID is provided
    if (!isset($_POST['machineId'])) {
        throw new Exception('Machine ID is required');
    }

    $machineId = $_POST['machineId'];
    
    // Validate machine ID format (optional additional validation)
    if (empty($machineId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $machineId)) {
        throw new Exception('Invalid machine ID format');
    }

    // Debug log
    error_log("Validating machine ID: $machineId");

    // Include the GoogleDriveHandler
    require_once __DIR__ . '/includes/GoogleDriveHandler.php';

    // Initialize the Google Drive handler
    $driveHandler = new GoogleDriveHandler();

    // Validate machine ID against Google Drive
    $isValid = $driveHandler->isMachineIdValid($machineId);
    error_log("Google Drive validation result for machine ID $machineId: " . ($isValid ? 'Valid' : 'Invalid'));
    
    if ($isValid) {
        echo json_encode([
            'success' => true,
            'message' => 'Machine ID validated',
            'machineId' => $machineId
        ]);
    } else {
        // Get the root folder ID for debugging
        $rootFolderId = $driveHandler->getRootFolderId();
        error_log("Root folder ID: $rootFolderId");
        error_log("Machine folder name being searched: machine_$machineId");
        throw new Exception('Invalid machine ID. This machine is not registered.');
    }
} catch (Exception $e) {
    error_log("Machine ID validation error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}