<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaAIService
{
    protected $baseUrl;
    protected $model;
    protected $timeout;
    
    public function __construct()
    {
        $this->baseUrl = config('ai-verification.ollama_base_url', 'http://localhost:11434');
        $this->model = config('ai-verification.ollama_model', 'llama3.2:3b'); // Text model (fast!)
        $this->timeout = config('ai-verification.ollama_timeout', 60);
    }
    
    /**
     * Validate document using OCR text + Text AI (no vision needed!)
     * 
     * Flow: OCR extracts text → AI analyzes text
     */
    public function validateDocument($imagePath, $documentType, $userData, $ocrText = '')
    {
        try {
            // If no OCR text, we can't analyze
            if (empty(trim($ocrText))) {
                Log::warning("No OCR text provided for AI analysis");
                return $this->getFallbackResponse("No text extracted from document");
            }
            
            // Build prompt for text analysis
            $prompt = $this->buildTextAnalysisPrompt($documentType, $userData, $ocrText);
            
            Log::info("Sending OCR text to AI for analysis. Text length: " . strlen($ocrText));
            
            // Call Ollama API with TEXT only (no images!)
            $response = Http::timeout($this->timeout)
                ->post($this->baseUrl . '/api/generate', [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'stream' => false,
                    'format' => 'json'
                ]);
            
            if ($response->successful()) {
                $responseData = $response->json();
                $result = json_decode($responseData['response'] ?? '{}', true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::warning("AI response was not valid JSON: " . ($responseData['response'] ?? 'empty'));
                    return $this->getFallbackResponse("Invalid AI response format");
                }
                
                Log::info("AI analysis completed successfully");
                return $this->parseAIResponse($result);
            }
            
            throw new \Exception("Ollama API request failed: " . $response->status());
            
        } catch (\Exception $e) {
            Log::error('Ollama AI Error: ' . $e->getMessage());
            return $this->getFallbackResponse($e->getMessage());
        }
    }
    
    /**
     * Build prompt for TEXT-BASED analysis (using OCR output)
     */
    private function buildTextAnalysisPrompt($documentType, $userData, $ocrText)
    {
        $userName = trim(($userData->first_name ?? '') . ' ' . ($userData->last_name ?? ''));
        
        $prompt = "You are a document verification assistant. Analyze the following OCR-extracted text from a {$documentType} document.\n\n";
        
        $prompt .= "=== EXPECTED USER INFORMATION ===\n";
        $prompt .= "Name: {$userName}\n";
        $prompt .= "Email: {$userData->email}\n\n";
        
        $prompt .= "=== OCR EXTRACTED TEXT FROM DOCUMENT ===\n";
        $prompt .= "{$ocrText}\n\n";
        
        $prompt .= "=== YOUR TASK ===\n";
        $prompt .= "Analyze the extracted text and provide a JSON response with these exact fields:\n\n";
        
        $prompt .= "{\n";
        $prompt .= "  \"document_type_correct\": true or false (does the text indicate this is a {$documentType}?),\n";
        $prompt .= "  \"text_quality\": 0-100 (how readable/complete is the extracted text?),\n";
        $prompt .= "  \"name_on_document\": \"extracted full name from the document text, or null if not found\",\n";
        $prompt .= "  \"name_matches\": true or false (does the name on document match '{$userName}'? Use fuzzy matching),\n";
        $prompt .= "  \"id_number\": \"extracted ID/license/reference number, or null\",\n";
        $prompt .= "  \"issue_date\": \"YYYY-MM-DD format or null\",\n";
        $prompt .= "  \"expiry_date\": \"YYYY-MM-DD format or null\",\n";
        $prompt .= "  \"is_expired\": true or false (based on expiry_date vs today's date),\n";
        $prompt .= "  \"red_flags\": [\"array of any suspicious issues found\"],\n";
        $prompt .= "  \"confidence_score\": 0-100 (overall confidence in document validity),\n";
        $prompt .= "  \"recommendation\": \"APPROVE\" or \"REVIEW\" or \"REJECT\",\n";
        $prompt .= "  \"notes\": \"brief explanation of your analysis\"\n";
        $prompt .= "}\n\n";
        
        $prompt .= "Guidelines:\n";
        $prompt .= "- APPROVE: High confidence (85+), name matches, not expired, no red flags\n";
        $prompt .= "- REVIEW: Medium confidence (50-84) or minor issues\n";
        $prompt .= "- REJECT: Low confidence (<50), name mismatch, expired, or major red flags\n";
        $prompt .= "- For Philippine documents, check for LTO, DFA, PSA, SSS, etc.\n";
        $prompt .= "- Today's date is " . date('Y-m-d') . "\n\n";
        
        $prompt .= "Respond ONLY with the JSON object, no other text.";
        
        return $prompt;
    }
    
    /**
     * Parse AI response into standardized format
     */
    private function parseAIResponse($response)
    {
        if (!is_array($response)) {
            return $this->getFallbackResponse("Invalid response type");
        }
        
        // Ensure all required fields exist with defaults
        $defaults = [
            'confidence_score' => 0,
            'text_quality' => 0,
            'name_on_document' => null,
            'id_number' => null,
            'issue_date' => null,
            'expiry_date' => null,
            'name_matches' => false,
            'is_expired' => false,
            'red_flags' => [],
            'recommendation' => 'REVIEW',
            'notes' => ''
        ];
        
        $response = array_merge($defaults, $response);
        
        return [
            'confidence' => (int)($response['confidence_score'] ?? 0),
            'extracted_data' => [
                'name' => $response['name_on_document'] ?? null,
                'reference_number' => $response['id_number'] ?? null,
                'issue_date' => $response['issue_date'] ?? null,
                'expiry_date' => $response['expiry_date'] ?? null,
            ],
            'flags' => (array)($response['red_flags'] ?? []),
            'quality_score' => (int)($response['text_quality'] ?? 0),
            'name_matches' => (bool)($response['name_matches'] ?? false),
            'is_expired' => (bool)($response['is_expired'] ?? false),
            'recommendation' => strtoupper($response['recommendation'] ?? 'REVIEW'),
            'notes' => $response['notes'] ?? 'AI analysis completed'
        ];
    }
    
    /**
     * Get fallback response when AI fails
     */
    private function getFallbackResponse($errorMessage = '')
    {
        return [
            'confidence' => 0,
            'extracted_data' => [
                'name' => null,
                'reference_number' => null,
                'issue_date' => null,
                'expiry_date' => null,
            ],
            'flags' => ['AI processing failed - manual review required'],
            'quality_score' => 0,
            'name_matches' => false,
            'is_expired' => false,
            'recommendation' => 'REVIEW',
            'notes' => 'AI analysis failed: ' . $errorMessage
        ];
    }
    
    /**
     * Check if Ollama service is available
     */
    public function isAvailable()
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/api/tags');
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get available models
     */
    public function getAvailableModels()
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/api/tags');
            
            if ($response->successful()) {
                return $response->json()['models'] ?? [];
            }
            
            return [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
