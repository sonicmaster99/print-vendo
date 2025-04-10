/**
 * DocumentScanner - A class for scanning document templates for fillable fields
 * and dynamically generating form inputs based on detected fields
 */
class DocumentScanner {
    constructor(options = {}) {
        // Default options
        this.options = {
            templatePath: '../templates/',
            fieldSelector: '[data-field]',
            dynamicFieldsContainer: '#dynamicFields',
            previewContainer: '#documentPreview',
            previewSection: '#previewSection',
            ...options
        };

        // Initialize properties
        this.template = null;
        this.fields = [];
        this.templateContent = '';
        this.machineId = sessionStorage.getItem('machineId');
    }

    /**
     * Load a document template and scan for fillable fields
     * @param {string} templateName - The name of the template to load
     * @returns {Promise} - Resolves when template is loaded and scanned
     */
    async loadTemplate(templateName) {
        try {
            // Clear previous fields
            this.fields = [];
            
            // Show loading indicator
            document.getElementById('loadingSpinner').style.display = 'block';
            
            // Load the template
            const templateUrl = `${this.options.templatePath}${templateName}.html`;
            const response = await fetch(templateUrl);
            
            if (!response.ok) {
                throw new Error(`Failed to load template: ${response.statusText}`);
            }
            
            this.templateContent = await response.text();
            
            // Create a temporary container to parse the HTML
            const tempContainer = document.createElement('div');
            tempContainer.innerHTML = this.templateContent;
            
            // Scan for fields in the client-side
            this.scanFieldsClientSide(tempContainer);
            
            // If no fields found client-side, try server-side scanning
            if (this.fields.length === 0) {
                await this.scanFieldsServerSide(templateName);
            }
            
            // Generate form inputs based on detected fields
            this.generateFormInputs();
            
            // Hide loading indicator
            document.getElementById('loadingSpinner').style.display = 'none';
            
            return this.fields;
        } catch (error) {
            console.error('Error loading template:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: `Failed to load template: ${error.message}`
            });
            
            // Hide loading indicator
            document.getElementById('loadingSpinner').style.display = 'none';
            
            throw error;
        }
    }
    
    /**
     * Scan for fillable fields in the template (client-side)
     * @param {HTMLElement} container - The container with the template HTML
     */
    scanFieldsClientSide(container) {
        // Find all elements with data-field attribute
        const fieldElements = container.querySelectorAll(this.options.fieldSelector);
        
        fieldElements.forEach(element => {
            const fieldName = element.getAttribute('data-field');
            const fieldType = element.getAttribute('data-field-type') || this.determineFieldType(element);
            const fieldLabel = element.getAttribute('data-field-label') || this.formatFieldLabel(fieldName);
            const fieldRequired = element.getAttribute('data-field-required') !== 'false';
            
            this.fields.push({
                id: fieldName,
                label: fieldLabel,
                type: fieldType,
                required: fieldRequired
            });
        });
    }
    
    /**
     * Scan for fillable fields using server-side processing
     * @param {string} templateName - The name of the template to scan
     * @returns {Promise} - Resolves when server-side scanning is complete
     */
    async scanFieldsServerSide(templateName) {
        try {
            const formData = new FormData();
            formData.append('templateName', templateName);
            
            const response = await fetch('../scan_document_fields.php', {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                throw new Error(`Server-side scanning failed: ${response.statusText}`);
            }
            
            const result = await response.json();
            
            if (result.success && result.fields) {
                this.fields = result.fields;
            } else {
                throw new Error(result.message || 'No fields detected in template');
            }
        } catch (error) {
            console.error('Server-side scanning error:', error);
            throw error;
        }
    }
    
    /**
     * Determine the appropriate field type based on the element
     * @param {HTMLElement} element - The field element
     * @returns {string} - The field type (text, textarea, date, etc.)
     */
    determineFieldType(element) {
        const tagName = element.tagName.toLowerCase();
        
        if (tagName === 'textarea') {
            return 'textarea';
        }
        
        if (tagName === 'select') {
            return 'select';
        }
        
        if (tagName === 'input') {
            return element.type || 'text';
        }
        
        // For spans, divs, etc. with underscores or empty content
        const content = element.textContent.trim();
        if (content === '' || content.includes('_____')) {
            // Check content length to determine if it should be a textarea
            if (element.clientWidth > 300 || element.clientHeight > 50) {
                return 'textarea';
            }
            
            // Check if it looks like a date field
            if (element.textContent.toLowerCase().includes('date')) {
                return 'date';
            }
        }
        
        return 'text';
    }
    
    /**
     * Format a field name into a human-readable label
     * @param {string} fieldName - The field name
     * @returns {string} - The formatted label
     */
    formatFieldLabel(fieldName) {
        return fieldName
            .replace(/([A-Z])/g, ' $1') // Add space before capital letters
            .replace(/^./, str => str.toUpperCase()) // Capitalize first letter
            .replace(/_/g, ' ') // Replace underscores with spaces
            .trim();
    }
    
    /**
     * Generate form inputs based on detected fields
     */
    generateFormInputs() {
        const container = document.querySelector(this.options.dynamicFieldsContainer);
        
        // Clear previous fields
        container.innerHTML = '';
        
        if (this.fields.length === 0) {
            container.innerHTML = '<div class="alert alert-warning">No fillable fields detected in this document.</div>';
            return;
        }
        
        // Generate form inputs for each field
        this.fields.forEach(field => {
            const div = document.createElement('div');
            div.className = 'mb-3';
            
            const label = document.createElement('label');
            label.className = 'form-label';
            label.htmlFor = field.id;
            label.textContent = field.label;
            
            let input;
            
            switch (field.type) {
                case 'textarea':
                    input = document.createElement('textarea');
                    input.rows = 3;
                    break;
                    
                case 'select':
                    input = document.createElement('select');
                    
                    // Add options if available
                    if (field.options) {
                        field.options.forEach(option => {
                            const optElement = document.createElement('option');
                            optElement.value = option.value;
                            optElement.textContent = option.label;
                            input.appendChild(optElement);
                        });
                    }
                    break;
                    
                case 'checkbox':
                    div.className = 'mb-3 form-check';
                    input = document.createElement('input');
                    input.type = 'checkbox';
                    input.className = 'form-check-input';
                    label.className = 'form-check-label';
                    break;
                    
                case 'radio':
                    // For radio buttons, we'd need to handle groups
                    div.className = 'mb-3';
                    const radioGroup = document.createElement('div');
                    
                    if (field.options) {
                        field.options.forEach(option => {
                            const radioDiv = document.createElement('div');
                            radioDiv.className = 'form-check';
                            
                            const radioInput = document.createElement('input');
                            radioInput.type = 'radio';
                            radioInput.className = 'form-check-input';
                            radioInput.id = `${field.id}_${option.value}`;
                            radioInput.name = field.id;
                            radioInput.value = option.value;
                            
                            const radioLabel = document.createElement('label');
                            radioLabel.className = 'form-check-label';
                            radioLabel.htmlFor = `${field.id}_${option.value}`;
                            radioLabel.textContent = option.label;
                            
                            radioDiv.appendChild(radioInput);
                            radioDiv.appendChild(radioLabel);
                            radioGroup.appendChild(radioDiv);
                        });
                    }
                    
                    div.appendChild(label);
                    div.appendChild(radioGroup);
                    container.appendChild(div);
                    return;
                    
                default:
                    input = document.createElement('input');
                    input.type = field.type;
            }
            
            // Set common attributes
            input.className = field.type === 'checkbox' ? 'form-check-input' : 'form-control';
            input.id = field.id;
            input.name = field.id;
            input.required = field.required;
            
            if (field.type !== 'checkbox') {
                div.appendChild(label);
                div.appendChild(input);
            } else {
                div.appendChild(input);
                div.appendChild(label);
            }
            
            container.appendChild(div);
        });
    }
    
    /**
     * Generate a preview of the document with user input
     * @param {Object} values - The form values
     * @returns {string} - The HTML content for the preview
     */
    generatePreview(values) {
        let previewContent = this.templateContent;
        
        // Replace field placeholders with user input
        this.fields.forEach(field => {
            const value = values[field.id] || '';
            const regex = new RegExp(`data-field="${field.id}"[^>]*>(.*?)<`, 'g');
            
            previewContent = previewContent.replace(regex, `data-field="${field.id}">${value}<`);
        });
        
        // Show the preview section
        const previewSection = document.querySelector(this.options.previewSection);
        if (previewSection) {
            previewSection.classList.remove('d-none');
        }
        
        // Update the preview container
        const previewContainer = document.querySelector(this.options.previewContainer);
        if (previewContainer) {
            previewContainer.innerHTML = previewContent;
        }
        
        return previewContent;
    }
    
    /**
     * Get all form values
     * @returns {Object} - The form values
     */
    getFormValues() {
        const values = {};
        
        this.fields.forEach(field => {
            const element = document.getElementById(field.id);
            
            if (element) {
                if (field.type === 'checkbox') {
                    values[field.id] = element.checked;
                } else if (field.type === 'radio') {
                    const checkedRadio = document.querySelector(`input[name="${field.id}"]:checked`);
                    values[field.id] = checkedRadio ? checkedRadio.value : '';
                } else {
                    values[field.id] = element.value;
                }
            }
        });
        
        return values;
    }
}
