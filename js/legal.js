// Display Machine ID
document.addEventListener('DOMContentLoaded', function() {
    const machineId = sessionStorage.getItem('machineId');
    if (!machineId) {
        window.location.href = '../index.html';
        return;
    }
    document.getElementById('machineIdDisplay').textContent = `Machine ID: ${machineId}`;
    
    // Initialize document scanner
    initDocumentScanner();
    
    // Start checking for payment verification file
    startPaymentVerificationCheck();
});

// Initialize document scanner
let documentScanner;

function initDocumentScanner() {
    // Create a new DocumentScanner instance
    documentScanner = new DocumentScanner({
        templatePath: '../templates/',
        dynamicFieldsContainer: '#dynamicFields',
        previewContainer: '#documentPreview',
        previewSection: '#previewSection'
    });
    
    // Handle document type selection
    const documentTypeSelect = document.getElementById('documentType');
    documentTypeSelect.addEventListener('change', async function() {
        const selectedTemplate = this.value;
        
        // Clear previous fields and preview
        document.getElementById('dynamicFields').innerHTML = '';
        document.getElementById('documentPreview').innerHTML = '';
        document.getElementById('previewSection').classList.add('d-none');
        document.getElementById('printBtn').classList.add('d-none');
        
        if (!selectedTemplate) {
            return;
        }
        
        try {
            // Load and scan the selected template
            await documentScanner.loadTemplate(selectedTemplate);
        } catch (error) {
            console.error('Failed to load template:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to load the selected document template.'
            });
        }
    });
    
    // Handle form submission
    document.getElementById('legalForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Get form values
        const values = documentScanner.getFormValues();
        
        // Show loading spinner
        document.getElementById('loadingSpinner').style.display = 'block';
        
        // Generate document content
        const preview = documentScanner.generatePreview(values);
        
        // Calculate cost
        const colorMode = document.querySelector('input[name="colorMode"]:checked').value;
        const content = preview.replace(/<[^>]*>/g, ''); // Strip HTML tags for length calculation
        const pages = Math.ceil(content.length / 500);
        const cost = colorMode === 'color' ? pages * 15 : pages * 5;
        
        // Store generated content and cost for later use
        window.generatedContent = preview;
        window.generatedCost = cost;
        window.generatedValues = values;
        window.colorMode = colorMode;
        
        // Save cost to file
        saveCostToFile(cost);
        
        // Hide loading spinner
        document.getElementById('loadingSpinner').style.display = 'none';
    });
}

// Save cost to ChatGpt_Amount.txt file
function saveCostToFile(cost) {
    const machineId = sessionStorage.getItem('machineId');
    
    const formData = new FormData();
    formData.append('machineId', machineId);
    formData.append('amount', cost);
    
    fetch('../save_chatgpt_cost.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Cost saved successfully');
            Swal.fire({
                icon: 'info',
                title: 'Payment Required',
                text: `Please pay ₱${cost} to print your document.`,
                confirmButtonText: 'OK'
            });
        } else {
            console.error('Failed to save cost:', data.message);
        }
    })
    .catch(error => {
        console.error('Error saving cost:', error);
    });
}

// Start checking for payment verification
function startPaymentVerificationCheck() {
    // Check immediately
    checkPaymentVerification();
    
    // Then check every 5 seconds
    setInterval(checkPaymentVerification, 5000);
}

// Check if payment has been verified
function checkPaymentVerification() {
    if (!window.generatedContent) {
        return; // No document generated yet
    }
    
    const machineId = sessionStorage.getItem('machineId');
    
    fetch(`../uploads/${machineId}/payment_verified.txt?nocache=${new Date().getTime()}`)
        .then(response => {
            if (response.ok) {
                return response.text();
            }
            throw new Error('Payment not verified yet');
        })
        .then(data => {
            if (data.trim() === '1') {
                // Payment verified, show document preview
                showDocumentPreview();
                
                // Delete the verification file to prevent reuse
                deletePaymentVerificationFile();
            }
        })
        .catch(error => {
            // Payment not verified yet, do nothing
            console.log('Waiting for payment verification...');
        });
}

// Show document preview after payment verification
function showDocumentPreview() {
    // Show the preview section
    const previewSection = document.getElementById('previewSection');
    previewSection.classList.remove('d-none');
    
    // Update the preview content
    const documentPreview = document.getElementById('documentPreview');
    documentPreview.innerHTML = window.generatedContent;
    
    // Show the print button
    const printBtn = document.getElementById('printBtn');
    printBtn.classList.remove('d-none');
    
    // Show success message
    Swal.fire({
        icon: 'success',
        title: 'Payment Verified',
        text: 'Your document is ready to print!',
        confirmButtonText: 'OK'
    });
}

