<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EntityDocument;
use App\Models\User;
use App\Models\Venue;
use App\Models\Team;
use App\Models\CoachProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\EntityVerificationService;

class EntityDocumentAdminController extends Controller
{
    public function index(Request $request)
    {
        $query = EntityDocument::with(['documentable', 'verifier:id,username']);

        // Filter by entity type
        if ($request->filled('entity_type')) {
            $entityType = $this->getModelClass($request->entity_type);
            if ($entityType) {
                $query->where('documentable_type', $entityType);
            }
        }

        // Filter by document category
        if ($request->filled('document_category')) {
            $query->where('document_category', $request->document_category);
        }

        // Filter by verification status
        if ($request->filled('status')) {
            $query->where('verification_status', $request->status);
        }

        // Filter by AI verification
        if ($request->filled('ai_verified')) {
            if ($request->get('ai_verified') === 'true' || $request->get('ai_verified') === '1') {
                $query->where('ai_auto_verified', true);
            }
        }

        $perPage = min($request->input('per_page', 20), 100);
        $documents = $query->orderBy('created_at', 'desc')->paginate($perPage);
        
        // Transform to include entity info
        $documents->getCollection()->transform(function ($doc) {
            $entity = $doc->documentable;
            $doc->entity_name = $this->getEntityName($entity);
            $doc->entity_type_display = $this->getEntityTypeDisplay($doc->documentable_type);
            return $doc;
        });

        return response()->json([
            'status' => 'success',
            'data' => $documents->items(),
            'pagination' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ]
        ]);
    }

    public function show($id)
    {
        $document = EntityDocument::with(['documentable', 'verifier:id,username'])
            ->findOrFail($id);
        
        $entity = $document->documentable;
        $document->entity_name = $this->getEntityName($entity);
        $document->entity_type_display = $this->getEntityTypeDisplay($document->documentable_type);

        return response()->json([
            'status' => 'success',
            'document' => $document
        ]);
    }

    public function verify(Request $request, $id)
    {
        $admin = auth()->user();
        $document = EntityDocument::findOrFail($id);
        
        $request->validate([
            'verification_notes' => 'nullable|string|max:1000',
        ]);

        $document->verify($admin->id, $request->input('verification_notes'));
        
        // Check entity verification
        $verificationService = app(EntityVerificationService::class);
        $verificationService->checkAndVerifyEntity(
            $document->documentable_type,
            $document->documentable_id
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Document verified successfully',
            'document' => $document->fresh(['verifier:id,username', 'documentable'])
        ]);
    }

    public function reject(Request $request, $id)
    {
        $admin = auth()->user();
        $document = EntityDocument::findOrFail($id);
        
        $request->validate([
            'verification_notes' => 'required|string|max:1000',
        ]);

        $document->reject($admin->id, $request->input('verification_notes'));

        return response()->json([
            'status' => 'success',
            'message' => 'Document rejected',
            'document' => $document->fresh(['verifier:id,username', 'documentable'])
        ]);
    }

    public function resetVerification($id)
    {
        $document = EntityDocument::findOrFail($id);

        $document->update([
            'verification_status' => 'pending',
            'verified_by' => null,
            'verified_at' => null,
            'verification_notes' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Document verification reset to pending',
            'document' => $document->fresh()
        ]);
    }

    public function download($id)
    {
        $document = EntityDocument::findOrFail($id);

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

    protected function getModelClass($type)
    {
        $map = [
            'user' => User::class,
            'venue' => Venue::class,
            'team' => Team::class,
            'coach' => CoachProfile::class,
        ];
        return $map[strtolower($type)] ?? null;
    }

    protected function getEntityName($entity)
    {
        if ($entity instanceof User) {
            return trim(($entity->first_name ?? '') . ' ' . ($entity->last_name ?? ''));
        } elseif ($entity instanceof Venue || $entity instanceof Team) {
            return $entity->name ?? 'Unknown';
        } elseif ($entity instanceof CoachProfile) {
            $user = $entity->user;
            return $user ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) : 'Unknown';
        }
        return 'Unknown';
    }

    protected function getEntityTypeDisplay($type)
    {
        $map = [
            User::class => 'User (Athlete)',
            Venue::class => 'Venue',
            Team::class => 'Team',
            CoachProfile::class => 'Coach',
        ];
        return $map[$type] ?? 'Unknown';
    }
}
