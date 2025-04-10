// Generate unique file ID
function generateFileId() {
    return 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

// Display Machine ID
document.addEventListener('DOMContentLoaded', function() {
    // Display machine ID
    const machineId = sessionStorage.getItem('machineId');
    if (machineId) {
        document.getElementById('machineIdDisplay').textContent = `Machine ID: ${machineId}`;
    } else {
        window.location.href = '../index.html';
    }
    
    // Start checking for payment verification
    startPaymentVerificationCheck();

    // Fetch pricing data
    fetchPricing();
});

// Fetch pricing data from the server
function fetchPricing() {
    fetch('../get_pricing.php')
        .then(response => response.json())
        .then(pricing => {
            const modelSelect = document.getElementById('model');
            if (pricing && pricing.success) {
                modelSelect.innerHTML = `
                    <option value="3.5">Model 3.5 - PHP ${pricing.pricing['3.5']}/100 tokens</option>
                    <option value="4.0">Model 4.0 - PHP ${pricing.pricing['4.0']}/100 tokens</option>
                    <option value="4.5">Model 4.5 - PHP ${pricing.pricing['4.5']}/100 tokens</option>
                `;
            }
        })
        .catch(error => console.error('Error fetching pricing:', error));
}

// Check for payment verification file
function startPaymentVerificationCheck() {
    // Check every 0.25 seconds
    setInterval(checkPaymentVerification, 250);
}

// Check if payment has been verified
function checkPaymentVerification() {
    const machineId = sessionStorage.getItem('machineId');
    
    // Only check if we're waiting for payment
    if (document.getElementById('waitingForPayment').style.display !== 'none') {
        // Check for Amount_paid.txt
        fetch(`../check_payment.php?machineId=${machineId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.paid) {
                // Payment verified, redirect to edit page with machineId parameter
                window.location.href = `edit_document_response.html?machineId=${machineId}`;
            }
        })
        .catch(error => {
            console.error('Error checking payment:', error);
        });

        // Check for actual cost details
        fetch(`../get_cost_details.php?machineId=${machineId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.costDetails) {
                // Update cost display with actual values
                const costDetailsElement = document.getElementById('costDetails');
                const costDetails = data.costDetails;
                
                costDetailsElement.innerHTML = `
                    <ul class="list-unstyled mb-0">
                        <li><strong>Input:</strong> ${costDetails.inputTokens} tokens (₱${costDetails.inputCostPhp})</li>
                        <li><strong>Output:</strong> ${costDetails.outputTokens} tokens (₱${costDetails.outputCostPhp})</li>
                        <li><strong>Final Cost:</strong> ₱${(costDetails.finalCost || costDetails.costPhp || 0).toFixed(2)}</li>
                    </ul>
                `;

                // Update required amount
                window.PrintVendoConfig.setRequiredAmount(costDetails.finalCost || costDetails.costPhp || 0);
                
                // Update payment message
                const waitingMessage = document.getElementById('waitingForPayment');
                if (waitingMessage) {
                    const messageElement = waitingMessage.querySelector('p');
                    if (messageElement) {
                        messageElement.textContent = `Please pay ₱${(costDetails.finalCost || costDetails.costPhp || 0).toFixed(2)} to proceed.`;
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error fetching cost details:', error);
        });
    }
}

// Improved token estimation function for more accurate cost predictions
function estimateTokens(text) {
    if (!text) return 0;
    
    // GPT models use tokens that are about 4 characters on average for English text
    const characters = text.length;
    
    // Account for whitespace, punctuation, and special characters
    const words = text.trim().split(/\s+/).length;
    
    // Adjust for different languages and special characters
    // Average English text: ~4 chars per token
    // Code or text with many special chars: ~3 chars per token
    const hasCodeIndicators = /[{}\[\]()=><;:"`']/.test(text) && 
                             (text.split(/[{}\[\]()=><;:"`']/).length > words * 0.1);
    
    // Calculate token estimate based on content type
    const charsPerToken = hasCodeIndicators ? 3 : 4;
    
    // Base calculation
    let tokenEstimate = Math.ceil(characters / charsPerToken);
    
    // Add a small buffer for safety (about 5%)
    tokenEstimate = Math.ceil(tokenEstimate * 1.05);
    
    return tokenEstimate;
}

// Update the calculateCost function with more accurate output token estimation
function calculateCost(promptText, documentSize) {
    // Token pricing (per 1K tokens)
    const usdPerInputToken = 0.0015 / 1000;  // $0.0015 per 1K input tokens
    const usdPerOutputToken = 0.002 / 1000;   // $0.002 per 1K output tokens
    const usdToPhp = 58;
    const markup = 20;

    // Estimate input tokens from prompt
    const promptTokens = estimateTokens(promptText);
    
    // Estimate document tokens (rough estimate based on file size)
    // Assuming 1KB ~= 200 tokens for text files
    const documentTokens = Math.ceil(documentSize / 1024 * 200);
    
    // Total input tokens
    const inputTokens = promptTokens + documentTokens;
    
    // Estimate output tokens - GPT-3.5 typically generates 1.5-3x the prompt tokens
    // For document analysis, we typically get more concise responses
    let outputTokenMultiplier;
    if (inputTokens < 500) {
        outputTokenMultiplier = 2; // Short inputs often get more verbose responses
    } else if (inputTokens < 2000) {
        outputTokenMultiplier = 1.5; // Medium inputs
    } else {
        outputTokenMultiplier = 1; // Long inputs get more concise responses
    }
    
    const outputTokens = Math.ceil(promptTokens * outputTokenMultiplier); // Only multiply the prompt tokens
    
    // Calculate costs in USD
    const inputCostUsd = inputTokens * usdPerInputToken;
    const outputCostUsd = outputTokens * usdPerOutputToken;
    
    // Convert to PHP with markup
    const inputCostPhp = inputCostUsd * usdToPhp * markup;
    const outputCostPhp = outputCostUsd * usdToPhp * markup;
    const totalCostPhp = inputCostPhp + outputCostPhp;
    
    // Calculate final cost (rounded up to nearest peso, minimum 5 pesos)
    const finalCost = Math.max(5, Math.ceil(totalCostPhp));
    
    return {
        inputTokens: inputTokens,
        outputTokens: outputTokens,
        inputCostUsd: round(inputCostUsd, 4),
        outputCostUsd: round(outputCostUsd, 4),
        inputCostPhp: round(inputCostPhp, 2),
        outputCostPhp: round(outputCostPhp, 2),
        totalCostPhp: round(totalCostPhp, 2),
        finalCost: finalCost
    };
}

// Helper function for rounding
function round(value, decimals) {
    return Number(Math.round(value + 'e' + decimals) + 'e-' + decimals);
}

// Handle form submission
document.getElementById('uploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const fileInput = document.getElementById('document');
    const promptText = document.getElementById('prompt').value;
    const model = document.getElementById('model').value;
    const machineId = sessionStorage.getItem('machineId');
    const generateBtn = document.getElementById('generateBtn');
    
    if (!fileInput.files || fileInput.files.length === 0) {
        Swal.fire({
            icon: 'error',
            title: 'No File Selected',
            text: 'Please select a document to upload.'
        });
        return;
    }
    
    const file = fileInput.files[0];
    
    try {
        // Disable button and show processing indicator
        generateBtn.disabled = true;
        const originalBtnText = generateBtn.innerHTML;
        generateBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
        
        // Show loading spinner
        document.getElementById('loadingSpinner').style.display = 'flex';
        
        // Calculate cost based on prompt and document size
        const costDetails = calculateCost(promptText, file.size);
        
        // Create FormData object
        const formData = new FormData();
        formData.append('document', file);
        formData.append('prompt', promptText);
        formData.append('model', model);
        formData.append('machineId', machineId);
        formData.append('cost', costDetails.finalCost);
        
        // Upload document and prompt
        const response = await fetch('../upload_document.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || 'Failed to process document');
        }
        
        // Hide loading spinner
        document.getElementById('loadingSpinner').style.display = 'none';
        
        // Update cost display
        const costDetailsElement = document.getElementById('costDetails');
        costDetailsElement.innerHTML = `
            <ul class="list-unstyled mb-0">
                <li><strong>Input:</strong> ${costDetails.inputTokens} tokens (₱${costDetails.inputCostPhp})</li>
                <li><strong>Output:</strong> ${costDetails.outputTokens} tokens (₱${costDetails.outputCostPhp})</li>
                <li><strong>Final Cost:</strong> ₱${costDetails.finalCost.toFixed(2)}</li>
            </ul>
        `;
        
        // Show cost display
        document.getElementById('costDisplay').style.display = 'block';
        
        // Set required amount in PrintVendoConfig
        window.PrintVendoConfig.setRequiredAmount(costDetails.finalCost);
        
        // Show waiting for payment message with the actual cost
        document.getElementById('waitingForPayment').style.display = 'block';
        
        // Update payment message with correct amount
        const waitingMessage = document.getElementById('waitingForPayment');
        if (waitingMessage) {
            const messageElement = waitingMessage.querySelector('p');
            if (messageElement) {
                messageElement.textContent = `Please pay ₱${costDetails.finalCost.toFixed(2)} to proceed.`;
            }
        }
        
        // Re-enable button and restore original text
        generateBtn.disabled = false;
        generateBtn.innerHTML = originalBtnText;
        
    } catch (error) {
        console.error('Error:', error);
        
        // Hide loading spinner
        document.getElementById('loadingSpinner').style.display = 'none';
        
        // Re-enable button and restore original text
        generateBtn.disabled = false;
        generateBtn.innerHTML = originalBtnText;
        
        // Show error message
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: error.message || 'An error occurred while processing your request.'
        });
    }
});
