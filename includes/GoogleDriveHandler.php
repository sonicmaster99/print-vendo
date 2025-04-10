<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class GoogleDriveHandler
{
    private $service;
    private $rootFolderId = '1xHjJU_wnhLgd1RoCWFrCexHPPtBuNrdI';
    private $machineFolders = [];
    private $configFile;

    private static function logError($message) {
        $logFile = __DIR__ . '/../errormachine.txt';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }

    public function __construct()
    {
        try {
            // Check PHP version compatibility first
            if (version_compare(PHP_VERSION, '8.0.0', '<')) {
                self::logError('PHP version incompatibility: Google Drive integration requires PHP 8.0.0 or higher. Current version: ' . PHP_VERSION);
                throw new Exception('PHP version incompatibility: Google Drive integration requires PHP 8.0.0 or higher');
            }

            // Check if autoload.php exists
            if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
                self::logError('Composer dependencies not installed. vendor/autoload.php not found.');
                throw new Exception('Composer dependencies not installed. Run "composer install" to use Google Drive integration');
            }

            $client = new Client();
            $credentialsPath = __DIR__ . '/../config/google-credentials.json';

            if (!file_exists($credentialsPath)) {
                self::logError('Google credentials file not found at: ' . $credentialsPath);
                throw new Exception('Google credentials file not found');
            }

            $client->setAuthConfig($credentialsPath);
            // Update scope to allow full access to files and folders
            $client->setScopes(['https://www.googleapis.com/auth/drive']);
            $this->service = new Drive($client);
            
            // Verify root folder access
            try {
                $rootFolder = $this->service->files->get($this->rootFolderId, ['fields' => 'id, name']);
                self::logError('Successfully accessed root folder: ' . $rootFolder->getName());
            } catch (Exception $e) {
                self::logError('Failed to access root folder: ' . $e->getMessage());
                throw new Exception('Failed to access root folder. Please verify the folder ID and permissions.');
            }
            
            self::logError('Google Drive service initialized successfully');
        } catch (Exception $e) {
            self::logError('Google Drive initialization error: ' . $e->getMessage());
            throw new Exception('Failed to initialize Google Drive service: ' . $e->getMessage());
        }
    }

    public function createMachineFolder($machineId) {
        try {
            // Create the machine folder
            $folderMetadata = new DriveFile([
                'name' => "machine_{$machineId}",
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$this->rootFolderId]
            ]);

            $folder = $this->service->files->create($folderMetadata, [
                'fields' => 'id, name'
            ]);

            // Create subfolders
            $subfolders = ['requests', 'responses', 'prints', 'payments'];
            foreach ($subfolders as $subfolder) {
                $subFolderMetadata = new DriveFile([
                    'name' => $subfolder,
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'parents' => [$folder->getId()]
                ]);
                $this->service->files->create($subFolderMetadata);
            }

            self::logError("Created machine folder and subfolders for machine ID: $machineId");
            return $folder->getId();
        } catch (Exception $e) {
            self::logError("Failed to create machine folder: " . $e->getMessage());
            throw new Exception("Failed to create machine folder: " . $e->getMessage());
        }
    }

    public function getMachineFolderId($machineId)
    {
        if (isset($this->machineFolders[$machineId])) {
            self::logError("Found cached machine folder ID for $machineId");
            return $this->machineFolders[$machineId];
        }

        self::logError("Searching for machine folder: machine_{$machineId} in root folder: {$this->rootFolderId}");
        
        // Search for existing machine folder
        $query = "mimeType='application/vnd.google-apps.folder' and name='machine_{$machineId}' and '{$this->rootFolderId}' in parents and trashed=false";
        self::logError("Google Drive search query: $query");
        
        try {
            $results = $this->service->files->listFiles([
                'q' => $query,
                'spaces' => 'drive',
                'fields' => 'files(id, name)'
            ]);

            $files = $results->getFiles();
            self::logError("Found " . count($files) . " matching folders");

            if (count($files) > 0) {
                $folderId = $files[0]->getId();
                self::logError("Found machine folder with ID: $folderId");
                $this->machineFolders[$machineId] = $folderId;
                return $folderId;
            }

            self::logError("No matching machine folder found for machine ID: $machineId");
            return null;
        } catch (Exception $e) {
            self::logError("Error searching for machine folder: " . $e->getMessage());
            self::logError("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    public function isMachineIdValid($machineId)
    {
        try {
            $folderId = $this->getMachineFolderId($machineId);
            return $folderId !== null;
        } catch (Exception $e) {
            self::logError("Error validating machine ID: " . $e->getMessage());
            return false;
        }
    }

    public function getRootFolderId() {
        return $this->rootFolderId;
    }

    public function uploadFile($machineId, $filePath, $fileName)
    {
        try {
            if (!file_exists($filePath)) {
                throw new Exception('File not found: ' . $filePath);
            }

            // Log the upload attempt with file details
            self::logError("Attempting to upload file to Google Drive: $filePath (Size: " . filesize($filePath) . " bytes)");

            $folderId = $this->getMachineFolderId($machineId);

            if ($folderId === null) {
                throw new Exception('Invalid machine ID: ' . $machineId);
            }

            $fileMetadata = new DriveFile([
                'name' => $fileName,
                'parents' => [$folderId]
            ]);

            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new Exception('Failed to read file contents: ' . $filePath);
            }

            $mimeType = mime_content_type($filePath);
            if (!$mimeType) {
                // Default to PDF if mime type detection fails
                $mimeType = 'application/pdf';
                self::logError("MIME type detection failed, defaulting to: $mimeType");
            }

            self::logError("Starting Google Drive upload with MIME type: $mimeType");

            $file = $this->service->files->create($fileMetadata, [
                'data' => $content,
                'mimeType' => $mimeType,
                'uploadType' => 'multipart',
                'fields' => 'id, webViewLink'
            ]);

            // Log successful upload
            self::logError("File uploaded successfully to Google Drive. File ID: {$file->getId()}, Link: {$file->getWebViewLink()}");

            return [
                'fileId' => $file->getId(),
                'webViewLink' => $file->getWebViewLink()
            ];
        } catch (Exception $e) {
            self::logError("Google Drive upload error for machine $machineId: " . $e->getMessage());
            self::logError("Stack trace: " . $e->getTraceAsString());
            throw new Exception('Failed to upload file to Google Drive: ' . $e->getMessage());
        }
    }

    public function findFile($machineId, $fileName)
    {
        try {
            $folderId = $this->getMachineFolderId($machineId);

            if ($folderId === null) {
                throw new Exception('Invalid machine ID: ' . $machineId);
            }

            // Search for the file in the machine's folder
            $query = "name='$fileName' and '$folderId' in parents and trashed=false";
            $results = $this->service->files->listFiles([
                'q' => $query,
                'spaces' => 'drive',
                'fields' => 'files(id, name, webViewLink)'
            ]);

            if (count($results->getFiles()) > 0) {
                $file = $results->getFiles()[0];

                // Get file content
                $content = $this->service->files->get($file->getId(), ['alt' => 'media']);

                return [
                    'fileId' => $file->getId(),
                    'name' => $file->getName(),
                    'webViewLink' => $file->getWebViewLink(),
                    'content' => $content->getBody()->getContents()
                ];
            }

            return null;
        } catch (Exception $e) {
            self::logError("Google Drive find file error for machine $machineId: " . $e->getMessage());
            throw new Exception('Failed to find file in Google Drive: ' . $e->getMessage());
        }
    }

    public function deleteFile($fileId)
    {
        try {
            $this->service->files->delete($fileId);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete all files in a machine's folder but keep the folder structure
     * 
     * @param string $machineId The machine ID
     * @return int Number of files deleted
     */
    public function deleteAllMachineFiles($machineId)
    {
        try {
            $folderId = $this->getMachineFolderId($machineId);
            
            if ($folderId === null) {
                throw new Exception('Invalid machine ID: ' . $machineId);
            }
            
            // Get all files in the machine's folder (excluding folders)
            $query = "'$folderId' in parents and mimeType != 'application/vnd.google-apps.folder' and trashed=false";
            $results = $this->service->files->listFiles([
                'q' => $query,
                'spaces' => 'drive',
                'fields' => 'files(id, name)'
            ]);
            
            $files = $results->getFiles();
            $deletedCount = 0;
            
            // Delete each file
            foreach ($files as $file) {
                try {
                    $this->service->files->delete($file->getId());
                    $deletedCount++;
                    self::logError("Deleted file: {$file->getName()} (ID: {$file->getId()}) from machine $machineId");
                } catch (Exception $e) {
                    self::logError("Failed to delete file {$file->getName()}: " . $e->getMessage());
                }
            }
            
            // Now get all subfolders and recursively delete their files
            $query = "'$folderId' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed=false";
            $results = $this->service->files->listFiles([
                'q' => $query,
                'spaces' => 'drive',
                'fields' => 'files(id, name)'
            ]);
            
            $folders = $results->getFiles();
            
            // Process each subfolder
            foreach ($folders as $folder) {
                // Get all files in the subfolder
                $subQuery = "'$folder->getId()' in parents and mimeType != 'application/vnd.google-apps.folder' and trashed=false";
                $subResults = $this->service->files->listFiles([
                    'q' => $subQuery,
                    'spaces' => 'drive',
                    'fields' => 'files(id, name)'
                ]);
                
                $subFiles = $subResults->getFiles();
                
                // Delete each file in the subfolder
                foreach ($subFiles as $file) {
                    try {
                        $this->service->files->delete($file->getId());
                        $deletedCount++;
                        self::logError("Deleted file: {$file->getName()} (ID: {$file->getId()}) from subfolder {$folder->getName()}");
                    } catch (Exception $e) {
                        self::logError("Failed to delete file {$file->getName()} from subfolder: " . $e->getMessage());
                    }
                }
            }
            
            self::logError("Cleanup completed for machine $machineId: Deleted $deletedCount files");
            return $deletedCount;
            
        } catch (Exception $e) {
            self::logError("Error deleting files for machine $machineId: " . $e->getMessage());
            throw new Exception('Failed to delete files: ' . $e->getMessage());
        }
    }

    /**
     * Upload content directly to Google Drive
     * 
     * @param string $machineId The machine ID
     * @param string $fileName The file name
     * @param string $content The content to upload
     * @param string $mimeType The mime type of the content
     * @return array|null File information or null on failure
     */
    public function uploadContent($machineId, $fileName, $content, $mimeType = 'application/pdf')
    {
        try {
            self::logError("Attempting to upload content to Google Drive as $fileName");

            $folderId = $this->getMachineFolderId($machineId);

            if ($folderId === null) {
                throw new Exception('Invalid machine ID: ' . $machineId);
            }

            $fileMetadata = new DriveFile([
                'name' => $fileName,
                'parents' => [$folderId]
            ]);

            self::logError("Starting Google Drive content upload with MIME type: $mimeType");

            $file = $this->service->files->create($fileMetadata, [
                'data' => $content,
                'mimeType' => $mimeType,
                'uploadType' => 'multipart',
                'fields' => 'id, name, webViewLink, webContentLink'
            ]);

            // Log successful upload
            self::logError("Content uploaded successfully to Google Drive. File ID: {$file->getId()}, Link: {$file->getWebViewLink()}");

            return [
                'id' => $file->getId(),
                'name' => $file->getName(),
                'webViewLink' => $file->getWebViewLink(),
                'webContentLink' => $file->getWebContentLink()
            ];
        } catch (Exception $e) {
            self::logError("Google Drive content upload error for machine $machineId: " . $e->getMessage());
            self::logError("Stack trace: " . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Get file data from Google Drive
     * 
     * @param string $fileId The file ID
     * @return array|null File data or null on failure
     */
    public function getFileData($fileId)
    {
        try {
            $file = $this->service->files->get($fileId, [
                'fields' => 'id, name, webViewLink, webContentLink'
            ]);

            return [
                'id' => $file->getId(),
                'name' => $file->getName(),
                'webViewLink' => $file->getWebViewLink(),
                'webContentLink' => $file->getWebContentLink()
            ];
        } catch (Exception $e) {
            self::logError("Error getting file data: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get file content from Google Drive
     * 
     * @param string $fileId The file ID
     * @return string|null File content or null on failure
     */
    public function getFileContent($fileId)
    {
        try {
            $content = $this->service->files->get($fileId, ['alt' => 'media']);
            return $content->getBody()->getContents();
        } catch (Exception $e) {
            self::logError("Error getting file content: " . $e->getMessage());
            return null;
        }
    }
}
