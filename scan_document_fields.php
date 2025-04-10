<?php
// Set headers to prevent caching
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Content-Type: application/json');

// Error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Get the template name from POST request
$templateName = isset($_POST['templateName']) ? $_POST['templateName'] : '';

// Validate inputs
if (empty($templateName)) {
    echo json_encode(['success' => false, 'message' => 'Template name is required']);
    exit;
}

// Define the path to the template
$templatePath = __DIR__ . '/templates/' . $templateName . '.html';

// Check if the template file exists
if (!file_exists($templatePath)) {
    echo json_encode(['success' => false, 'message' => 'Template not found']);
    exit;
}

// Load the template content
$templateContent = file_get_contents($templatePath);

// Function to scan for fillable fields in HTML content
function scanForFillableFields($html) {
    $fields = [];
    
    // Create a DOMDocument to parse the HTML
    $dom = new DOMDocument();
    
    // Suppress warnings for HTML5 tags
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    // Find elements with data-field attribute
    $xpath = new DOMXPath($dom);
    $fieldElements = $xpath->query('//*[@data-field]');
    
    foreach ($fieldElements as $element) {
        $fieldName = $element->getAttribute('data-field');
        $fieldType = $element->getAttribute('data-field-type') ?: determineFieldType($element);
        $fieldLabel = $element->getAttribute('data-field-label') ?: formatFieldLabel($fieldName);
        $fieldRequired = $element->getAttribute('data-field-required') !== 'false';
        
        $fields[] = [
            'id' => $fieldName,
            'label' => $fieldLabel,
            'type' => $fieldType,
            'required' => $fieldRequired
        ];
    }
    
    // If no data-field attributes found, look for common patterns in legal documents
    if (empty($fields)) {
        // Look for underscores which often indicate fillable fields
        preg_match_all('/_{3,}/', $html, $matches, PREG_OFFSET_CAPTURE);
        
        if (!empty($matches[0])) {
            foreach ($matches[0] as $index => $match) {
                // Get surrounding text to determine field name
                $start = max(0, $match[1] - 50);
                $length = min(100, strlen($html) - $start);
                $context = substr($html, $start, $length);
                
                // Try to extract a field name from context
                $fieldName = extractFieldNameFromContext($context);
                
                $fields[] = [
                    'id' => $fieldName ?: 'field_' . ($index + 1),
                    'label' => formatFieldLabel($fieldName ?: 'Field ' . ($index + 1)),
                    'type' => 'text',
                    'required' => true
                ];
            }
        }
        
        // Look for common form field patterns in legal documents
        $patterns = [
            'name' => '/\b(?:name|full\s*name)\b/i',
            'address' => '/\b(?:address|residence)\b/i',
            'date' => '/\b(?:date|day|month|year)\b/i',
            'signature' => '/\b(?:signature|sign)\b/i',
            'description' => '/\b(?:describe|description|details)\b/i'
        ];
        
        foreach ($patterns as $fieldName => $pattern) {
            if (preg_match($pattern, $html)) {
                $type = ($fieldName === 'description') ? 'textarea' : 
                       (($fieldName === 'date') ? 'date' : 'text');
                
                // Check if this field is already added
                $exists = false;
                foreach ($fields as $field) {
                    if ($field['id'] === $fieldName) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $fields[] = [
                        'id' => $fieldName,
                        'label' => formatFieldLabel($fieldName),
                        'type' => $type,
                        'required' => true
                    ];
                }
            }
        }
    }
    
    return $fields;
}

// Helper function to determine field type based on element
function determineFieldType($element) {
    $tagName = $element->tagName;
    
    if ($tagName === 'textarea') {
        return 'textarea';
    }
    
    if ($tagName === 'select') {
        return 'select';
    }
    
    if ($tagName === 'input') {
        return $element->getAttribute('type') ?: 'text';
    }
    
    // For spans, divs, etc. with underscores or empty content
    $content = trim($element->textContent);
    if (empty($content) || strpos($content, '_____') !== false) {
        // Check if it looks like a date field
        if (stripos($element->textContent, 'date') !== false) {
            return 'date';
        }
        
        // Check if it looks like a large text area
        $style = $element->getAttribute('style');
        if (strpos($style, 'width') !== false && strpos($style, 'height') !== false) {
            return 'textarea';
        }
    }
    
    return 'text';
}

// Helper function to format field name into label
function formatFieldLabel($fieldName) {
    // Replace underscores and camelCase with spaces
    $label = preg_replace('/([A-Z])/', ' $1', $fieldName);
    $label = str_replace('_', ' ', $label);
    
    // Capitalize first letter
    $label = ucfirst(trim($label));
    
    return $label;
}

// Helper function to extract field name from context
function extractFieldNameFromContext($context) {
    // Common label patterns in legal documents
    $patterns = [
        '/\b(name|full\s*name)\s*:/i',
        '/\b(address|residence)\s*:/i',
        '/\b(date)\s*:/i',
        '/\b(signature)\s*:/i',
        '/\b(description|details)\s*:/i'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $context, $matches)) {
            return strtolower($matches[1]);
        }
    }
    
    return '';
}

// Scan the template for fillable fields
$fields = scanForFillableFields($templateContent);

// Return the detected fields
echo json_encode([
    'success' => true,
    'message' => count($fields) . ' fillable fields detected',
    'fields' => $fields
]);
