<?php

namespace App\Http\Controllers;

use App\Models\EntityDocument;
use App\Models\User;
use App\Models\Venue;
use App\Models\Team;
use App\Models\CoachProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Jobs\ProcessEntityDocumentWithAI;

class EntityDocumentController extends Controller
{
    public function store(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|in:user,venue,team,coach',
            'entity_id' => 'required|integer',
            'document_category' => 'required|in:athlete_certification,venue_business,team_registration,coach_license,other',
            'document_type' => ['required', \Illuminate\Validation\Rule::in([
                'government_id',
                'medical_certificate',
                'waiver_form',
                'insurance_proof',
                'parental_consent',
                'business_license',
                'certification',
                'registration',
                'permit',
                'other'
            ])],
            'custom_type' => 'required_if:document_type,other|nullable|string|max:100',
            'document_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'reference_number' => 'nullable|string|max:100',
            'document' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx',
            'issued_by' => 'nullable|string|max:255',
            'issue_date' => 'nullable|date|before_or_equal:today',
            'expiry_date' => 'nullable|date|after:issue_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verify entity ownership/access
        $entity = $this->getEntity($request->entity_type, $request->entity_id);
        if (!$entity) {
            return response()->json([
                'status' => 'error',
                'message' => 'Entity not found'
            ], 404);
        }

        // Check if user has permission to upload document for this entity
        if (!$this->canUploadDocument($user, $entity, $request->entity_type)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to upload documents for this entity'
            ], 403);
        }

        // Store file
        $file = $request->file('document');
        $entityTypeDir = str_replace('_', '-', $request->entity_type);
        $path = $file->store("entities/{$entityTypeDir}/{$request->entity_id}/documents", 'public');

        $modelClass = $this->getModelClass($request->entity_type);
        
        $document = EntityDocument::create([
            'documentable_type' => $modelClass,
            'documentable_id' => $request->entity_id,
            'document_category' => $request->document_category,
            'document_type' => $request->document_type,
            'custom_type' => $request->document_type === 'other' ? $request->custom_type : null,
            'document_name' => $request->document_name,
            'description' => $request->description,
            'reference_number' => $request->reference_number,
            'file_path' => $path,
            'file_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'issued_by' => $request->issued_by,
            'issue_date' => $request->issue_date,
            'expiry_date' => $request->expiry_date,
            'verification_status' => 'pending',
        ]);

        // Queue AI processing if enabled
        if (config('ai-verification.enabled') && config('ai-verification.process_on_upload')) {
            ProcessEntityDocumentWithAI::dispatch($document->id);
        }

        return response()->json([
            'status' => 'success',
            'message' => config('ai-verification.enabled') 
                ? 'Document uploaded successfully. AI verification in progress...'
                : 'Document uploaded successfully',
            'document' => $document->fresh(['documentable']),
            'ai_processing' => config('ai-verification.enabled')
        ], 201);
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|in:user,venue,team,coach',
            'entity_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verify entity ownership/access
        $entity = $this->getEntity($request->entity_type, $request->entity_id);
        if (!$entity) {
            return response()->json([
                'status' => 'error',
                'message' => 'Entity not found'
            ], 404);
        }

        if (!$this->canUploadDocument($user, $entity, $request->entity_type)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to view documents for this entity'
            ], 403);
        }

        $modelClass = $this->getModelClass($request->entity_type);
        $query = EntityDocument::where('documentable_type', $modelClass)
            ->where('documentable_id', $request->entity_id)
            ->with('verifier:id,username');

        // Filter by document category
        if ($request->filled('document_category')) {
            $query->where('document_category', $request->document_category);
        }

        // Filter by verification status
        if ($request->filled('status')) {
            $query->where('verification_status', $request->status);
        }

        $documents = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'documents' => $documents
        ]);
    }

    protected function getEntity($type, $id)
    {
        $modelClass = $this->getModelClass($type);
        return $modelClass::find($id);
    }

    protected function getModelClass($type)
    {
        $map = [
            'user' => User::class,
            'venue' => Venue::class,
            'team' => Team::class,
            'coach' => CoachProfile::class,
        ];
        return $map[$type] ?? null;
    }

    /**
     * Delete document
     */
    public function destroy($id)
    {
        $user = auth()->user();
        
        $document = EntityDocument::find($id);
        
        if (!$document) {
            return response()->json([
                'status' => 'error',
                'message' => 'Document not found'
            ], 404);
        }
        
        // Get the entity to check permissions
        $entity = $document->documentable;
        if (!$entity) {
            return response()->json([
                'status' => 'error',
                'message' => 'Associated entity not found'
            ], 404);
        }
        
        // Determine entity type for permission check
        $entityType = $this->getEntityTypeFromModel($document->documentable_type);
        
        if (!$entityType) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid entity type'
            ], 400);
        }
        
        // Check if user has permission to delete document for this entity
        if (!$this->canUploadDocument($user, $entity, $entityType)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to delete this document'
            ], 403);
        }
        
        // Delete file from storage
        if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
            Storage::disk('public')->delete($document->file_path);
        }
        
        $document->delete();
        
        return response()->json([
            'status' => 'success',
            'message' => 'Document deleted successfully'
        ]);
    }

    /**
     * Download document file
     */
    public function download($id)
    {
        $user = auth()->user();
        
        $document = EntityDocument::find($id);
        
        if (!$document) {
            return response()->json([
                'status' => 'error',
                'message' => 'Document not found'
            ], 404);
        }
        
        // Get the entity to check permissions
        $entity = $document->documentable;
        if (!$entity) {
            return response()->json([
                'status' => 'error',
                'message' => 'Associated entity not found'
            ], 404);
        }
        
        // Determine entity type for permission check
        $entityType = $this->getEntityTypeFromModel($document->documentable_type);
        
        if (!$entityType) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid entity type'
            ], 400);
        }
        
        // Check if user has permission to download document for this entity
        if (!$this->canUploadDocument($user, $entity, $entityType)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to download this document'
            ], 403);
        }

        if (!Storage::disk('public')->exists($document->file_path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'File not found'
            ], 404);
        }

        return Storage::disk('public')->download(
            $document->file_path,
            $document->document_name . '.' . pathinfo($document->file_path, PATHINFO_EXTENSION)
        );
    }

    protected function canUploadDocument($user, $entity, $entityType)
    {
        switch ($entityType) {
            case 'user':
                return $user->id === $entity->id;
            case 'venue':
                return $entity->created_by === $user->id;
            case 'team':
                return $entity->created_by === $user->id;
            case 'coach':
                return $entity->user_id === $user->id;
            default:
                return false;
        }
    }

    protected function getEntityTypeFromModel($modelClass)
    {
        $map = [
            User::class => 'user',
            Venue::class => 'venue',
            Team::class => 'team',
            CoachProfile::class => 'coach',
        ];
        return $map[$modelClass] ?? null;
    }
}
