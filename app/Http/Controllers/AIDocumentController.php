<?php

namespace App\Http\Controllers;

use App\Models\UserDocument;
use App\Jobs\ProcessDocumentWithFreeAI;
use App\Services\OllamaAIService;
use Illuminate\Http\Request;

class AIDocumentController extends Controller
{
    /**
     * Get AI smart queue (sorted by priority)
     */
    public function smartQueue(Request $request)
    {
        $autoApproved = UserDocument::where('ai_auto_verified', true)
            ->where('ai_processed_at', '>=', now()->startOfDay())
            ->count();

        // High priority: Low confidence or critical flags
        $highPriority = UserDocument::with(['user:id,username,email'])
            ->where('ai_processed', true)
            ->where('verification_status', 'pending')
            ->where(function($q) {
                $q->where('ai_confidence_score', '<', config('ai-verification.quick_review_threshold', 70))
                  ->orWhereNotNull('ai_flags');
            })
            ->orderBy('ai_confidence_score', 'asc')
            ->limit(20)
            ->get()
            ->map(function($doc) {
                return [
                    'id' => $doc->id,
                    'user' => $doc->user->username ?? 'Unknown',
                    'user_email' => $doc->user->email ?? '',
                    'document_name' => $doc->document_name,
                    'document_type' => $doc->document_type === 'other' ? $doc->custom_type : $doc->document_type,
                    'ai_confidence' => $doc->ai_confidence_score,
                    'ai_flags' => $doc->ai_flags ?? [],
                    'ai_quality_score' => $doc->ai_quality_score,
                    'created_at' => $doc->created_at,
                    'file_url' => $doc->file_url
                ];
            });

        // Quick review: Medium confidence, no critical flags
        $quickReview = UserDocument::with(['user:id,username,email'])
            ->where('ai_processed', true)
            ->where('verification_status', 'pending')
            ->whereBetween('ai_confidence_score', [
                config('ai-verification.quick_review_threshold', 70),
                config('ai-verification.auto_approve_threshold', 85)
            ])
            ->where(function($q) {
                $q->whereNull('ai_flags')
                  ->orWhereRaw('JSON_LENGTH(ai_flags) = 0');
            })
            ->orderBy('ai_confidence_score', 'desc')
            ->limit(20)
            ->get()
            ->map(function($doc) {
                return [
                    'id' => $doc->id,
                    'user' => $doc->user->username ?? 'Unknown',
                    'document_name' => $doc->document_name,
                    'document_type' => $doc->document_type === 'other' ? $doc->custom_type : $doc->document_type,
                    'ai_confidence' => $doc->ai_confidence_score,
                    'ai_quality_score' => $doc->ai_quality_score,
                    'ai_validation_notes' => $doc->ai_validation_notes,
                    'created_at' => $doc->created_at,
                    'file_url' => $doc->file_url
                ];
            });

        // Statistics
        $stats = [
            'auto_approved_today' => $autoApproved,
            'pending_high_priority' => $highPriority->count(),
            'pending_quick_review' => $quickReview->count(),
            'avg_confidence' => UserDocument::where('ai_processed', true)
                ->where('ai_processed_at', '>=', now()->startOfDay())
                ->avg('ai_confidence_score'),
            'avg_processing_time' => '10.5s', // Can calculate from logs if needed
        ];

        return response()->json([
            'status' => 'success',
            'statistics' => $stats,
            'high_priority' => $highPriority,
            'quick_review' => $quickReview
        ]);
    }

