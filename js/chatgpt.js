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
});

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
                window.location.href = `edit_prompt.html?machineId=${machineId}`;
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
                        ${document.getElementById('saveAsPdf')?.checked ? '<li><em>Will be saved as PDF</em></li>' : ''}
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
    // This is a more accurate estimation than just counting words
    const characters = text.length;
    
    // Account for whitespace, punctuation, and special characters
    const words = text.trim().split(/\s+/).length;
    
    // Adjust for different languages and special characters
    // Average English text: ~4 chars per token
    // Code or text with many special chars: ~3 chars per token
    // Determine if text likely contains code (more special characters)
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
function calculateCost(promptText) {
    // Token pricing (per 1K tokens)
    const usdPerInputToken = 0.0015 / 1000;  // $0.0015 per 1K input tokens
    const usdPerOutputToken = 0.002 / 1000;   // $0.002 per 1K output tokens
    const usdToPhp = 58;
    const markup = 20;

    // Estimate input tokens
    const inputTokens = estimateTokens(promptText);
    
    // Estimate output tokens - GPT-3.5 typically generates 1.5-3x the input tokens
    // For shorter prompts, it tends to generate more relative to input
    // For longer prompts, it tends to be more concise
    let outputTokenMultiplier;
    if (inputTokens < 100) {
        outputTokenMultiplier = 3; // Short prompts often get more verbose responses
    } else if (inputTokens < 500) {
        outputTokenMultiplier = 2.5; // Medium prompts
    } else if (inputTokens < 1000) {
        outputTokenMultiplier = 2; // Longer prompts
    } else {
        outputTokenMultiplier = 1.5; // Very long prompts get more concise responses
    }
    
    const outputTokens = Math.ceil(inputTokens * outputTokenMultiplier);
    
    // Calculate costs in USD
    const inputCostUsd = inputTokens * usdPerInputToken;
    const outputCostUsd = outputTokens * usdPerOutputToken;
    
    // Convert to PHP with markup
    const inputCostPhp = inputCostUsd * usdToPhp * markup;
    const outputCostPhp = outputCostUsd * usdToPhp * markup;
    const totalCostPhp = inputCostPhp + outputCostPhp;
    
    // Calculate final cost (rounded up to nearest peso)
    const finalCost = Math.ceil(totalCostPhp);
    
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

// Generate cost.txt content
function generateCostContent(costDetails) {
    // Create the initial cost summary with pre-calculated values
    let content = `Cost Summary
-------------
Input Words: ${costDetails.inputWords}
Input Tokens: ${costDetails.inputTokens}
Output Words: ${costDetails.estimatedOutputWords}
Output Tokens: ${costDetails.outputTokens}
Total Cost: ₱${costDetails.finalCost.toFixed(2)}
-------------`;

    return content;
}

// Save cost file
async function saveCostFile(machineId, costDetails, format = 'text') {
    // First, try to get the actual cost details from the server
    try {
        const costResponse = await fetch(`../get_cost_details.php?machineId=${machineId}`);
        const costData = await costResponse.json();
        
        if (costData.success && costData.costDetails) {
            // Use the actual cost details from the server
            costDetails.finalCost = costData.costDetails.finalCost || costData.costDetails.costPhp || costDetails.finalCost;
            // Update token counts if available
            if (costData.costDetails.inputTokens) costDetails.inputTokens = costData.costDetails.inputTokens;
            if (costData.costDetails.outputTokens) costDetails.outputTokens = costData.costDetails.outputTokens;
        }
    } catch (error) {
        console.error('Error fetching actual cost details for cost file:', error);
        // Continue with pre-calculated values if there's an error
    }

    const formData = new FormData();
    formData.append('machineId', machineId);
    formData.append('costContent', generateCostContent(costDetails));
    formData.append('amount', costDetails.finalCost.toFixed(2));
    formData.append('format', format);

    const response = await fetch('../save_cost.php', {
        method: 'POST',
        body: formData
    });

    if (!response.ok) {
        throw new Error('Failed to save cost file');
    }

    return await response.json();
}

// Handle form submission
document.getElementById('promptForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const promptText = document.getElementById('promptText').value;
    const generateBtn = document.getElementById('generateBtn');
    const machineId = sessionStorage.getItem('machineId');
    const saveAsPdfElement = document.getElementById('saveAsPdf');
    const saveAsPdf = saveAsPdfElement ? saveAsPdfElement.checked : false;
    
    // Validate input
    if (!promptText.trim()) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing Information',
            text: 'Please enter your prompt first.',
            background: '#1e1e1e',
            color: '#ffffff'
        });
        return;
    }

    // Check if button is already disabled (prevent double submission)
    if (generateBtn.disabled) {
        return;
    }

    try {
        // Disable button and show processing indicator
        generateBtn.disabled = true;
        const originalBtnText = generateBtn.innerHTML;
        generateBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
        
        // Calculate cost estimate with our improved function
        const costDetails = calculateCost(promptText);
        
        // Store the estimated cost in the config
        window.PrintVendoConfig.setRequiredAmount(costDetails.finalCost);

        // Hide cost display during processing - we'll show actual costs later
        const costDisplay = document.getElementById('costDisplay');
        const costDetailsElement = document.getElementById('costDetails');
        costDisplay.style.display = 'none';
        
        // Prepare cost details content but don't display yet
        costDetailsElement.innerHTML = `
            <ul class="list-unstyled mb-0">
                <li><strong>Input:</strong> ${costDetails.inputTokens} tokens (₱${costDetails.inputCostPhp})</li>
                <li><strong>Output:</strong> ${costDetails.outputTokens} tokens (₱${costDetails.outputCostPhp})</li>
                <li><strong>Final Cost:</strong> ₱${(costDetails.finalCost || costDetails.costPhp || 0).toFixed(2)}</li>
                ${saveAsPdf ? '<li><em>Will be saved as PDF</em></li>' : ''}
            </ul>
        `;

        // Save prompt with pre-calculated cost
        const promptFormData = new FormData();
        promptFormData.append('machineId', machineId);
        promptFormData.append('promptText', promptText);
        promptFormData.append('format', saveAsPdf ? 'pdf' : 'text');
        promptFormData.append('calculatedCost', costDetails.finalCost);
        promptFormData.append('inputTokens', costDetails.inputTokens);
        promptFormData.append('outputTokens', costDetails.outputTokens);

        // Send request to save prompt and generate response
        const promptResponse = await fetch('../save_prompt.php', {
            method: 'POST',
            body: promptFormData
        });

        if (!promptResponse.ok) {
            throw new Error(`Failed to save prompt: ${promptResponse.status} ${promptResponse.statusText}`);
        }

        // Parse the response to check for any server-side errors
        const promptResult = await promptResponse.json();
        if (!promptResult.success) {
            throw new Error(promptResult.message || 'Server error while processing prompt');
        }

        // Save cost file with pre-calculated values
        await saveCostFile(machineId, {
            finalCost: costDetails.finalCost,
            inputTokens: costDetails.inputTokens,
            outputTokens: costDetails.outputTokens,
            inputWords: promptText.split(/\s+/).length,
            estimatedOutputWords: Math.ceil(costDetails.outputTokens / 1.3)
        }, saveAsPdf ? 'pdf' : 'text');
        
        // Keep button disabled but restore original text
        generateBtn.innerHTML = originalBtnText;
        
        // Fetch actual cost details before showing any messages
        let actualCost = costDetails.finalCost || costDetails.costPhp || 0;
        
        try {
            const costResponse = await fetch(`../get_cost_details.php?machineId=${machineId}`);
            const costData = await costResponse.json();
            
            if (costData.success && costData.costDetails) {
                // Use the actual cost details from the server
                actualCost = costData.costDetails.finalCost || costData.costDetails.costPhp || costDetails.finalCost || 0;
                
                // Update the cost details display with actual values from the server
                if (costData.costDetails.inputTokens && costData.costDetails.outputTokens) {
                    costDetailsElement.innerHTML = `
                        <ul class="list-unstyled mb-0">
                            <li><strong>Input:</strong> ${costData.costDetails.inputTokens} tokens (₱${costData.costDetails.inputCostPhp || costDetails.inputCostPhp})</li>
                            <li><strong>Output:</strong> ${costData.costDetails.outputTokens} tokens (₱${costData.costDetails.outputCostPhp || costDetails.outputCostPhp})</li>
                            <li><strong>Final Cost:</strong> ₱${actualCost.toFixed(2)}</li>
                            ${saveAsPdf ? '<li><em>Saved as PDF</em></li>' : ''}
                        </ul>
                    `;
                    // Now we can show the cost display with accurate values
                    costDisplay.style.display = 'block';
                }
            }
        } catch (error) {
            console.error('Error fetching actual cost details:', error);
            // Fallback to pre-calculated cost if there's an error
        }
        
        // Show waiting for payment message with the actual cost
        document.getElementById('waitingForPayment').style.display = 'block';
        
        // Update payment message with correct amount
        const waitingMessage = document.getElementById('waitingForPayment');
        if (waitingMessage) {
            const messageElement = waitingMessage.querySelector('p');
            if (messageElement) {
                messageElement.textContent = `Please pay ₱${actualCost.toFixed(2)} to proceed.`;
            }
        }
        
        // Show success message with the same actual cost
        Swal.fire({
            icon: 'success',
            title: 'Prompt Submitted',
            text: `Please pay ₱${actualCost.toFixed(2)} to proceed.`,
            background: '#1e1e1e',
            color: '#ffffff'
        });
        
        // Start payment verification check
        startPaymentVerificationCheck();
        
    } catch (error) {
        console.error('Error:', error);
        
        // Re-enable button and restore original text
        generateBtn.disabled = false;
        if (generateBtn.querySelector('.spinner-border')) {
            generateBtn.innerHTML = 'Generate Response';
        }
        
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: `Failed to process your request: ${error.message}`,
            background: '#1e1e1e',
            color: '#ffffff'
        });
    }
});
