<?php

namespace App\Services;

use thiagoalessio\TesseractOCR\TesseractOCR;
use Illuminate\Support\Facades\Log;

class FreeOCRService
{
    /**
     * Extract text from image using Tesseract OCR
     */
    public function extractText($imagePath)
    {
        try {
            $fullPath = storage_path('app/public/' . $imagePath);
            
            if (!file_exists($fullPath)) {
                throw new \Exception("File not found: {$fullPath}");
            }
            
            $ocr = new TesseractOCR($fullPath);
            
            // Set Tesseract path if configured
            if (config('ai-verification.tesseract_path')) {
                $ocr->executable(config('ai-verification.tesseract_path'));
            }
            
            // Run OCR
            $text = $ocr->run();
            $trimmedText = trim($text);
            
            // Calculate confidence based on extracted text quality
            $confidence = $this->calculateConfidence($trimmedText);
            
            return [
                'success' => true,
                'text' => $trimmedText,
                'confidence' => $confidence,
                'language' => 'eng'
            ];
            
        } catch (\Exception $e) {
            Log::error('OCR Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'text' => '',
                'confidence' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Calculate OCR confidence score from text
     * Note: Since we already ran OCR to get text, we estimate confidence based on text quality
     */
    private function calculateConfidence($text)
    {
        try {
            if (empty($text)) {
                return 0;
            }
            
            // Simple heuristic: check text quality indicators
            $length = strlen($text);
            $words = str_word_count($text);
            
            // If we have reasonable text, estimate confidence
            if ($words > 5 && $length > 20) {
                // Check for readable characters ratio
                $alphanumeric = preg_match_all('/[a-zA-Z0-9]/', $text);
                $ratio = $alphanumeric / max($length, 1);
                
                // Higher ratio = more readable text = higher confidence
                return min(round($ratio * 100, 2), 95);
            }
            
            return $words > 0 ? 50 : 0;
            
        } catch (\Exception $e) {
            return 50; // Default moderate confidence
        }
    }
    
    /**
     * Extract structured data based on document type
     */
    public function extractStructuredData($imagePath, $documentType)
    {
        $result = $this->extractText($imagePath);
        
        if (!$result['success']) {
            return [];
        }
        
        $text = $result['text'];
        
        // Pattern matching based on document type
        $patterns = $this->getPatterns($documentType);
        
        $data = [];
        foreach ($patterns as $field => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $data[$field] = trim($matches[1]);
            }
        }
        
        return $data;
    }
    
    /**
     * Get regex patterns for different document types
     */
    private function getPatterns($documentType)
    {
        $patterns = [
            'government_id' => [
                'name' => '/(?:NAME|PANGALAN)[:\s]+([A-Z\s]+)/i',
                'id_number' => '/(?:ID|NO|NUMBER|LICENSE)[:\s#]+([A-Z0-9\-]+)/i',
                'expiry_date' => '/(?:EXPIRY|EXPIRES|VALID UNTIL)[:\s]+(\d{2}[-\/\s]\w+[-\/\s]\d{4}|\d{4}[-\/]\d{2}[-\/]\d{2})/i',
                'issue_date' => '/(?:ISSUED|DATE OF ISSUE)[:\s]+(\d{2}[-\/\s]\w+[-\/\s]\d{4}|\d{4}[-\/]\d{2}[-\/]\d{2})/i',
            ],
            'medical_certificate' => [
                'patient_name' => '/(?:PATIENT|NAME)[:\s]+([A-Z\s]+)/i',
                'doctor_name' => '/(?:DR|DOCTOR|PHYSICIAN)[.:\s]+([A-Z\s]+)/i',
                'date' => '/(?:DATE)[:\s]+(\d{2}[-\/\s]\w+[-\/\s]\d{4}|\d{4}[-\/]\d{2}[-\/]\d{2})/i',
                'clinic' => '/(?:CLINIC|HOSPITAL|FACILITY)[:\s]+([A-Z0-9\s,]+)/i',
            ],
            'waiver_form' => [
                'participant_name' => '/(?:NAME|PARTICIPANT)[:\s]+([A-Z\s]+)/i',
                'date_signed' => '/(?:DATE|SIGNED)[:\s]+(\d{2}[-\/\s]\w+[-\/\s]\d{4}|\d{4}[-\/]\d{2}[-\/]\d{2})/i',
                'signature' => '/(?:SIGNATURE|SIGNED BY)[:\s]+([A-Z\s]+)/i',
            ],
            'insurance_proof' => [
                'policy_holder' => '/(?:POLICY HOLDER|NAME)[:\s]+([A-Z\s]+)/i',
                'policy_number' => '/(?:POLICY|NUMBER)[:\s#]+([A-Z0-9\-]+)/i',
                'expiry_date' => '/(?:EXPIRY|EXPIRES|VALID UNTIL)[:\s]+(\d{2}[-\/\s]\w+[-\/\s]\d{4}|\d{4}[-\/]\d{2}[-\/]\d{2})/i',
            ],
            'parental_consent' => [
                'child_name' => '/(?:CHILD|MINOR|NAME)[:\s]+([A-Z\s]+)/i',
                'parent_name' => '/(?:PARENT|GUARDIAN)[:\s]+([A-Z\s]+)/i',
                'date_signed' => '/(?:DATE|SIGNED)[:\s]+(\d{2}[-\/\s]\w+[-\/\s]\d{4}|\d{4}[-\/]\d{2}[-\/]\d{2})/i',
            ],
        ];
        
        return $patterns[$documentType] ?? [];
    }
    
    /**
     * Check if OCR quality is acceptable
     */
    public function isQualityAcceptable($imagePath)
    {
        $result = $this->extractText($imagePath);
        
        return $result['success'] && $result['confidence'] >= 60;
    }
}