    /**
     * Get AI analysis for specific document
     */
    public function getAnalysis($id)
    {
        $document = UserDocument::with(['user:id,username,email,first_name,last_name'])
            ->findOrFail($id);

        $analysis = [
            'document_id' => $document->id,
            'user' => [
                'id' => $document->user->id,
                'name' => trim(($document->user->first_name ?? '') . ' ' . ($document->user->last_name ?? '')),
                'username' => $document->user->username,
                'email' => $document->user->email,
            ],
            'document_info' => [
                'name' => $document->document_name,
                'type' => $document->document_type === 'other' ? $document->custom_type : $document->document_type,
                'file_url' => $document->file_url,
                'uploaded_at' => $document->created_at,
            ],
            'ai_processing' => [
                'processed' => $document->ai_processed,
                'processed_at' => $document->ai_processed_at,
                'confidence_score' => $document->ai_confidence_score,
                'quality_score' => $document->ai_quality_score,
                'ocr_text' => $document->ai_ocr_text,
            ],
            'ai_results' => [
                'extracted_data' => $document->ai_extracted_data ?? [],
                'name_matches' => $document->ai_name_matches,
                'flags' => $document->ai_flags ?? [],
                'validation_notes' => $document->ai_validation_notes,
                'auto_verified' => $document->ai_auto_verified,
            ],
            'verification_status' => [
                'status' => $document->verification_status,
                'verified_by' => $document->verified_by,
                'verified_at' => $document->verified_at,
                'verification_notes' => $document->verification_notes,
            ]
        ];

        return response()->json([
            'status' => 'success',
            'analysis' => $analysis
        ]);
    }

    /**
     * Reprocess document with AI
     */
    public function reprocess($id)
    {
        $document = UserDocument::findOrFail($id);

        // Reset AI fields
        $document->update([
            'ai_processed' => false,
            'ai_confidence_score' => null,
            'ai_extracted_data' => null,
            'ai_validation_notes' => null,
            'ai_flags' => null,
            'ai_quality_score' => null,
            'ai_name_matches' => null,
            'ai_auto_verified' => false,
            'ai_processed_at' => null,
            'ai_ocr_text' => null,
        ]);

        // Queue for reprocessing
        ProcessDocumentWithFreeAI::dispatch($document->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Document queued for AI reprocessing'
        ]);
    }

    /**
     * Get AI statistics
     */
    public function statistics()
    {
        $stats = [
            'total_processed' => UserDocument::where('ai_processed', true)->count(),
            'auto_approved' => UserDocument::where('ai_auto_verified', true)->count(),
            'pending_review' => UserDocument::where('ai_processed', true)
                ->where('verification_status', 'pending')
                ->count(),
            'today_processed' => UserDocument::where('ai_processed', true)
                ->whereDate('ai_processed_at', today())
                ->count(),
            'today_auto_approved' => UserDocument::where('ai_auto_verified', true)
                ->whereDate('verified_at', today())
                ->count(),
            'avg_confidence' => round(UserDocument::where('ai_processed', true)->avg('ai_confidence_score'), 2),
            'avg_quality' => round(UserDocument::where('ai_processed', true)->avg('ai_quality_score'), 2),
            'by_confidence_range' => [
                'high_90_plus' => UserDocument::where('ai_confidence_score', '>=', 90)->count(),
                'good_80_89' => UserDocument::whereBetween('ai_confidence_score', [80, 89])->count(),
                'medium_70_79' => UserDocument::whereBetween('ai_confidence_score', [70, 79])->count(),
                'low_below_70' => UserDocument::where('ai_confidence_score', '<', 70)->count(),
            ],
            'auto_approve_rate' => UserDocument::where('ai_processed', true)->count() > 0
                ? round((UserDocument::where('ai_auto_verified', true)->count() / UserDocument::where('ai_processed', true)->count()) * 100, 2)
                : 0,
        ];

        return response()->json([
            'status' => 'success',
            'statistics' => $stats
        ]);
    }

    /**
     * Check if Ollama is available
     */
    public function checkService(OllamaAIService $aiService)
    {
        $isAvailable = $aiService->isAvailable();
        $models = $aiService->getAvailableModels();

        return response()->json([
            'status' => 'success',
            'ai_service' => [
                'provider' => config('ai-verification.provider'),
                'available' => $isAvailable,
                'base_url' => config('ai-verification.ollama_base_url'),
                'model' => config('ai-verification.ollama_model'),
                'available_models' => $models,
            ],
            'config' => [
                'auto_approve_threshold' => config('ai-verification.auto_approve_threshold'),
                'quick_review_threshold' => config('ai-verification.quick_review_threshold'),
                'process_on_upload' => config('ai-verification.process_on_upload'),
            ]
        ]);
    }
}


