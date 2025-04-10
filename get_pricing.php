<?php
header('Content-Type: application/json');

// Define pricing with markup
$pricing = [
    '3.5' => 24, // PHP 24 per 100 tokens
    '4.0' => 36, // PHP 36 per 100 tokens
    '4.5' => 48  // PHP 48 per 100 tokens
];

// Return pricing data
echo json_encode([
    'success' => true,
    'pricing' => $pricing
]);
