// Document handling state
let currentDocument = null;
let waitingForPayment = false;

// Default pricing
const PRICE_BW = 3;  // Price per black & white page
const PRICE_COLOR = 5;  // Price per colored page

document.addEventListener('DOMContentLoaded', function() {
    const machineId = sessionStorage.getItem('machineId');
    if (!machineId) {
        window.location.href = '../index.html';
        return;
    }

    // Display machine ID
    document.getElementById('machineIdDisplay').textContent = `Machine ID: ${machineId}`;

    // Load the prompt and start checking for response
    loadPrompt(machineId);
    startResponseCheck(machineId);

    // Add event listeners for print options
    document.querySelectorAll('input[name="printType"]').forEach(radio => {
        radio.addEventListener('change', updateCost);
    });
    
    // Start checking for payment verification
    startPaymentVerificationCheck();
});

// Check for payment verification file
function startPaymentVerificationCheck() {
    // Check every 0.25 seconds
    setInterval(checkPaymentVerification, 250);
}

// Check if payment has been verified
function checkPaymentVerification() {
    const machineId = sessionStorage.getItem('machineId');
    
    // Check for Amount_print_paid.txt
    fetch(`../check_payment.php?machineId=${machineId}&type=print`)
    .then(res => res.json())
    .then(data => {
        if (data.success && data.paid) {
            // Payment verified, show success message
            Swal.fire({
                icon: 'success',
                title: 'Payment Verified',
                text: 'Your payment has been received. Your document will be printed shortly.',
                background: '#ffffff',
                color: '#000000',
                allowOutsideClick: false
            });
        }
    })
    .catch(error => {
        console.error('Error checking payment:', error);
    });
}

// Load prompt from file
async function loadPrompt(machineId) {
    try {
        const response = await fetch(`../get_prompt.php?machineId=${machineId}&v=${new Date().getTime()}`);
        const data = await response.json();
        
        if (data.success && data.content) {
            document.getElementById('promptText').value = data.content;
            // Store the prompt ID for later use
            sessionStorage.setItem('currentPromptId', data.fileId);
        } else {
            throw new Error(data.message || 'Failed to load prompt');
        }
    } catch (error) {
        console.error('Error loading prompt:', error);
        showError('Failed to load prompt. Please try again.');
    }
}

// Start checking for ChatGPT response
function startResponseCheck(machineId) {
    // Show loading indicator
    document.getElementById('loading').style.display = 'block';
    document.getElementById('responseEditor').style.display = 'none';

    // Check every 0.25 seconds
    const checkInterval = setInterval(async () => {
        try {
            const response = await fetch(`../check_response.php?machineId=${machineId}`);
            const data = await response.json();
            
            if (data.success && data.response) {
                // Response is ready
                clearInterval(checkInterval);
                document.getElementById('loading').style.display = 'none';
                document.getElementById('responseEditor').style.display = 'block';
                
                // Set response text and make it editable
                const responseEditor = document.getElementById('responseEditor');
                responseEditor.value = data.response;
                responseEditor.readOnly = false; // Make it editable immediately
                
                updateCost(); // Calculate initial cost
            }
        } catch (error) {
            console.error('Error checking response:', error);
        }
    }, 250);
}

// Generate content for cost.txt file
function generateCostTxtContent(pageInfo, fileId, machineId) {
    const timestamp = new Date().toISOString();
    return `Document Cost Summary
-----------------------
File ID: ${fileId}
Machine ID: ${machineId}
Timestamp: ${timestamp}
Total Pages: ${pageInfo.totalPages}
Print Type: ${pageInfo.printType}
Cost per Page: ₱${COST_PER_PAGE[pageInfo.printType]}
Total Cost: ₱${pageInfo.totalCost.toFixed(2)}
-----------------------`;
}

// Update cost based on content and print type
function updateCost() {
    const content = document.getElementById('responseEditor').value;
    const isPrintTypeColor = document.getElementById('colored').checked;
    
    // Calculate number of pages (improved estimate: ~2000 characters per page)
    const charsPerPage = 2000;
    const numPages = Math.max(1, Math.ceil(content.length / charsPerPage));
    
    // Calculate cost
    const costPerPage = isPrintTypeColor ? PRICE_COLOR : PRICE_BW;
    const totalCost = numPages * costPerPage;
    
    // Update display
    document.getElementById('estimatedCost').textContent = totalCost.toFixed(2);

    // Update current document info
    currentDocument = {
        type: 'chatgpt',
        content: content,
        pageInfo: {
            totalPages: numPages,
            printType: isPrintTypeColor ? 'color' : 'black',
            costPerPage: costPerPage,
            totalCost: totalCost
        }
    };
}

