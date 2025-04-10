// Display Machine ID and load document data
document.addEventListener('DOMContentLoaded', function() {
    // Get machine ID from URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const machineId = urlParams.get('machineId');
    
    if (!machineId) {
        window.location.href = '../index.html';
        return;
    }
    
    // Store machine ID in session storage
    sessionStorage.setItem('machineId', machineId);
    
    // Display machine ID
    document.getElementById('machineIdDisplay').textContent = `Machine ID: ${machineId}`;
    
    // Check if we already have the response in session storage
    const cachedResponse = sessionStorage.getItem('responseText');
    const lastMachineId = sessionStorage.getItem('lastMachineId');
    
    if (cachedResponse && lastMachineId === machineId) {
        // Use cached response
        displayDocumentData(cachedResponse, machineId);
    } else {
        // Load document data and response from server
        loadDocumentData(machineId);
    }
    
    // Add event listeners for edit functionality
    setupEditFunctionality();
    
    // Add event listener for download button
    const downloadBtn = document.getElementById('downloadPdfBtn');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', function() {
            downloadDocument(machineId);
        });
    }
});

// Setup edit functionality for the response
function setupEditFunctionality() {
    const editBtn = document.getElementById('editResponseBtn');
    const saveBtn = document.getElementById('saveResponseBtn');
    const cancelBtn = document.getElementById('cancelEditBtn');
    const responseContainer = document.getElementById('responseContainer');
    const responseEditor = document.getElementById('responseEditor');
    
    // Edit button click handler
    editBtn.addEventListener('click', function() {
        // Get current response text (strip HTML tags)
        const currentText = responseContainer.innerHTML
            .replace(/<br\s*\/?>/gi, '\n')  // Replace <br> with newlines
            .replace(/<[^>]*>/g, '');       // Remove all other HTML tags
        
        // Set the editor content
        responseEditor.value = currentText;
        
        // Show editor and hide display
        responseContainer.style.display = 'none';
        responseEditor.style.display = 'block';
        
        // Show save/cancel buttons, hide edit button
        editBtn.style.display = 'none';
        saveBtn.style.display = 'block';
        cancelBtn.style.display = 'block';
    });
    
    // Save button click handler
    saveBtn.addEventListener('click', function() {
        // Get edited text
        const editedText = responseEditor.value;
        
        // Update the response container with formatted text
        responseContainer.innerHTML = editedText.replace(/\n/g, '<br>');
        
        // Update session storage
        sessionStorage.setItem('responseText', editedText);
        
        // Hide editor and show display
        responseEditor.style.display = 'none';
        responseContainer.style.display = 'block';
        
        // Hide save/cancel buttons, show edit button
        saveBtn.style.display = 'none';
        cancelBtn.style.display = 'none';
        editBtn.style.display = 'block';
        
        // Show success message
        Swal.fire({
            icon: 'success',
            title: 'Saved!',
            text: 'Your changes have been saved.',
            timer: 1500,
            showConfirmButton: false
        });
    });
    
    // Cancel button click handler
    cancelBtn.addEventListener('click', function() {
        // Hide editor and show display
        responseEditor.style.display = 'none';
        responseContainer.style.display = 'block';
        
        // Hide save/cancel buttons, show edit button
        saveBtn.style.display = 'none';
        cancelBtn.style.display = 'none';
        editBtn.style.display = 'block';
    });
}

// Display document data
function displayDocumentData(responseText, machineId) {
    try {
        // Hide loading spinner
        document.getElementById('loadingSpinner').style.display = 'none';
        
        // Format and display response
        const responseElement = document.getElementById('responseContainer');
        if (responseText) {
            // Convert line breaks to <br> tags and display the response
            responseElement.innerHTML = responseText.replace(/\n/g, '<br>');
            
            // Store the response text for PDF generation
            sessionStorage.setItem('responseText', responseText);
            sessionStorage.setItem('lastMachineId', machineId);
        } else {
            responseElement.innerHTML = '<p class="text-danger">No response available</p>';
        }
    } catch (error) {
        console.error('Error displaying document data:', error);
        
        // Show error message
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: error.message || 'An error occurred while displaying the document data.'
        });
    }
}

// Load document data and response
async function loadDocumentData(machineId) {
    try {
        // Show loading spinner
        document.getElementById('loadingSpinner').style.display = 'flex';
        
        // Fetch document data
        const response = await fetch(`../get_document_response.php?machineId=${machineId}`);
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || 'Failed to load document data');
        }
        
        // Update document info
        document.getElementById('documentName').textContent = data.documentName || 'Unknown document';
        document.getElementById('promptText').textContent = data.prompt || 'No prompt provided';
        
        // Display the response
        if (data.response) {
            displayDocumentData(data.response, machineId);
        } else {
            document.getElementById('responseContainer').innerHTML = '<p class="text-danger">No response available</p>';
            document.getElementById('loadingSpinner').style.display = 'none';
        }
        
    } catch (error) {
        console.error('Error loading document data:', error);
        
        // Hide loading spinner
        document.getElementById('loadingSpinner').style.display = 'none';
        
        // Show error message
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: error.message || 'An error occurred while loading the document data.'
        });
    }
}

