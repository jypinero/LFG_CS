<?php

namespace App\Services;

use App\Models\UserDocument;
use Illuminate\Support\Facades\Log;

class FreeAIDocumentService
{
    protected $ocrService;
    protected $aiService;
    
    public function __construct(FreeOCRService $ocr, OllamaAIService $ai)
    {
        $this->ocrService = $ocr;
        $this->aiService = $ai;
    }
    
    /**
     * Process document with OCR and AI
     */
    public function processDocument(UserDocument $document)
    {
        try {
            Log::info("Starting AI processing for document ID: {$document->id}");
            
            // Step 1: OCR with Tesseract (fast, free text extraction)
            $ocrResult = $this->ocrService->extractText($document->file_path);
            $structuredData = $this->ocrService->extractStructuredData(
                $document->file_path,
                $document->document_type
            );
            
            Log::info("OCR completed. Confidence: " . ($ocrResult['confidence'] ?? 0));
            
            // Step 2: AI validation with Ollama (slower, more accurate)
            $aiResult = $this->aiService->validateDocument(
                $document->file_path,
                $document->document_type === 'other' ? $document->custom_type : $document->document_type,
                $document->user,
                $ocrResult['text'] ?? ''
            );
            
            Log::info("AI analysis completed. Confidence: " . ($aiResult['confidence'] ?? 0));
            
            // Step 3: Combine results
            $finalConfidence = $this->calculateFinalConfidence($ocrResult, $aiResult);
            
            // Merge extracted data from both OCR and AI
            $extractedData = array_merge($structuredData, $aiResult['extracted_data']);
            
            // Get final recommendation
            $recommendation = $this->getRecommendation(
                $finalConfidence,
                $aiResult['flags'],
                $aiResult['name_matches'],
                $aiResult['name_match_confidence'] ?? 0
            );
            
            return [
                'success' => true,
                'confidence' => $finalConfidence,
                'ocr_text' => $ocrResult['text'] ?? '',
                'ocr_confidence' => $ocrResult['confidence'] ?? 0,
                'ai_confidence' => $aiResult['confidence'] ?? 0,
                'extracted_data' => $extractedData,
                'flags' => $aiResult['flags'] ?? [],
                'quality_score' => $aiResult['quality_score'] ?? 0,
                'name_matches' => $aiResult['name_matches'] ?? false,
                'name_match_confidence' => $aiResult['name_match_confidence'] ?? 0,
                'is_expired' => $aiResult['is_expired'] ?? false,
                'recommendation' => $recommendation,
                'notes' => $aiResult['notes'] ?? ''
            ];
            
        } catch (\Exception $e) {
            Log::error('Free AI Document Service Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'confidence' => 0,
                'ocr_text' => '',
                'extracted_data' => [],
                'flags' => ['Processing error: ' . $e->getMessage()],
                'quality_score' => 0,
                'name_matches' => false,
                'name_match_confidence' => 0,
                'is_expired' => false,
                'recommendation' => 'MANUAL_REVIEW',
                'notes' => 'Error during processing - manual review required'
            ];
        }
    }
    
    /**
     * Calculate final confidence score (weighted average)
     */
    private function calculateFinalConfidence($ocrResult, $aiResult)
    {
        $ocrConfidence = $ocrResult['confidence'] ?? 0;
        $aiConfidence = $aiResult['confidence'] ?? 0;
        
        // Weighted average: OCR 30%, AI 70%
        // AI is more reliable for validation
        $finalConfidence = ($ocrConfidence * 0.3) + ($aiConfidence * 0.7);
        
        return round($finalConfidence, 2);
    }
    
    /**
     * Get recommendation based on confidence, flags, and name matching
     */
    private function getRecommendation($confidence, $flags, $nameMatches, $nameMatchConfidence = 0)
    {
        $autoApproveThreshold = config('ai-verification.auto_approve_threshold', 85);
        $quickReviewThreshold = config('ai-verification.quick_review_threshold', 70);
        
        // Critical flags that require manual review
        $criticalFlags = ['expired', 'tampered', 'fake', 'fraud', 'mismatch'];
        $hasCriticalFlag = false;
        
        foreach ($flags as $flag) {
            foreach ($criticalFlags as $critical) {
                if (stripos($flag, $critical) !== false) {
                    $hasCriticalFlag = true;
                    break 2;
                }
            }
        }
        
        // Decision logic
        if ($hasCriticalFlag) {
            return 'MANUAL_REVIEW';
        }
        
        // Require high name match confidence for auto-approval
        $nameMatchThreshold = 80; // Require 80%+ confidence in name match
        if ($confidence >= $autoApproveThreshold && empty($flags) && $nameMatches && $nameMatchConfidence >= $nameMatchThreshold) {
            return 'AUTO_APPROVE';
        }
        
        // If name doesn't match or low confidence, require review
        if (!$nameMatches || $nameMatchConfidence < 50) {
            return 'MANUAL_REVIEW';
        }
        
        if ($confidence >= $quickReviewThreshold && $nameMatchConfidence >= 50) {
            return 'QUICK_REVIEW';
        }
        
        return 'MANUAL_REVIEW';
    }
    
    /**
     * Auto-verify document if confidence is high enough
     */
    public function shouldAutoVerify($result)
    {
        return $result['recommendation'] === 'AUTO_APPROVE';
    }
    
    /**
     * Check if Ollama is available before processing
     */
    public function isAIAvailable()
    {
        return $this->aiService->isAvailable();
    }
}