// Convert text content to PDF
async function convertTxtToPdf(content, filename) {
    try {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();

        // Set document properties
        doc.setProperties({
            title: filename,
            subject: 'Generated from ChatGPT response',
            creator: 'PrintVendo System'
        });

        // Format settings
        const margin = 10;
        const lineHeight = 7;
        const pageWidth = doc.internal.pageSize.width - (margin * 2);
        const pageHeight = doc.internal.pageSize.height;
        doc.setFontSize(12);
        
        // Remove header with filename - start directly with content
        let y = 20; // Start position for content
        
        // Process text content with proper line breaks
        const paragraphs = content.split(/\n\s*\n/); // Split by empty lines
        let pageCount = 1; // Start with first page
        
        for (let i = 0; i < paragraphs.length; i++) {
            const paragraph = paragraphs[i].trim();
            if (paragraph) {
                // Split paragraph into lines that fit the page width
                const lines = doc.splitTextToSize(paragraph, pageWidth);
                
                // Check if we need a new page
                if (y + (lines.length * lineHeight) > pageHeight - margin) {
                    doc.addPage();
                    pageCount++; // Increment page count
                    y = 20;
                }
                
                // Add text
                doc.text(lines, margin, y);
                y += lines.length * lineHeight + 5; // Add extra space between paragraphs
            }
        }

        // Convert to PDF file
        const pdfBlob = doc.output('blob');
        const pdfFile = new File([pdfBlob], filename.replace(/\.txt$/i, '.pdf'), {
            type: 'application/pdf'
        });
        
        // Return both the file and page count
        return {
            file: pdfFile,
            pageCount: pageCount,
            hasColor: false // Text PDFs are always black & white
        };
    } catch (error) {
        console.error('Error converting text to PDF:', error);
        throw new Error('Failed to convert text to PDF format.');
    }
}

// Convert to black & white if needed
async function convertToBlackAndWhite(pdfInfo) {
    try {
        // For a proper implementation, we would need to:
        // 1. Use PDF.js to render each page to a canvas
        // 2. Convert the canvas to grayscale
        // 3. Create a new PDF from the grayscale canvases
        
        // Since this is beyond the scope of this implementation,
        // we'll add a flag to the file name to indicate it should be
        // processed as black and white
        const fileName = pdfInfo.file.name.replace('.pdf', '_bw.pdf');
        const bwFile = new File([pdfInfo.file], fileName, { type: 'application/pdf' });
        
        // Return updated info with black & white file
        return {
            file: bwFile,
            pageCount: pdfInfo.pageCount,
            hasColor: false
        };
    } catch (error) {
        console.error('Error converting to black and white:', error);
        return pdfInfo; // Return original if conversion fails
    }
}

// Store uploaded file
async function storeFile(machineId, content, fileId, pageInfo, costFile, amountFile, pdfFile) {
    try {
        console.log('Starting file upload with params:', { 
            machineId, 
            fileId, 
            printType: pageInfo.printType
        });
        
        // Create form data
        const formData = new FormData();
        formData.append('content', content);
        formData.append('machineId', machineId);
        formData.append('fileId', fileId);
        formData.append('pageInfo', JSON.stringify(pageInfo));
        formData.append('printType', pageInfo.printType);
        
        // Add files to form data
        if (costFile) formData.append('costFile', costFile);
        if (amountFile) formData.append('amountFile', amountFile);
        if (pdfFile) formData.append('pdfFile', pdfFile);

        // Send upload request
        const response = await fetch('../prepare_print.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Upload failed');
        }

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Upload failed');
        }

        console.log('Upload successful:', result);
        return result;
        
    } catch (error) {
        console.error('Upload error:', error);
        throw error;
    }
}