// Track download status
let isDownloading = false;

// Download PDF directly
async function downloadDocument(machineId) {
    // Prevent multiple downloads
    if (isDownloading) {
        return;
    }
    
    try {
        const downloadBtn = document.getElementById('downloadPdfBtn');
        const responseText = sessionStorage.getItem('responseText');
        
        // Check if we have response text
        if (!responseText || responseText.trim() === '') {
            throw new Error('No document content available to download');
        }
        
        // Set downloading flag
        isDownloading = true;
        
        // Disable the button and change text to show processing
        downloadBtn.disabled = true;
        downloadBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
        
        // Show loading spinner
        document.getElementById('loadingSpinner').style.display = 'flex';
        
        // Create a unique download ID to track this download
        const downloadId = 'download_' + machineId + '_' + Date.now();
        
        // Get document info for PDF generation
        const documentName = document.getElementById('documentName').textContent || 'Unknown Document';
        const promptText = document.getElementById('promptText').textContent || 'No prompt provided';
        
        // Generate PDF using client-side library
        try {
            // Create new PDF document
            const { jsPDF } = window.jspdf;
            
            if (!jsPDF) {
                throw new Error('PDF generation library not available');
            }
            
            const pdf = new jsPDF();
            
            // Add title
            pdf.setFontSize(16);
            pdf.text('Document Analysis', 105, 15, { align: 'center' });
            
            // Add machine ID
            pdf.setFontSize(12);
            pdf.text(`Machine ID: ${machineId}`, 105, 25, { align: 'center' });
            
            // Add document name
            pdf.text(`Document: ${documentName}`, 20, 35);
            
            // Add prompt
            pdf.setFontSize(10);
            pdf.text('Prompt:', 20, 45);
            const promptLines = pdf.splitTextToSize(promptText, 170);
            pdf.text(promptLines, 20, 50);
            
            // Add response
            const yPosition = 50 + (promptLines.length * 5) + 10;
            pdf.setFontSize(12);
            pdf.text('ChatGPT Response:', 20, yPosition);
            
            // Format response text
            const responseLines = pdf.splitTextToSize(responseText, 170);
            pdf.text(responseLines, 20, yPosition + 10);
            
            // Save the PDF
            const pdfOutput = pdf.output('datauristring');
            
            // Create a download link
            const downloadLink = document.createElement('a');
            downloadLink.href = pdfOutput;
            downloadLink.download = `ChatGPT_Response_${machineId}_${new Date().toISOString().slice(0, 10)}.pdf`;
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
            
            console.log('PDF generation successful');
            
            // Hide loading spinner
            document.getElementById('loadingSpinner').style.display = 'none';
            
            // Re-enable the download button
            downloadBtn.disabled = false;
            downloadBtn.textContent = 'Download PDF';
            
            // Reset downloading flag
            isDownloading = false;
            
            // Show success message
            Swal.fire({
                icon: 'success',
                title: 'Download Complete',
                text: 'Your PDF has been generated and downloaded.',
                timer: 3000,
                showConfirmButton: false
            });
            
            // Also send the content to the server for storage
            try {
                const formData = new FormData();
                formData.append('machineId', machineId);
                formData.append('responseText', responseText);
                
                fetch('../download_document.php?ajax=true', {
                    method: 'POST',
                    body: formData
                }).then(response => {
                    console.log('Server storage request sent');
                }).catch(error => {
                    console.warn('Failed to store PDF on server:', error);
                });
            } catch (serverError) {
                console.warn('Error sending content to server:', serverError);
            }
            
        } catch (pdfError) {
            console.error('Error generating PDF:', pdfError);
            throw new Error('Failed to generate PDF: ' + pdfError.message);
        }
        
    } catch (error) {
        console.error('Error in download process:', error);
        
        // Hide loading spinner
        document.getElementById('loadingSpinner').style.display = 'none';
        
        // Re-enable the download button
        const downloadBtn = document.getElementById('downloadPdfBtn');
        downloadBtn.disabled = false;
        downloadBtn.textContent = 'Download PDF';
        
        // Reset downloading flag
        isDownloading = false;
        
        // Show error message
        Swal.fire({
            icon: 'error',
            title: 'Download Failed',
            text: error.message || 'An error occurred while generating the PDF.'
        });
    }
}
