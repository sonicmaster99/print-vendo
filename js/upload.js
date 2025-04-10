// Document handling state
let currentDocument = null;
let waitingForPayment = false;
let previewModal = null;  // Add this back

// Default pricing
const PRICE_BW = 3;  // Price per black & white page
const PRICE_COLOR = 5;  // Price per colored page

// Display Machine ID and load configuration
document.addEventListener('DOMContentLoaded', function() {
    const machineId = sessionStorage.getItem('machineId');
    if (!machineId) {
        window.location.href = '../index.html';
        return;
    }

    // Display machine ID
    const machineIdDisplay = document.getElementById('machineId');
    if (machineIdDisplay) {
        machineIdDisplay.textContent = `Machine ID: ${machineId}`;
    }

    // Initialize Bootstrap modal
    previewModal = new bootstrap.Modal(document.getElementById('previewContainer'));

    // Initialize event listeners
    initializeEventListeners();

    // Start checking for payment verification
    // startPaymentVerificationCheck();
});

function initializeEventListeners() {
    // File upload
    const fileInput = document.getElementById('fileUpload');
    if (fileInput) {
        fileInput.addEventListener('change', handleFileUpload);
    }

    // Confirm print button
    const confirmPrintBtn = document.getElementById('confirmPrintBtn');
    if (confirmPrintBtn) {
        confirmPrintBtn.addEventListener('click', handleConfirmPrint);
    }
}

