<?php

return [
    
    /*
    |--------------------------------------------------------------------------
    | AI Verification Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for AI-powered document verification using free services:
    | - Tesseract OCR for text extraction
    | - Ollama (Llama 3.2 Vision) for document validation
    |
    */

    // Enable/Disable AI verification
    'enabled' => env('AI_ENABLED', true),
    
    // AI Provider (ollama or google_vision)
    'provider' => env('AI_PROVIDER', 'ollama'),
    
    /*
    |--------------------------------------------------------------------------
    | Ollama Configuration (Free, Self-Hosted)
    |--------------------------------------------------------------------------
    */
    
    'ollama_base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
    'ollama_model' => env('OLLAMA_MODEL', 'llama3.2-vision'),
    'ollama_timeout' => env('OLLAMA_TIMEOUT', 60), // seconds
    
    /*
    |--------------------------------------------------------------------------
    | Tesseract OCR Configuration (Free)
    |--------------------------------------------------------------------------
    */
    
    // Path to tesseract executable (auto-detect if null)
    'tesseract_path' => env('TESSERACT_PATH', null),
    
    /*
    |--------------------------------------------------------------------------
    | Auto-Verification Thresholds
    |--------------------------------------------------------------------------
    |
    | Confidence scores determine automatic approval:
    | - auto_approve_threshold: Auto-verify if confidence >= this value (and no red flags)
    | - quick_review_threshold: Flag for quick admin review if confidence >= this value
    | - Below quick_review: Manual review required
    |
    */
    
    'auto_approve_threshold' => env('AI_AUTO_APPROVE_THRESHOLD', 85),
    'quick_review_threshold' => env('AI_QUICK_REVIEW_THRESHOLD', 70),
    
    /*
    |--------------------------------------------------------------------------
    | Processing Options
    |--------------------------------------------------------------------------
    */
    
    // Process documents immediately after upload (recommended)
    'process_on_upload' => env('AI_PROCESS_ON_UPLOAD', true),
    
    // Queue connection for async processing
    'queue_connection' => env('QUEUE_CONNECTION', 'database'),
    
    // Queue name
    'queue_name' => 'ai-processing',
    
    /*
    |--------------------------------------------------------------------------
    | Document Type Specific Settings
    |--------------------------------------------------------------------------
    */
    
    'document_settings' => [
        'government_id' => [
            'required_fields' => ['name', 'id_number', 'expiry_date'],
            'auto_approve_threshold' => 90, // Higher threshold for IDs
        ],
        'medical_certificate' => [
            'required_fields' => ['patient_name', 'date', 'doctor_name'],
            'auto_approve_threshold' => 80,
        ],
        'waiver_form' => [
            'required_fields' => ['participant_name', 'date_signed'],
            'auto_approve_threshold' => 75,
        ],
        'insurance_proof' => [
            'required_fields' => ['policy_holder', 'policy_number'],
            'auto_approve_threshold' => 85,
        ],
        'parental_consent' => [
            'required_fields' => ['child_name', 'parent_name'],
            'auto_approve_threshold' => 80,
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Critical Flags
    |--------------------------------------------------------------------------
    |
    | These keywords in AI flags will prevent auto-approval
    |
    */
    
    'critical_flags' => [
        'expired',
        'tampered',
        'fake',
        'fraud',
        'forged',
        'mismatch',
        'suspicious',
        'invalid',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    
    'log_all_processing' => env('AI_LOG_PROCESSING', true),
    'log_channel' => env('AI_LOG_CHANNEL', 'stack'),
    
];


















