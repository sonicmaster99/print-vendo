/**
 * PDF to HTML Converter with Exact Layout Preservation
 * 
 * This script enhances the PDF to HTML conversion process by:
 * 1. Providing a drag-and-drop interface for PDF uploads
 * 2. Handling the conversion process via AJAX
 * 3. Displaying the converted HTML with preserved layout
 * 4. Offering options to download or view the HTML source
 */

class PdfConverter {
    constructor() {
        // Elements
        this.dropZone = document.getElementById('dropZone');
        this.browseBtn = document.getElementById('browseBtn');
        this.pdfFileInput = document.getElementById('pdfFile');
        this.fileInfo = document.getElementById('fileInfo');
        this.fileName = document.getElementById('fileName');
        this.removeFileBtn = document.getElementById('removeFile');
        this.convertBtn = document.getElementById('convertBtn');
        this.loadingSpinner = document.getElementById('loadingSpinner');
        this.previewSection = document.getElementById('previewSection');
        this.previewFrame = document.getElementById('previewFrame');
        this.downloadBtn = document.getElementById('downloadBtn');
        this.viewSourceBtn = document.getElementById('viewSourceBtn');
        this.htmlSource = document.getElementById('htmlSource');
        this.copySourceBtn = document.getElementById('copySourceBtn');
        this.pdfTitle = document.getElementById('pdfTitle');
        this.pageCount = document.getElementById('pageCount');
        this.pdfAuthor = document.getElementById('pdfAuthor');
        this.exactLayoutToggle = document.getElementById('exactLayoutToggle');
        
        // Modal
        this.sourceModal = new bootstrap.Modal(document.getElementById('sourceModal'));
        
        // Bind event handlers
        this.bindEvents();
    }
    
    bindEvents() {
        // Browse button click handler
        this.browseBtn.addEventListener('click', () => this.pdfFileInput.click());
        
        // File input change handler
        this.pdfFileInput.addEventListener('change', () => this.handleFileSelection(this.pdfFileInput.files));
        
        // Drag and drop handlers
        this.dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            this.dropZone.classList.add('dragover');
        });
        
        this.dropZone.addEventListener('dragleave', () => {
            this.dropZone.classList.remove('dragover');
        });
        
        this.dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            this.dropZone.classList.remove('dragover');
            this.handleFileSelection(e.dataTransfer.files);
        });
        
        // Remove file button handler
        this.removeFileBtn.addEventListener('click', () => {
            this.pdfFileInput.value = '';
            this.fileInfo.classList.add('d-none');
        });
        
        // Form submission handler
        document.getElementById('pdfConverterForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.convertPdf();
        });
        
        // View source button handler
        this.viewSourceBtn.addEventListener('click', () => {
            this.sourceModal.show();
        });
        
        // Copy source button handler
        this.copySourceBtn.addEventListener('click', () => {
            const textToCopy = this.htmlSource.textContent;
            navigator.clipboard.writeText(textToCopy)
                .then(() => {
                    this.copySourceBtn.innerHTML = '<i class="fas fa-check me-2"></i> Copied!';
                    setTimeout(() => {
                        this.copySourceBtn.innerHTML = '<i class="fas fa-copy me-2"></i> Copy to Clipboard';
                    }, 2000);
                })
                .catch(err => {
                    console.error('Failed to copy text: ', err);
                    Swal.fire({
                        icon: 'error',
                        title: 'Copy Failed',
                        text: 'Failed to copy text to clipboard.'
                    });
                });
        });
    }
    
    handleFileSelection(files) {
        if (files && files.length > 0) {
            const file = files[0];
            
            if (file.type !== 'application/pdf') {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid File Type',
                    text: 'Please select a valid PDF file.'
                });
                return;
            }
            
            this.fileName.textContent = file.name;
            this.fileInfo.classList.remove('d-none');
            
            // Set the file to the input
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            this.pdfFileInput.files = dataTransfer.files;
        }
    }
    
    convertPdf() {
        if (!this.pdfFileInput.files || this.pdfFileInput.files.length === 0) {
            Swal.fire({
                icon: 'error',
                title: 'No File Selected',
                text: 'Please select a PDF file to convert.'
            });
            return;
        }
        
        const file = this.pdfFileInput.files[0];
        if (file.type !== 'application/pdf') {
            Swal.fire({
                icon: 'error',
                title: 'Invalid File Type',
                text: 'Please select a valid PDF file.'
            });
            return;
        }
        
        // Show loading spinner
        this.loadingSpinner.style.display = 'flex';
        
        // Create form data
        const formData = new FormData();
        formData.append('pdfFile', file);
        
        // Add option for exact layout preservation
        if (this.exactLayoutToggle && this.exactLayoutToggle.checked) {
            formData.append('preserveExactLayout', '1');
        }
        
        // Send request to convert PDF
        fetch('../pdf_to_html.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Server error: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            // Hide loading spinner
            this.loadingSpinner.style.display = 'none';
            
            if (data.success) {
                this.displayConversionResult(data);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Conversion Failed',
                    text: data.message || 'An error occurred during conversion.'
                });
            }
        })
        .catch(error => {
            // Hide loading spinner
            this.loadingSpinner.style.display = 'none';
            
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Conversion Failed',
                text: 'An error occurred during the conversion process. Please try again.'
            });
        });
    }
    
    displayConversionResult(data) {
        // Update document info
        this.pdfTitle.textContent = data.title || 'Unknown';
        this.pageCount.textContent = data.pageCount || '0';
        this.pdfAuthor.textContent = data.author || 'Unknown';
        
        // Update layout info if available
        if (document.getElementById('layoutInfo')) {
            document.getElementById('layoutInfo').textContent = 
                data.usedExactLayout ? 'Exact Layout Preserved' : 'Basic Layout';
        }
        
        // Set download link
        this.downloadBtn.href = '../' + data.htmlFile;
        
        // Load preview
        this.previewFrame.src = '../' + data.htmlFile;
        
        // Show preview section
        this.previewSection.classList.remove('d-none');
        
        // Scroll to preview
        this.previewSection.scrollIntoView({ behavior: 'smooth' });
        
        // Fetch HTML content for source view
        fetch('../' + data.htmlFile)
            .then(response => response.text())
            .then(html => {
                this.htmlSource.textContent = html;
            })
            .catch(error => {
                console.error('Error fetching HTML content:', error);
            });
    }
}

// Initialize the converter when the DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Check for machine ID
    const machineId = sessionStorage.getItem('machineId');
    if (!machineId) {
        window.location.href = '../index.html';
        return;
    }
    document.getElementById('machineIdDisplay').textContent = `Machine ID: ${machineId}`;
    
    // Initialize the PDF converter
    const converter = new PdfConverter();
});
