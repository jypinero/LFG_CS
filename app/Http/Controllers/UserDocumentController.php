<?php

namespace App\Http\Controllers;

use App\Models\UserDocument;
use App\Models\CoachProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserDocumentController extends Controller
{
    /**
     * Get all documents for authenticated user
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        
        $query = UserDocument::where('user_id', $user->id)
            ->with('verifier:id,username');

        // Filter by document type
        if ($request->filled('document_type')) {
            $query->where('document_type', $request->document_type);
        }

        // Filter by verification status
        if ($request->filled('status')) {
            $query->where('verification_status', $request->status);
        }

        // Only valid (non-expired) documents
        if ($request->filled('valid_only') && $request->valid_only) {
            $query->valid();
        }

        $documents = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'documents' => $documents
        ]);
    }

    /**
     * Upload a new document
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'document_type' => ['required', Rule::in([
                'government_id',
                'medical_certificate',
                'waiver_form',
                'insurance_proof',
                'parental_consent',
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

        // Validate custom_type is provided when document_type is 'other'
        if ($request->document_type === 'other' && !$request->filled('custom_type')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please specify the document type when selecting "Other"'
            ], 422);
        }

        // Store file
        $file = $request->file('document');
        $path = $file->store("users/{$user->id}/documents", 'public');

        $document = UserDocument::create([
            'user_id' => $user->id,
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
            \App\Jobs\ProcessDocumentWithFreeAI::dispatch($document->id);
        }

        // Update coach profile certifications if this is a coach certification document
        $this->updateCoachCertifications($user->id, $document);

        return response()->json([
            'status' => 'success',
            'message' => config('ai-verification.enabled') 
                ? 'Document uploaded successfully. AI verification in progress...'
                : 'Document uploaded successfully',
            'document' => $document->fresh(),
            'ai_processing' => config('ai-verification.enabled')
        ], 201);
    }

    /**
     * Get specific document details
     */
    public function show($id)
    {
        $user = auth()->user();
        
        $document = UserDocument::where('id', $id)
            ->where('user_id', $user->id)
            ->with('verifier:id,username')
            ->first();

        if (!$document) {
            return response()->json([
                'status' => 'error',
                'message' => 'Document not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'document' => $document
        ]);
    }

    /**
     * Update existing document
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();

        $document = UserDocument::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$document) {
            return response()->json([
                'status' => 'error',
                'message' => 'Document not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'document_type' => ['sometimes', Rule::in([
                'government_id',
                'medical_certificate',
                'waiver_form',
                'insurance_proof',
                'parental_consent',
                'other'
            ])],
            'custom_type' => 'required_if:document_type,other|nullable|string|max:100',
            'document_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'reference_number' => 'nullable|string|max:100',
            'document' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx',
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

        // If document type changed to 'other', ensure custom_type is provided
        $documentType = $request->input('document_type', $document->document_type);
        if ($documentType === 'other' && !$request->filled('custom_type') && !$document->custom_type) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please specify the document type when selecting "Other"'
            ], 422);
        }

        // Store old values for coach certification update
        $oldCustomType = $document->custom_type;
        $oldDocumentType = $document->document_type;
        $oldDocumentName = $document->document_name;

        // If new file uploaded, replace old one
        if ($request->hasFile('document')) {
            // Delete old file
            if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
                Storage::disk('public')->delete($document->file_path);
            }

            $file = $request->file('document');
            $path = $file->store("users/{$user->id}/documents", 'public');
            
            $document->file_path = $path;
            $document->file_type = $file->getClientMimeType();
            $document->file_size = $file->getSize();
            
            // Reset verification when file changes
            $document->verification_status = 'pending';
            $document->verified_by = null;
            $document->verified_at = null;
            $document->verification_notes = null;
        }

        // Update other fields
        if ($request->filled('document_type')) {
            $document->document_type = $request->document_type;
        }
        if ($request->filled('custom_type')) {
            $document->custom_type = $request->custom_type;
        }
        if ($request->filled('document_name')) {
            $document->document_name = $request->document_name;
        }
        if ($request->has('description')) {
            $document->description = $request->description;
        }
        if ($request->has('reference_number')) {
            $document->reference_number = $request->reference_number;
        }
        if ($request->has('issued_by')) {
            $document->issued_by = $request->issued_by;
        }
        if ($request->has('issue_date')) {
            $document->issue_date = $request->issue_date;
        }
        if ($request->has('expiry_date')) {
            $document->expiry_date = $request->expiry_date;
        }

        $document->save();

        // Update coach profile certifications if this is a coach certification document
        // Remove old certification if it changed
        if (($oldDocumentType === 'other' && $oldCustomType) || 
            (in_array($oldDocumentType, ['medical_certificate']))) {
            $oldCertName = $oldDocumentType === 'other' ? $oldCustomType : ($oldDocumentName ?: 'Medical Certificate');
            $this->removeCoachCertificationByName($user->id, $oldCertName);
        }

        // Add new certification
        $this->updateCoachCertifications($user->id, $document->fresh());

        return response()->json([
            'status' => 'success',
            'message' => 'Document updated successfully',
            'document' => $document->fresh()
        ]);
    }

    /**
     * Delete document
     */
    public function destroy($id)
    {
        $user = auth()->user();

        $document = UserDocument::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$document) {
            return response()->json([
                'status' => 'error',
                'message' => 'Document not found'
            ], 404);
        }

        // Delete file from storage
        if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
            Storage::disk('public')->delete($document->file_path);
        }

        // Remove from coach profile certifications if this was a certification document
        $this->removeCoachCertification($user->id, $document);

        $document->delete(); // Soft delete

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

        $document = UserDocument::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$document) {
            return response()->json([
                'status' => 'error',
                'message' => 'Document not found'
            ], 404);
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

    /**
     * Get document statistics
     */
    public function statistics()
    {
        $user = auth()->user();

        $stats = [
            'total' => UserDocument::where('user_id', $user->id)->count(),
            'verified' => UserDocument::where('user_id', $user->id)->verified()->count(),
            'pending' => UserDocument::where('user_id', $user->id)->where('verification_status', 'pending')->count(),
            'expired' => UserDocument::where('user_id', $user->id)
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '<', now())
                ->count(),
            'expiring_soon' => UserDocument::where('user_id', $user->id)
                ->whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [now(), now()->addDays(30)])
                ->count(),
        ];

        return response()->json([
            'status' => 'success',
            'statistics' => $stats
        ]);
    }

    /**
     * Update coach profile certifications based on uploaded certification documents
     */
    private function updateCoachCertifications($userId, $document)
    {
        // Check if user is a coach
        $coachProfile = CoachProfile::where('user_id', $userId)->first();
        
        if (!$coachProfile) {
            return; // User is not a coach, skip
        }

        // Check if this is a certification document
        // Certification documents are those with document_type 'other' and custom_type provided
        // Or we can check if custom_type contains certification-related keywords
        $isCertification = false;
        $certificationName = null;

        if ($document->document_type === 'other' && $document->custom_type) {
            $isCertification = true;
            $certificationName = $document->custom_type;
        } elseif (in_array($document->document_type, ['medical_certificate'])) {
            // Medical certificates can also be coach certifications
            $isCertification = true;
            $certificationName = $document->document_name ?: 'Medical Certificate';
        }

        if (!$isCertification) {
            return; // Not a certification document
        }

        // Get current certifications array
        $certifications = $coachProfile->certifications ?? [];
        
        // Ensure it's an array
        if (!is_array($certifications)) {
            $certifications = [];
        }

        // Add certification if not already present
        if (!in_array($certificationName, $certifications)) {
            $certifications[] = $certificationName;
            $coachProfile->certifications = $certifications;
            $coachProfile->save();
        }
    }

    /**
     * Remove certification from coach profile when document is deleted
     */
    private function removeCoachCertification($userId, $document)
    {
        // Determine certification name to remove
        $certificationName = null;

        if ($document->document_type === 'other' && $document->custom_type) {
            $certificationName = $document->custom_type;
        } elseif (in_array($document->document_type, ['medical_certificate'])) {
            $certificationName = $document->document_name ?: 'Medical Certificate';
        }

        if (!$certificationName) {
            return; // Not a certification document
        }

        $this->removeCoachCertificationByName($userId, $certificationName);
    }

    /**
     * Remove a specific certification by name from coach profile
     */
    private function removeCoachCertificationByName($userId, $certificationName)
    {
        // Check if user is a coach
        $coachProfile = CoachProfile::where('user_id', $userId)->first();
        
        if (!$coachProfile) {
            return; // User is not a coach, skip
        }

        // Get current certifications array
        $certifications = $coachProfile->certifications ?? [];
        
        // Ensure it's an array
        if (!is_array($certifications)) {
            return;
        }

        // Remove certification if present
        $certifications = array_values(array_filter($certifications, function($cert) use ($certificationName) {
            return $cert !== $certificationName;
        }));

        $coachProfile->certifications = $certifications;
        $coachProfile->save();
    }
}