// Delete payment verification file
function deletePaymentVerificationFile() {
    const machineId = sessionStorage.getItem('machineId');
    
    fetch('../delete_payment_verification.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `machineId=${machineId}`
    })
    .then(response => response.json())
    .then(data => {
        console.log('Payment verification file deleted:', data);
    })
    .catch(error => {
        console.error('Error deleting payment verification file:', error);
    });
}

// Handle print button
document.getElementById('printBtn').addEventListener('click', function() {
    const documentContent = document.getElementById('documentPreview').innerHTML;
    const colorMode = window.colorMode;
    
    // Create a new window for printing
    const printWindow = window.open('', '_blank');
    
    // Write the document content to the new window
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Print Document</title>
            <style>
                body {
                    font-family: 'Times New Roman', Times, serif;
                    line-height: 1.6;
                    color: ${colorMode === 'bw' ? '#000' : 'inherit'};
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .document {
                    max-width: 800px;
                    margin: 0 auto;
                    padding: 40px;
                }
                @media print {
                    body {
                        margin: 0;
                        padding: 0;
                    }
                    .document {
                        padding: 0;
                    }
                }
            </style>
        </head>
        <body>
            <div class="document">
                ${documentContent}
            </div>
            <script>
                window.onload = function() {
                    window.print();
                    setTimeout(function() {
                        window.close();
                    }, 500);
                };
            </script>
        </body>
        </html>
    `);
});

// Template field definitions
const documentFields = {
    'affidavit-loss': [
        { id: 'fullName', label: 'Full Name', type: 'text' },
        { id: 'address', label: 'Complete Address', type: 'text' },
        { id: 'itemLost', label: 'Item Lost', type: 'text' },
        { id: 'dateOfLoss', label: 'Date of Loss', type: 'date' },
        { id: 'circumstances', label: 'Circumstances of Loss', type: 'textarea' }
    ]
};

// Generate form fields based on selected document
document.addEventListener('DOMContentLoaded', function() {
    const dynamicFields = document.getElementById('dynamicFields');
    const previewSection = document.getElementById('previewSection');
    const printBtn = document.getElementById('printBtn');
    
    // Hide preview section initially
    previewSection.classList.add('d-none');
    printBtn.classList.add('d-none');
});

// Preview generator for affidavit-loss
function generateAffidavitPreview(values) {
    return `
        <div class="legal-document">
            <div class="document-header text-center">
                <h3 class="mb-1">REPUBLIC OF THE PHILIPPINES</h3>
                <h3 class="mb-1">CITY/MUNICIPALITY OF _______________</h3>
                <h3 class="mb-4">PROVINCE OF _______________</h3>
                <h3 class="mb-1">AFFIDAVIT OF LOSS</h3>
            </div>

            <div class="document-body mt-5">
                <p class="text-justify">I, <strong>${values.fullName}</strong>, of legal age, Filipino, ${values.address ? `residing at <strong>${values.address}</strong>,` : ''} after having been duly sworn to in accordance with law, hereby depose and state:</p>
                
                <p class="text-justify mt-4">1. That I am the lawful owner of <strong>${values.itemLost}</strong>;</p>
                
                <p class="text-justify">2. That on or about <strong>${values.dateOfLoss}</strong>, I discovered that I lost the aforementioned item under the following circumstances:</p>
                
                <p class="text-justify indented">${values.circumstances}</p>
                
                <p class="text-justify mt-4">3. That I have exerted all diligent efforts to locate the said item but to no avail;</p>
                
                <p class="text-justify">4. That I am executing this Affidavit to attest to the truth of the foregoing facts and for whatever legal purpose it may serve.</p>
                
                <p class="text-justify mt-5">IN WITNESS WHEREOF, I have hereunto set my hand this _____ day of ______________, 2024 at ________________, Philippines.</p>
                
                <div class="signature-block mt-5">
                    <div class="signature-line">_______________________</div>
                    <div class="signature-name"><strong>${values.fullName}</strong></div>
                    <div class="signature-title">Affiant</div>
                </div>
                
                <div class="jurat mt-5">
                    <p>SUBSCRIBED AND SWORN to before me this _____ day of ______________, 2024 at ________________, Philippines. Affiant exhibited to me his/her valid identification as proof of identity.</p>
                    
                    <div class="notary-block mt-5">
                        <div class="signature-line">_______________________</div>
                        <div class="signature-title">Notary Public</div>
                    </div>
                    
                    <div class="doc-number mt-4">
                        <p>Doc. No. _____;</p>
                        <p>Page No. _____;</p>
                        <p>Book No. _____;</p>
                        <p>Series of 2024.</p>
                    </div>
                </div>
            </div>
        </div>
    `;
}
