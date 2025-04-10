// This script checks if payment has been verified and triggers document processing
let processingStarted = false;

document.addEventListener('DOMContentLoaded', function() {
    // Get machine ID from URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const machineId = urlParams.get('machineId');
    
    if (!machineId) {
        console.error('No machine ID found in URL');
        return;
    }
    
    // Check payment status and trigger processing if paid
    checkPaymentAndProcess(machineId);
});

// Check payment status and trigger processing if paid
async function checkPaymentAndProcess(machineId) {
    try {
        // Check if payment has been verified
        const response = await fetch(`../check_payment.php?machineId=${machineId}`);
        const data = await response.json();
        
        if (data.success && data.paid && !processingStarted) {
            console.log('Payment verified, triggering document processing');
            processingStarted = true;
            
            // Trigger document processing
            triggerDocumentProcessing(machineId);
        }
    } catch (error) {
        console.error('Error checking payment status:', error);
    }
}

// Trigger document processing
async function triggerDocumentProcessing(machineId) {
    try {
        const formData = new FormData();
        formData.append('machineId', machineId);
        formData.append('documentId', 'doc_' + Math.random().toString(36).substr(2, 9)); // Generate a random document ID
        
        // Call process_document_response.php
        const response = await fetch('../process_document_response.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        console.log('Document processing response:', data);
        
        // Reload document data to show the response
        if (typeof loadDocumentData === 'function') {
            loadDocumentData(machineId);
        } else {
            // If loadDocumentData is not available, reload the page after a delay
            setTimeout(() => {
                window.location.reload();
            }, 5000); // 5 seconds delay
        }
    } catch (error) {
        console.error('Error triggering document processing:', error);
    }
}