async function handleFileUpload(e) {
    const file = e.target.files[0];
    if (!file) return;

    // Validate file type
    if (!validateFile(file)) {
        e.target.value = '';
        return;
    }

    // Show immediate feedback before processing starts
    const fileLabel = e.target.nextElementSibling || document.querySelector('.text-muted');
    if (fileLabel) {
        fileLabel.innerHTML = `<span class="text-primary"><i class="bi bi-arrow-repeat spinner"></i> Processing: ${file.name}</span>`;
    }

    // Disable file input to prevent double uploads
    e.target.disabled = true;
    
    // Get print mode
    const printMode = document.querySelector('input[name="colorMode"]:checked')?.value || 'bw';

    // Show processing message (with slight delay to ensure UI updates)
    setTimeout(() => {
        const processingSwal = Swal.fire({
            title: 'Processing Document',
            html: 'Please wait...<br>Calculating cost and uploading your document. This may take a moment.',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Store processingSwal in a variable that's accessible in the finally block
        window.currentProcessingSwal = processingSwal;
    }, 100);

    try {
        const machineId = sessionStorage.getItem('machineId');
        const fileId = generateFileId();
        
        // Convert to PDF if needed
        const fileType = file.name.split('.').pop().toLowerCase();
        let pdfFile = file;
        
        if (fileType !== 'pdf') {
            switch (fileType) {
                case 'doc':
                case 'docx':
                    pdfFile = await convertDocToPdf(file);
                    break;
                case 'txt':
                    pdfFile = await convertTxtToPdf(file);
                    break;
                default:
                    throw new Error('Unsupported file type');
            }
        }

        // Convert to black & white if selected
        if (printMode === 'bw') {
            pdfFile = await convertToBlackAndWhite(pdfFile);
        }

        // Calculate cost
        const pageInfo = await analyzePDF(pdfFile, printMode === 'bw');
        
        // Generate cost.txt content
        const costContent = generateCostTxtContent(pageInfo, fileId, machineId);
        const costFile = new File([costContent], 'cost.txt', { type: 'text/plain' });
        
        // Upload both files
        await storeFile(machineId, pdfFile, fileId, pageInfo, printMode, costFile);

        // Store current document info
        currentDocument = {
            type: 'custom',
            file: pdfFile,
            pageInfo: pageInfo,
            fileId: fileId
        };

        // Close processing message
        if (window.currentProcessingSwal) {
            window.currentProcessingSwal.close();
            window.currentProcessingSwal = null;
        }

        // Show success message with callback to show preview
        Swal.fire({
            icon: 'success',
            title: 'Document Processed',
            text: 'Your document has been uploaded and is ready for printing.',
            showConfirmButton: true,
            confirmButtonText: 'Continue',
            allowOutsideClick: false
        }).then((result) => {
            if (result.isConfirmed) {
                // Show preview with cost after user clicks Continue
                showDocumentPreview(null, pageInfo, fileId);
            }
        });

    } catch (error) {
        console.error('Processing error:', error);
        e.target.value = '';
        
        // Close processing message
        if (window.currentProcessingSwal) {
            window.currentProcessingSwal.close();
            window.currentProcessingSwal = null;
        }
        Swal.fire({
            icon: 'error',
            title: 'Processing Error',
            text: 'Failed to process the document. Please try again.'
        });
    } finally {
        // Re-enable file input
        e.target.disabled = false;
        
        // Reset the file label
        const fileLabel = e.target.nextElementSibling || document.querySelector('.text-muted');
        if (fileLabel) {
            fileLabel.innerHTML = 'Supported formats: PDF, DOC, DOCX, TXT';
        }
    }
}

// Generate content for cost.txt file
function generateCostTxtContent(pageInfo, fileId, machineId) {
    // Ensure all values have defaults
    const safePageInfo = {
        totalPages: pageInfo?.totalPages || 0,
        blackAndWhitePages: pageInfo?.blackAndWhitePages || 0,
        coloredPages: pageInfo?.coloredPages || 0,
        totalCost: pageInfo?.totalCost || 0
    };
    
    const timestamp = new Date().toISOString();
    console.log('Generating cost content with:', { safePageInfo, fileId, machineId });
    
    return `Document Cost Summary
-----------------------
File ID: ${fileId || 'Unknown'}
Machine ID: ${machineId || 'Unknown'}
Timestamp: ${timestamp}
Total Pages: ${safePageInfo.totalPages}
Black & White Pages: ${safePageInfo.blackAndWhitePages}
Color Pages: ${safePageInfo.coloredPages}
Cost per B&W Page: ₱${PRICE_BW}
Cost per Color Page: ₱${PRICE_COLOR}
Total Cost: ₱${safePageInfo.totalCost.toFixed(2)}
-----------------------`;
}

// Store uploaded file
async function storeFile(machineId, file, fileId, pageInfo, printMode, costFile = null) {
    try {
        console.log('Starting file upload with params:', { 
            machineId, 
            fileId, 
            printMode,
            fileName: file.name,
            fileSize: file.size
        });
        
        // Create form data for the main file
        const formData = new FormData();
        formData.append('file', file);
        formData.append('machineId', machineId);
        formData.append('fileId', fileId);
        formData.append('pageInfo', JSON.stringify(pageInfo));
        formData.append('printMode', printMode);
        
        // Add cost file if provided
        if (costFile) {
            formData.append('costFile', costFile);
        }

        // Send upload request
        const response = await fetch('../upload_handler.php', {
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

// Function to analyze and process PDF pages
async function analyzePDF(file, forceBW = false) {
    try {
        // Show processing status
        const processingSwal = Swal.fire({
            title: 'Analyzing Document',
            html: 'Processing your PDF...',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Load PDF.js worker if not loaded
        if (!pdfjsLib.GlobalWorkerOptions.workerSrc) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';
        }

        // Load the PDF file
        const arrayBuffer = await file.arrayBuffer();
        const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;

        let blackAndWhitePages = 0;
        let coloredPages = 0;
        const processedPages = [];
        const canvas = document.getElementById('pdfCanvas');
        const ctx = canvas.getContext('2d');

        // Process each page
        for (let i = 1; i <= pdf.numPages; i++) {
            const page = await pdf.getPage(i);
            const viewport = page.getViewport({ scale: 1.5 });
            
            // Set canvas dimensions
            canvas.width = viewport.width;
            canvas.height = viewport.height;

            // Render page
            await page.render({
                canvasContext: ctx,
                viewport: viewport
            }).promise;

            // Get image data for color analysis
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const isColorPage = !forceBW && isColored(imageData);

            // Update page counts
            if (isColorPage) {
                coloredPages++;
            } else {
                blackAndWhitePages++;
            }

            // Create thumbnail
            const thumbCanvas = document.createElement('canvas');
            const thumbCtx = thumbCanvas.getContext('2d');
            const thumbScale = 0.2;
            thumbCanvas.width = viewport.width * thumbScale;
            thumbCanvas.height = viewport.height * thumbScale;

            // Draw thumbnail
            thumbCtx.drawImage(canvas, 0, 0, thumbCanvas.width, thumbCanvas.height);

            // Store page info
            processedPages.push({
                pageNum: i,
                isColor: isColorPage,
                thumbnail: thumbCanvas.toDataURL('image/jpeg', 0.7)
            });
        }

        // Calculate total cost
        const totalCost = (blackAndWhitePages * PRICE_BW) + (coloredPages * PRICE_COLOR);

        // Close processing dialog
        processingSwal.close();

        // Return page info
        return {
            blackAndWhitePages,
            coloredPages,
            totalPages: pdf.numPages,
            pages: processedPages,
            totalCost
        };
    } catch (error) {
        console.error('PDF Analysis Error:', error);
        Swal.close();
        Swal.fire({
            icon: 'error',
            title: 'PDF Analysis Failed',
            text: error.message || 'Failed to analyze PDF file. Please try again.'
        });
        throw error;
    }
}

// Function to check if an image contains color
function isColored(imageData) {
    const data = imageData.data;
    const tolerance = 5; // Color detection tolerance

    for (let i = 0; i < data.length; i += 4) {
        const r = data[i];
        const g = data[i + 1];
        const b = data[i + 2];

        // Skip transparent pixels
        if (data[i + 3] < 10) continue;

        // Check if pixel is not grayscale
        if (Math.abs(r - g) > tolerance || 
            Math.abs(r - b) > tolerance || 
            Math.abs(g - b) > tolerance) {
            return true;
        }
    }
    return false;
}

// Function to convert image to grayscale
function convertToGrayscale(imageData) {
    const data = imageData.data;
    
    for (let i = 0; i < data.length; i += 4) {
        // Skip transparent pixels
        if (data[i + 3] < 10) continue;

        // Convert to grayscale using luminance formula
        const gray = Math.round(
            (data[i] * 0.299) + 
            (data[i + 1] * 0.587) + 
            (data[i + 2] * 0.114)
        );
        
        data[i] = gray;     // Red
        data[i + 1] = gray; // Green
        data[i + 2] = gray; // Blue
    }
    
    return imageData;
}

// Function to convert DOC/DOCX to PDF
async function convertDocToPdf(file) {
    try {
        const arrayBuffer = await file.arrayBuffer();
        const result = await mammoth.convertToHtml({ 
            arrayBuffer,
            preserveStyles: true,
            includeDefaultStyleMap: true,
            styleMap: [
                "p[style-name='Heading 1'] => h1:fresh",
                "p[style-name='Heading 2'] => h2:fresh",
                "p[style-name='Heading 3'] => h3:fresh",
                "p[style-name='Heading 4'] => h4:fresh",
                "r[style-name='Strong'] => strong",
                "r[style-name='Emphasis'] => em"
            ]
        });
        const html = result.value;

        // Create PDF
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        
        // Set document properties
        doc.setProperties({
            title: file.name,
            subject: 'Converted from ' + file.name,
            creator: 'PrintVendo System'
        });

        // Format content
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        document.body.appendChild(tempDiv);

        let y = 20;
        const margin = 10;
        const pageWidth = doc.internal.pageSize.width - (margin * 2);
        const pageHeight = doc.internal.pageSize.height;
        
        // Process all elements including tables, lists, and paragraphs
        const elements = tempDiv.querySelectorAll('*');
        
        for (let i = 0; i < elements.length; i++) {
            const element = elements[i];
            
            // Skip non-content elements or those without visible text
            if (element.tagName === 'HTML' || element.tagName === 'HEAD' || 
                element.tagName === 'BODY' || element.tagName === 'SCRIPT' || 
                element.tagName === 'STYLE' || 
                (element.textContent.trim() === '' && !element.querySelector('img'))) {
                continue;
            }
            
            // Handle different element types
            if (element.tagName.startsWith('H')) {
                // Headings
                const level = parseInt(element.tagName[1]);
                doc.setFontSize(18 - level * 2);
                doc.setFont(undefined, 'bold');
                
                const text = element.textContent.trim();
                if (text) {
                    // Add spacing before headings
                    y += 5;
                    
                    // Check if we need a new page
                    if (y > pageHeight - margin) {
                        doc.addPage();
                        y = 20;
                    }
                    
                    const lines = doc.splitTextToSize(text, pageWidth);
                    doc.text(lines, margin, y);
                    y += lines.length * 7 + 5;
                }
            } else if (element.tagName === 'TABLE') {
                // Tables
                const rows = element.querySelectorAll('tr');
                if (rows.length > 0) {
                    // Add spacing before table
                    y += 5;
                    
                    // Calculate table dimensions
                    const colCount = Math.max(...Array.from(rows).map(row => row.cells.length));
                    const colWidth = pageWidth / colCount;
                    
                    // Process each row
                    for (let r = 0; r < rows.length; r++) {
                        const row = rows[r];
                        const cells = row.cells;
                        let maxCellHeight = 0;
                        
                        // Check if we need a new page
                        if (y > pageHeight - margin - 10) {
                            doc.addPage();
                            y = 20;
                        }
                        
                        // First pass: calculate heights
                        for (let c = 0; c < cells.length; c++) {
                            const cellText = cells[c].textContent.trim();
                            if (cellText) {
                                doc.setFontSize(10);
                                doc.setFont(undefined, r === 0 ? 'bold' : 'normal');
                                const lines = doc.splitTextToSize(cellText, colWidth - 4);
                                const cellHeight = lines.length * 6;
                                maxCellHeight = Math.max(maxCellHeight, cellHeight);
                            }
                        }
                        
                        // Second pass: draw cells
                        for (let c = 0; c < cells.length; c++) {
                            const cellText = cells[c].textContent.trim();
                            if (cellText) {
                                doc.setFontSize(10);
                                doc.setFont(undefined, r === 0 ? 'bold' : 'normal');
                                
                                // Draw cell border
                                doc.setDrawColor(200, 200, 200);
                                doc.rect(margin + (c * colWidth), y, colWidth, maxCellHeight + 4);
                                
                                // Draw text
                                const lines = doc.splitTextToSize(cellText, colWidth - 4);
                                doc.text(lines, margin + (c * colWidth) + 2, y + 4);
                            }
                        }
                        
                        y += maxCellHeight + 4;
                    }
                    
                    // Add spacing after table
                    y += 5;
                }
            } else if (element.tagName === 'UL' || element.tagName === 'OL') {
                // Lists
                const listItems = element.querySelectorAll('li');
                if (listItems.length > 0) {
                    // Add spacing before list
                    y += 3;
                    
                    doc.setFontSize(12);
                    doc.setFont(undefined, 'normal');
                    
                    for (let li = 0; li < listItems.length; li++) {
                        const itemText = listItems[li].textContent.trim();
                        if (itemText) {
                            // Check if we need a new page
                            if (y > pageHeight - margin) {
                                doc.addPage();
                                y = 20;
                            }
                            
                            // Format with bullet or number
                            const prefix = element.tagName === 'UL' ? '• ' : `${li + 1}. `;
                            const lines = doc.splitTextToSize(prefix + itemText, pageWidth);
                            
                            doc.text(lines, margin, y);
                            y += lines.length * 7 + 2;
                        }
                    }
                    
                    // Add spacing after list
                    y += 3;
                }
            } else if (element.tagName === 'IMG') {
                // Images
                try {
                    const imgSrc = element.src;
                    if (imgSrc && !imgSrc.startsWith('data:')) {
                        // Add spacing before image
                        y += 5;
                        
                        // Check if we need a new page
                        if (y > pageHeight - margin - 40) {
                            doc.addPage();
                            y = 20;
                        }
                        
                        // Calculate image dimensions (max width: page width, max height: 1/3 of page)
                        const maxImgWidth = pageWidth;
                        const maxImgHeight = pageHeight / 3;
                        
                        let imgWidth = element.width || 200;
                        let imgHeight = element.height || 150;
                        
                        // Scale image to fit
                        if (imgWidth > maxImgWidth) {
                            const ratio = maxImgWidth / imgWidth;
                            imgWidth = maxImgWidth;
                            imgHeight = imgHeight * ratio;
                        }
                        
                        if (imgHeight > maxImgHeight) {
                            const ratio = maxImgHeight / imgHeight;
                            imgHeight = maxImgHeight;
                            imgWidth = imgWidth * ratio;
                        }
                        
                        // Add image (if possible)
                        try {
                            doc.addImage(imgSrc, 'JPEG', margin, y, imgWidth, imgHeight);
                            y += imgHeight + 5;
                        } catch (imgError) {
                            console.warn('Could not add image:', imgError);
                        }
                    }
                } catch (imgError) {
                    console.warn('Error processing image:', imgError);
                }
            } else if (element.tagName === 'P' || element.tagName === 'DIV') {
                // Paragraphs and divs with content
                const text = element.textContent.trim();
                if (text) {
                    doc.setFontSize(12);
                    doc.setFont(undefined, 'normal');
                    
                    // Check if we need a new page
                    if (y > pageHeight - margin) {
                        doc.addPage();
                        y = 20;
                    }
                    
                    const lines = doc.splitTextToSize(text, pageWidth);
                    doc.text(lines, margin, y);
                    y += lines.length * 7 + 3;
                }
            }
        }

        document.body.removeChild(tempDiv);

        // Convert to PDF file
        const pdfBlob = doc.output('blob');
        return new File([pdfBlob], file.name.replace(/\.(doc|docx)$/i, '.pdf'), {
            type: 'application/pdf'
        });
    } catch (error) {
        console.error('Error converting DOC/DOCX to PDF:', error);
        throw new Error('Failed to convert document to PDF format.');
    }
}

// Function to convert TXT to PDF
async function convertTxtToPdf(file) {
    try {
        const text = await file.text();
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();

        // Set document properties
        doc.setProperties({
            title: file.name,
            subject: 'Converted from ' + file.name,
            creator: 'PrintVendo System'
        });

        // Format settings
        const margin = 10;
        const lineHeight = 7;
        const pageWidth = doc.internal.pageSize.width - (margin * 2);
        const pageHeight = doc.internal.pageSize.height;
        doc.setFontSize(12);
        
        // Add header with filename
        doc.setFontSize(14);
        doc.setFont(undefined, 'bold');
        doc.text(file.name, margin, 15);
        
        // Reset to normal text
        doc.setFontSize(12);
        doc.setFont(undefined, 'normal');
        
        let y = 25; // Start after header
        
        // Process text content with proper line breaks
        const paragraphs = text.split(/\n\s*\n/); // Split by empty lines
        
        for (let i = 0; i < paragraphs.length; i++) {
            const paragraph = paragraphs[i].trim();
            if (paragraph) {
                // Split paragraph into lines that fit the page width
                const lines = doc.splitTextToSize(paragraph, pageWidth);
                
                // Check if we need a new page
                if (y + (lines.length * lineHeight) > pageHeight - margin) {
                    doc.addPage();
                    y = 20;
                }
                
                // Add text
                doc.text(lines, margin, y);
                y += lines.length * lineHeight + 5; // Add extra space between paragraphs
            }
        }

        // Convert to PDF file
        const pdfBlob = doc.output('blob');
        return new File([pdfBlob], file.name.replace(/\.txt$/i, '.pdf'), {
            type: 'application/pdf'
        });
    } catch (error) {
        console.error('Error converting TXT to PDF:', error);
        throw new Error('Failed to convert text file to PDF format.');
    }
}

// Function to analyze DOC/DOCX files
async function analyzeDocument(file, forceBW = false) {
    try {
        const arrayBuffer = await file.arrayBuffer();
        const result = await mammoth.convertToHtml({ arrayBuffer });
        const html = result.value;

        // Create a temporary div to count pages
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        tempDiv.style.width = '8.5in';
        tempDiv.style.height = '11in';
        tempDiv.style.position = 'absolute';
        tempDiv.style.left = '-9999px';
        document.body.appendChild(tempDiv);

        // Estimate pages based on content height
        const contentHeight = tempDiv.scrollHeight;
        const pageHeight = 11 * 96; // 11 inches at 96 DPI
        const totalPages = Math.ceil(contentHeight / pageHeight);

        document.body.removeChild(tempDiv);

        // If color mode is selected (not forceBW), treat all pages as color
        return {
            blackAndWhitePages: forceBW ? totalPages : 0,
            coloredPages: forceBW ? 0 : totalPages,
            totalPages: totalPages,
            content: html,
            totalCost: forceBW ? (totalPages * PRICE_BW) : (totalPages * PRICE_COLOR)
        };
    } catch (error) {
        console.error('Error analyzing document:', error);
        throw error;
    }
}

// Function to analyze TXT files
async function analyzeTXT(file, forceBW = false) {
    try {
        const text = await file.text();
        const linesPerPage = 50; // Approximate lines per page
        const lines = text.split('\n').length;
        const totalPages = Math.ceil(lines / linesPerPage);

        // If color mode is selected (not forceBW), treat all pages as color
        return {
            blackAndWhitePages: forceBW ? totalPages : 0,
            coloredPages: forceBW ? 0 : totalPages,
            totalPages: totalPages,
            content: text,
            totalCost: forceBW ? (totalPages * PRICE_BW) : (totalPages * PRICE_COLOR)
        };
    } catch (error) {
        console.error('Error analyzing text file:', error);
        throw error;
    }
}

// Generate unique file ID
function generateFileId() {
    return 'doc_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

// Validate file type
function validateFile(file) {
    const fileType = file.name.split('.').pop().toLowerCase();
    
    // Validate file type only
    if (!['pdf', 'doc', 'docx', 'txt'].includes(fileType)) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid File Type',
            text: 'Please select a PDF, DOC, DOCX, or TXT file.'
        });
        return false;
    }

    return true;
}

// Function to convert PDF to black and white
async function convertToBlackAndWhite(pdfFile) {
    try {
        // Create a canvas element for processing
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        
        // Load the PDF
        const pdfData = await pdfFile.arrayBuffer();
        const pdf = await pdfjsLib.getDocument({ data: pdfData }).promise;
        
        // Create a new PDF document
        const { jsPDF } = window.jspdf;
        const newPdf = new jsPDF();
        
        // Process each page
        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            const page = await pdf.getPage(pageNum);
            const viewport = page.getViewport({ scale: 2 }); // Higher scale for better quality
            
            // Set canvas size to match page
            canvas.width = viewport.width;
            canvas.height = viewport.height;

            // Render page to canvas
            await page.render({
                canvasContext: ctx,
                viewport: viewport
            }).promise;
            
            // Get image data
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const pixels = imageData.data;
            
            // Convert to grayscale
            for (let i = 0; i < pixels.length; i += 4) {
                const avg = (pixels[i] + pixels[i + 1] + pixels[i + 2]) / 3;
                pixels[i] = avg;     // R
                pixels[i + 1] = avg; // G
                pixels[i + 2] = avg; // B
                // pixels[i + 3] is Alpha, unchanged
            }
            
            // Put grayscale image back
            ctx.putImageData(imageData, 0, 0);
            
            // Add to new PDF
            if (pageNum > 1) {
                newPdf.addPage();
            }
            const imgData = canvas.toDataURL('image/jpeg', 0.95);
            newPdf.addImage(imgData, 'JPEG', 0, 0, newPdf.internal.pageSize.getWidth(), newPdf.internal.pageSize.getHeight());
        }
        
        // Convert to Blob
        const blobPdf = new Blob([newPdf.output('arraybuffer')], { type: 'application/pdf' });
        return new File([blobPdf], pdfFile.name, { type: 'application/pdf' });
        
    } catch (error) {
        console.error('Error converting to black and white:', error);
        throw new Error('Failed to convert document to black and white');
    }
}

function showDocumentPreview(content, pageInfo, fileId) {
    // Display the preview directly without additional loading message
    const previewContent = document.getElementById('previewContent');
    
    // Generate and show cost summary
    const costSummary = generateCostSummary(pageInfo, fileId);
    previewContent.innerHTML = costSummary;

    // Show the modal
    previewModal.show();
}

function generateCostSummary(pageInfo, fileId) {
    const totalPages = pageInfo.totalPages;
    const totalCost = pageInfo.totalCost;
    
    return `
        <div class="preview-info">
            <h6>Document Summary</h6>
            <p>Document ID: ${fileId}</p>
            <p>Total Pages: ${totalPages}</p>
            <p>Cost: ₱${totalCost.toFixed(2)}</p>
            <div class="page-list">
                ${pageInfo.pages.map((page, i) => `
                    <div class="page-item">
                        <span>Page ${i + 1}</span>
                        <span>${page.isColor ? 'Color' : 'Black & White'}</span>
                        <span>₱${(page.isColor ? PRICE_COLOR : PRICE_BW).toFixed(2)}</span>
                    </div>
                `).join('')}
            </div>
            <div class="alert alert-info mt-3">
                <i class="bi bi-info-circle"></i> 
                Your document has been uploaded. Proceed to the vending machine to complete payment and print.
            </div>
        </div>
    `;
}

async function handleConfirmPrint() {
    try {
        // Disable the confirm print button to prevent double-clicks
        const confirmPrintBtn = document.getElementById('confirmPrintBtn');
        if (confirmPrintBtn) {
            confirmPrintBtn.disabled = true;
            confirmPrintBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
        }

        const machineId = sessionStorage.getItem('machineId');
        if (!currentDocument || !machineId) {
            throw new Error('No document selected or machine ID not found');
        }

        // Get print mode and page info
        const printMode = document.querySelector('input[name="colorMode"]:checked')?.value || 'bw';
        const pageInfo = currentDocument.pageInfo;

        // Debug pageInfo
        console.log('Original pageInfo:', JSON.stringify(pageInfo));
        
        // Ensure pageInfo has all required properties
        const safePageInfo = {
            totalPages: pageInfo?.totalPages || 0,
            blackAndWhitePages: pageInfo?.blackAndWhitePages || 0,
            coloredPages: pageInfo?.coloredPages || 0,
            totalCost: pageInfo?.totalCost || 0
        };
        
        // Recalculate total cost if it's missing or zero
        if (!safePageInfo.totalCost) {
            safePageInfo.totalCost = 
                (PRICE_BW * safePageInfo.blackAndWhitePages) + 
                (PRICE_COLOR * safePageInfo.coloredPages);
        }

        const totalCost = safePageInfo.totalCost;

        // Debug output
        console.log('Safe pageInfo:', safePageInfo);
        console.log('Total cost:', totalCost);

        // Generate cost content first to ensure it exists
        const costContent = generateCostTxtContent(safePageInfo, currentDocument.fileId, machineId);
        console.log('Cost content:', costContent);
        
        // Verify cost content is not empty
        if (!costContent) {
            throw new Error('Failed to generate cost content');
        }

        // Create a cost file
        const costFile = new File([costContent], 'cost.txt', { type: 'text/plain' });

        // Log the total cost that will be used for payment
        console.log('Print job total cost: ₱' + totalCost.toFixed(2));

        // Create form data for prepare_print.php
        const formData = new FormData();
        formData.append('machineId', machineId);
        formData.append('content', costContent); // Document content (cost info in this case)
        formData.append('fileId', currentDocument.fileId);
        formData.append('pageInfo', JSON.stringify(safePageInfo));
        formData.append('printType', printMode);
        
        // Add the PDF file if available
        if (currentDocument.file) {
            formData.append('pdfFile', currentDocument.file);
        }
        
        // Add the cost file
        formData.append('costFile', costFile);
        
        console.log('Sending print preparation data with keys:', Array.from(formData.keys()));
        
        // Send request to prepare_print.php instead of upload_handler.php
        const response = await fetch('../prepare_print.php', {
            method: 'POST',
            body: formData
        });

        // Create a backup copy of the response for debugging
        const responseClone = response.clone();
        
        try {
            const result = await response.json();
            console.log('Prepare print response:', result);
            
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Failed to prepare print job');
            }
            
            // Close the preview modal
            previewModal.hide();
            
            // Show success message with payment instructions
            Swal.fire({
                icon: 'success',
                title: 'Print Job Ready',
                html: `
                    <div class="alert alert-info">
                        <h6>Print Summary:</h6>
                        <p>Total Pages: ${safePageInfo.totalPages}</p>
                        <p>Total Cost: ₱${totalCost.toFixed(2)}</p>
                        <hr>
                        <p class="mb-0">Please proceed to the vending machine to complete your payment and print your document.</p>
                    </div>
                `,
                showConfirmButton: true,
                confirmButtonText: 'OK'
            });

            // Set waiting for payment flag
            waitingForPayment = true;
            
            // Start checking for payment verification
            startPaymentVerificationCheck('print');
            
        } catch (jsonError) {
            console.error('Error parsing JSON response:', jsonError);
            
            // Try to get the raw text response for debugging
            const rawText = await responseClone.text();
            console.error('Raw response:', rawText);
            throw new Error('Invalid response from server: ' + rawText);
        }
    } catch (error) {
        console.error('Print confirmation error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: error.message || 'Failed to process print request.',
            confirmButtonText: 'OK'
        });
    } finally {
        // Re-enable the confirm print button
        const confirmPrintBtn = document.getElementById('confirmPrintBtn');
        if (confirmPrintBtn) {
            confirmPrintBtn.disabled = false;
            confirmPrintBtn.innerHTML = 'Confirm and Print';
        }
    }
}

// Function to check for payment verification
function startPaymentVerificationCheck(paymentType = 'regular') {
    if (waitingForPayment) {
        const machineId = sessionStorage.getItem('machineId');
        if (!machineId) return;

        console.log(`Checking ${paymentType} payment verification for machine ${machineId}`);
        
        // Clear any existing interval
        if (window.paymentCheckInterval) {
            clearInterval(window.paymentCheckInterval);
        }
        
        // Set up payment verification check
        window.paymentCheckInterval = setInterval(async () => {
            try {
                // Use different endpoint based on payment type
                let endpoint = '../check_payment.php';
                
                // Add parameters
                const params = new URLSearchParams({
                    machineId: machineId,
                    type: paymentType
                });
                
                const response = await fetch(`${endpoint}?${params}`);
                const result = await response.json();
                
                console.log('Payment verification check result:', result);
                
                if (result.success && result.paid) {
                    // Payment verified
                    clearInterval(window.paymentCheckInterval);
                    waitingForPayment = false;
                    
                    // Show payment confirmation
                    Swal.fire({
                        icon: 'success',
                        title: 'Payment Received',
                        text: paymentType === 'print' 
                            ? 'Your document is now being printed.' 
                            : 'Your ChatGPT response is ready.',
                        confirmButtonText: 'OK'
                    });
                    
                    // For print jobs, no further action needed
                    // The printer will handle the document
                }
            } catch (error) {
                console.error('Payment verification check error:', error);
            }
        }, 5000); // Check every 5 seconds
    }
}
