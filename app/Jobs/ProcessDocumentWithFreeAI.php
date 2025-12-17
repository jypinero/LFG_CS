<?php

namespace App\Jobs;

use App\Models\UserDocument;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Services\FreeAIDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDocumentWithFreeAI implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $documentId;
    
    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;
    
    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions = 2;
    
    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 180; // 3 minutes for AI processing (model loading can take time)

    /**
     * Create a new job instance.
     */
    public function __construct($documentId)
    {
        $this->documentId = $documentId;
    }

    /**
     * Execute the job.
     */
    public function handle(FreeAIDocumentService $aiService)
    {
        $document = UserDocument::find($this->documentId);
        
        if (!$document) {
            Log::warning("Document {$this->documentId} not found for AI processing");
            return;
        }

        Log::info("Processing document {$document->id} with Free AI");

        try {
            // Process document with OCR + AI
            $result = $aiService->processDocument($document);
            
            if (!$result['success']) {
                throw new \Exception($result['notes'] ?? 'Processing failed');
            }
            
            // Prepare enhanced extracted data with name matching details
            $user = $document->user;
            $expectedName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
            $extractedData = array_merge($result['extracted_data'] ?? [], [
                'name_on_document' => $result['extracted_data']['name'] ?? null,
                'expected_name' => $expectedName,
                'name_match_status' => $result['name_matches'] ? 'MATCH' : 'MISMATCH',
                'name_match_confidence' => $result['name_match_confidence'] ?? 0,
            ]);
            
            // Update document with AI results
            $document->update([
                'ai_processed' => true,
                'ai_confidence_score' => $result['confidence'],
                'ai_extracted_data' => $extractedData,
                'ai_validation_notes' => $result['notes'],
                'ai_flags' => $result['flags'],
                'ai_quality_score' => $result['quality_score'],
                'ai_name_matches' => $result['name_matches'],
                'ai_processed_at' => now(),
                'ai_ocr_text' => $result['ocr_text'] ?? null
            ]);
            
            Log::info("AI processing completed. Confidence: {$result['confidence']}%, Recommendation: {$result['recommendation']}");
            
            // Auto-verify if high confidence
            if ($aiService->shouldAutoVerify($result)) {
                $this->autoVerifyDocument($document, $result);
            } else {
                // Notify admin for review
                $this->notifyAdminForReview($document, $result);
            }
            
        } catch (\Exception $e) {
            Log::error('AI processing failed for document ' . $document->id . ': ' . $e->getMessage());
            
            // Mark as failed AI processing
            $document->update([
                'ai_processed' => false,
                'ai_validation_notes' => 'AI processing failed - manual review required',
                'ai_flags' => ['Processing error: ' . $e->getMessage()]
            ]);
            
            // Still notify user that manual review is needed
            $this->notifyManualReviewNeeded($document);
        }
    }
    
    /**
     * Auto-verify document with AI confidence
     */
    protected function autoVerifyDocument($document, $result)
    {
        $document->update([
            'verification_status' => 'verified',
            'verified_by' => 1, // System/AI user ID
            'verified_at' => now(),
            'verification_notes' => "Auto-verified by AI\nConfidence: {$result['confidence']}%\nQuality: {$result['quality_score']}%",
            'ai_auto_verified' => true
        ]);
        
        Log::info("Document {$document->id} auto-verified by AI");
        
        // Send success notification to user
        $this->sendVerificationNotification($document, 'verified');
    }
    
    /**
     * Notify admin that document needs review
     */
    protected function notifyAdminForReview($document, $result)
    {
        Log::info("Document {$document->id} requires admin review. Confidence: {$result['confidence']}%");
        
        // Send notification to user that document is under review
        $notification = Notification::create([
            'type' => 'document_processing_complete',
            'data' => [
                'message' => "Your document '{$document->document_name}' has been processed and is under review.",
                'document_id' => $document->id,
                'document_name' => $document->document_name,
                'ai_confidence' => $result['confidence'],
            ],
            'created_by' => 1, // System
        ]);

        UserNotification::create([
            'notification_id' => $notification->id,
            'user_id' => $document->user_id,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'none',
        ]);
    }
    
    /**
     * Notify user that manual review is needed (AI failed)
     */
    protected function notifyManualReviewNeeded($document)
    {
        $notification = Notification::create([
            'type' => 'document_manual_review_needed',
            'data' => [
                'message' => "Your document '{$document->document_name}' is being reviewed by our team.",
                'document_id' => $document->id,
                'document_name' => $document->document_name,
            ],
            'created_by' => 1,
        ]);

        UserNotification::create([
            'notification_id' => $notification->id,
            'user_id' => $document->user_id,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'none',
        ]);
    }
    
    /**
     * Send verification notification to user
     */
    protected function sendVerificationNotification($document, $status)
    {
        $message = $status === 'verified'
            ? "Your document '{$document->document_name}' has been automatically verified! You can now register for tournaments."
            : "Your document '{$document->document_name}' requires additional review. We'll notify you once verification is complete.";
        
        $notification = Notification::create([
            'type' => 'document_' . $status,
            'data' => [
                'message' => $message,
                'document_id' => $document->id,
                'document_name' => $document->document_name,
                'status' => $status,
                'ai_auto_verified' => $document->ai_auto_verified ?? false,
            ],
            'created_by' => $document->verified_by ?? 1,
        ]);

        UserNotification::create([
            'notification_id' => $notification->id,
            'user_id' => $document->user_id,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'none',
        ]);
    }
    
    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception)
    {
        Log::error('ProcessDocumentWithFreeAI job failed for document ' . $this->documentId . ': ' . $exception->getMessage());
        
        $document = UserDocument::find($this->documentId);
        if ($document) {
            $document->update([
                'ai_processed' => false,
                'ai_validation_notes' => 'AI processing failed after multiple attempts - manual review required'
            ]);
        }
    }
}