// Generate unique file ID
function generateFileId() {
    return 'print_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

// Handle upload and print
async function uploadAndPrint() {
    const uploadBtn = document.querySelector('.btn-primary');
    if (!uploadBtn || uploadBtn.disabled) {
        return; // Prevent multiple submissions
    }
    
    // Save original button text and disable button
    const originalBtnText = uploadBtn.innerHTML;
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
    
    const machineId = new URLSearchParams(window.location.search).get('machineId');
    const printType = Array.from(printTypeRadios).find(radio => radio.checked)?.value || 'black';
    const savePdf = saveAsPdfCheckbox ? saveAsPdfCheckbox.checked : false;
    const content = responseEditor.value;

    try {
        // Validate content
        if (!content || content.trim().length === 0) {
            throw new Error('No content to print. Please ensure there is text in the editor.');
        }
        
        // Show processing dialog
        const processingSwal = Swal.fire({
            title: 'Processing Document',
            html: 'Please wait...<br>Calculating cost and preparing your document for printing.',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        // Generate a unique fileId
        const fileId = generateFileId();
        
        // Convert content to PDF to get actual page count
        const contentFile = new File([content], fileId + '.txt', { type: 'text/plain' });
        let pdfInfo = await convertTxtToPdf(content, fileId + '.txt');
        
        // Convert to black & white if selected
        if (printType === 'black') {
            pdfInfo = await convertToBlackAndWhite(pdfInfo);
        }
        
        // Calculate cost based on actual PDF page count
        const pageCount = pdfInfo.pageCount;
        const costPerPage = COST_PER_PAGE[printType];
        let totalCost = pageCount * costPerPage;
        
        // Add PDF cost if selected
        if (savePdf) {
            totalCost += PDF_COST;
        }
        
        // Create pageInfo object with actual page count
        const pageInfo = {
            totalPages: pageCount,
            printType: printType,
            costPerPage: costPerPage,
            totalCost: totalCost,
            hasColor: pdfInfo.hasColor
        };
        
        // Update the estimated cost display
        const estimatedCostElement = document.getElementById('estimatedCost');
        if (estimatedCostElement) {
            estimatedCostElement.textContent = totalCost.toFixed(2);
        }
        
        // Generate cost.txt content
        const costContent = generateCostTxtContent(pageInfo, fileId, machineId);
        const costFile = new File([costContent], 'cost.txt', { type: 'text/plain' });
        
        // Generate Amount_request.txt content
        const amountContent = totalCost.toFixed(2);
        const amountFile = new File([amountContent], 'Amount_request.txt', { type: 'text/plain' });
        
        // If savePdf is checked, download the PDF file to the user's device
        if (savePdf) {
            // Create a download link for the PDF
            const pdfBlob = pdfInfo.file;
            const downloadUrl = URL.createObjectURL(pdfBlob);
            const downloadLink = document.createElement('a');
            downloadLink.href = downloadUrl;
            downloadLink.download = `ChatGPT_Response_${new Date().toISOString().slice(0, 10)}.pdf`;
            
            // Append to document, click, and remove
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
            
            // Clean up the URL object
            setTimeout(() => {
                URL.revokeObjectURL(downloadUrl);
            }, 100);
        }
        
        console.log('Preparing to upload files:', {
            machineId,
            fileId,
            printType,
            pageInfo,
            pdfCreated: !!pdfInfo.file
        });
        
        // Create form data with all required parameters
        const formData = new FormData();
        formData.append('machineId', machineId);
        formData.append('content', content);
        formData.append('fileId', fileId);
        formData.append('pageInfo', JSON.stringify(pageInfo));
        formData.append('printType', printType);
        
        // Add files to form data - these need to be uploaded as files
        formData.append('pdfFile', pdfInfo.file, fileId + '.pdf');
        formData.append('costFile', costFile, 'cost.txt');
        formData.append('amountFile', amountFile, 'Amount_request.txt');
        
        // Send the request to prepare_print.php
        const response = await fetch('../prepare_print.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`Server error: ${response.status} ${response.statusText}`);
        }
        
        let result;
        try {
            result = await response.json();
        } catch (e) {
            const text = await response.text();
            console.error('Failed to parse JSON response:', text);
            throw new Error('Invalid response from server');
        }
        
        if (!result.success) {
            throw new Error(result.message || 'Failed to prepare document for printing');
        }
        
        console.log('Upload successful:', result);
        
        // Close processing dialog
        processingSwal.close();
        
        // Show success message with payment instructions
        Swal.fire({
            icon: 'success',
            title: 'Print Job Ready',
            html: `
                <div class="alert alert-info">
                    <h6>Print Summary:</h6>
                    <p>Total Pages: ${pageInfo.totalPages}</p>
                    <p>Print Type: ${pageInfo.printType === 'color' ? 'Color' : 'Black & White'}</p>
                    <p>Cost per Page: ₱${pageInfo.costPerPage.toFixed(2)}</p>
                    <p>Total Cost: ₱${totalCost.toFixed(2)}</p>
                    <hr>
                    <p class="mb-0">Please proceed to the vending machine to complete your payment and print your document.</p>
                </div>
            `,
            showConfirmButton: true,
            confirmButtonText: 'OK',
            allowOutsideClick: false
        });
        
        // Set waiting for payment flag
        waitingForPayment = true;
        
    } catch (error) {
        console.error('Print confirmation error:', error);
        
        // Re-enable button and restore original text
        uploadBtn.disabled = false;
        uploadBtn.innerHTML = originalBtnText;
        
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: error.message || 'Failed to process print request.',
            confirmButtonText: 'OK',
            allowOutsideClick: false
        });
    }
}

// Show payment instructions
function showPaymentInstructions(amount) {
    Swal.fire({
        icon: 'info',
        title: 'Payment Instructions',
        html: `
            <div class="text-start">
                <p>Please pay ₱${amount.toFixed(2)} to proceed with printing.</p>
                <p>Payment Methods:</p>
                <ul>
                    <li>Cash payment at counter</li>
                    <li>GCash: 09123456789</li>
                </ul>
                <p>Your document will be printed once payment is confirmed.</p>
            </div>
        `,
        background: '#ffffff',
        color: '#000000',
        allowOutsideClick: false
    });
}

// Show error message
function showError(message) {
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: message,
        background: '#ffffff',
        color: '#000000',
        allowOutsideClick: false
    });
}

