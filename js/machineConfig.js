// Machine folder mapping configuration
const machineFolders = {
    // Example mappings - will be populated dynamically
    // "001": "machine_001_folder",
    // "002": "machine_002_folder"
};

// Function to create a new machine folder mapping
function createMachineFolderMapping(machineId) {
    if (!machineFolders[machineId]) {
        // Generate a unique folder ID using timestamp and random string
        const timestamp = Date.now();
        const randomStr = Math.random().toString(36).substring(2, 8);
        const folderId = `machine_${machineId}_${timestamp}_${randomStr}`;
        machineFolders[machineId] = folderId;
        saveMachineFolders();
    }
    return machineFolders[machineId];
}

// Function to get machine folder
function getMachineFolder(machineId) {
    // Ensure machineId is provided and valid
    if (!machineId) {
        throw new Error('Machine ID is required');
    }

    // Format the folder name consistently
    return `machine_${machineId.toString().toLowerCase()}`;
}

// Save machine folders to localStorage for persistence
function saveMachineFolders() {
    localStorage.setItem('machineFolders', JSON.stringify(machineFolders));
}

// Load existing machine folders from localStorage
function loadMachineFolders() {
    const saved = localStorage.getItem('machineFolders');
    if (saved) {
        Object.assign(machineFolders, JSON.parse(saved));
    }
}

// Initialize machine folders on load
loadMachineFolders();

// Make function globally available
window.getMachineFolder = getMachineFolder;
window.createMachineFolderMapping = createMachineFolderMapping;
