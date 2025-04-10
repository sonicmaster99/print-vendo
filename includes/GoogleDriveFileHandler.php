<?php
require_once __DIR__ . '/GoogleDriveHandler.php';

/**
 * GoogleDriveFileHandler - A utility class for handling file operations with Google Drive
 * This class provides methods to read and write files directly to Google Drive without
 * relying on temporary local storage.
 */
class GoogleDriveFileHandler
{
    private $driveHandler;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->driveHandler = new GoogleDriveHandler();
    }
    
    /**
     * Read a file from Google Drive
     * 
     * @param string $machineId The machine ID
     * @param string $fileName The file name to read
     * @return string|null The file content or null if not found
     */
    public function readFile($machineId, $fileName)
    {
        try {
            $file = $this->driveHandler->findFile($machineId, $fileName);
            if ($file && isset($file['content'])) {
                return $file['content'];
            }
            return null;
        } catch (Exception $e) {
            error_log('Error reading file from Google Drive: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Write a file to Google Drive
     * 
     * @param string $machineId The machine ID
     * @param string $fileName The file name to write
     * @param string $content The content to write
     * @return array|null The file info or null on failure
     */
    public function writeFile($machineId, $fileName, $content)
    {
        try {
            // Create a temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'gdrive_');
            if ($tempFile === false) {
                throw new Exception('Failed to create temporary file');
            }
            
            // Write content to temporary file
            if (file_put_contents($tempFile, $content) === false) {
                throw new Exception('Failed to write to temporary file');
            }
            
            // Upload to Google Drive
            $result = $this->driveHandler->uploadFile($machineId, $tempFile, $fileName);
            
            // Delete temporary file
            @unlink($tempFile);
            
            // Check if the result is valid
            if (!is_array($result) || !isset($result['fileId']) || !isset($result['webViewLink'])) {
                error_log('Invalid upload result from Google Drive in writeFile: ' . print_r($result, true));
                return null;
            }
            
            return $result;
        } catch (Exception $e) {
            error_log('Error writing file to Google Drive: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if a file exists in Google Drive
     * 
     * @param string $machineId The machine ID
     * @param string $fileName The file name to check
     * @return bool True if file exists, false otherwise
     */
    public function fileExists($machineId, $fileName)
    {
        try {
            $file = $this->driveHandler->findFile($machineId, $fileName);
            return ($file !== null);
        } catch (Exception $e) {
            error_log('Error checking file existence in Google Drive: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Upload a file from a temporary location to Google Drive
     * 
     * @param string $machineId The machine ID
     * @param string $tempFilePath The temporary file path
     * @param string $fileName The file name to use in Google Drive
     * @return array|null The file info or null on failure
     */
    public function uploadFile($machineId, $tempFilePath, $fileName)
    {
        try {
            $uploadResult = $this->driveHandler->uploadFile($machineId, $tempFilePath, $fileName);
            
            // Check if the result is valid
            if (!is_array($uploadResult) || !isset($uploadResult['fileId']) || !isset($uploadResult['webViewLink'])) {
                error_log('Invalid upload result from Google Drive: ' . print_r($uploadResult, true));
                return null;
            }
            
            return $uploadResult;
        } catch (Exception $e) {
            error_log('Error uploading file to Google Drive: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create a machine folder if it doesn't exist
     * 
     * @param string $machineId The machine ID
     * @return string|null The folder ID or null on failure
     */
    public function ensureMachineFolder($machineId)
    {
        try {
            $folderId = $this->driveHandler->getMachineFolderId($machineId);
            if ($folderId === null) {
                $createResult = $this->driveHandler->createMachineFolder($machineId);
                
                // Check if the result is a valid folder ID (string)
                if (!is_string($createResult) || empty($createResult)) {
                    error_log('Invalid folder ID returned from createMachineFolder: ' . print_r($createResult, true));
                    return null;
                }
                
                $folderId = $createResult;
            }
            return $folderId;
        } catch (Exception $e) {
            error_log('Error ensuring machine folder in Google Drive: ' . $e->getMessage());
            return null;
        }
    }
}