// Get references to form elements
const responseEditor = document.getElementById('responseEditor');
const promptText = document.getElementById('promptText');
const machineIdDisplay = document.getElementById('machineIdDisplay');
const printTypeRadios = document.getElementsByName('printType');
const saveAsPdfCheckbox = document.getElementById('saveAsPdf');
const estimatedCostSpan = document.getElementById('estimatedCost');

// Constants
const COST_PER_PAGE = {
    black: 3,
    color: 5
};
const PDF_COST = 2;
const CHARS_PER_PAGE = 2000;

// Calculate print cost
function calculatePrintCost() {
    try {
        const selectedPrintType = Array.from(printTypeRadios).find(radio => radio.checked)?.value || 'black';
        const costPerPage = COST_PER_PAGE[selectedPrintType];
        const text = responseEditor.value || '';
        const pageCount = Math.ceil(text.length / CHARS_PER_PAGE);
        let totalCost = pageCount * costPerPage;
        
        // Add PDF cost if selected
        if (saveAsPdfCheckbox && saveAsPdfCheckbox.checked) {
            totalCost += PDF_COST;
        }
        
        // Update the displayed cost
        if (estimatedCostSpan) {
            estimatedCostSpan.textContent = totalCost;
        }
        
        return totalCost;
    } catch (error) {
        console.error('Error calculating print cost:', error);
        return 0; // Default to 0 if calculation fails
    }
}

// Load initial content
document.addEventListener('DOMContentLoaded', () => {
    // Get machine ID from URL and ensure it's not null
    const urlParams = new URLSearchParams(window.location.search);
    const machineId = urlParams.get('machineId');
    
    console.log('Machine ID from URL:', machineId); // Debug log
    
    // Check if machineId exists and is not null or undefined
    if (!machineId) {
        console.error('Machine ID is missing or null');
        machineIdDisplay.textContent = 'Machine ID: Not available';
        showError('No machine ID provided. Please ensure you have the correct URL.');
        return; // Stop execution if no machineId
    }
    
    machineIdDisplay.textContent = `Machine ID: ${machineId}`;

    // Load prompt and response with proper error handling
    fetch(`../get_prompt.php?machineId=${machineId}&v=${new Date().getTime()}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Failed to load prompt (${response.status} ${response.statusText})`);
            }
            return response.json(); // Parse as JSON instead of text
        })
        .then(data => {
            if (data.success && data.content) {
                promptText.value = data.content;
            } else {
                throw new Error(data.message || 'Failed to load prompt data');
            }
        })
        .catch(error => {
            console.error('Error loading prompt:', error);
            showError('Failed to load prompt. Please try again or contact support.');
        });
    
    // Only fetch response if machineId is valid
    if (machineId) {
        fetch(`../get_response.php?machineId=${machineId}&v=${new Date().getTime()}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Failed to load response (${response.status} ${response.statusText})`);
                }
                return response.json(); // Parse as JSON instead of text
            })
            .then(data => {
                if (data.success && data.content) {
                    responseEditor.value = data.content;
                    calculatePrintCost();
                } else {
                    throw new Error(data.message || 'Failed to load response data');
                }
            })
            .catch(error => {
                console.error('Error loading response:', error);
                showError('Failed to load response. Please try again or contact support.');
            });
    }

    // Add event listeners for cost calculation
    responseEditor.addEventListener('input', calculatePrintCost);
    printTypeRadios.forEach(radio => {
        radio.addEventListener('change', calculatePrintCost);
    });
    if (saveAsPdfCheckbox) {
        saveAsPdfCheckbox.addEventListener('change', calculatePrintCost);
    }
});
