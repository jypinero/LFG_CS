<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserDocumentAdminController extends Controller
{
    /**
     * List all user documents (admin view)
     */
    public function index(Request $request)
    {
        $query = UserDocument::with(['user:id,username,email', 'verifier:id,username']);

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by username
        if ($request->filled('username')) {
            $query->whereHas('user', function($q) use ($request) {
                $q->where('username', 'like', '%' . $request->username . '%');
            });
        }

        // Filter by document type
        if ($request->filled('document_type')) {
            $query->where('document_type', $request->document_type);
        }

        // Filter by verification status
        if ($request->filled('status')) {
            $query->where('verification_status', $request->status);
        }

        // Filter by expired status
        if ($request->filled('expired')) {
            if ($request->expired === 'true' || $request->expired === '1') {
                $query->whereNotNull('expiry_date')
                      ->where('expiry_date', '<', now());
            } else {
                $query->where(function($q) {
                    $q->whereNull('expiry_date')
                      ->orWhere('expiry_date', '>=', now());
                });
            }
        }

        // Search by document name
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('document_name', 'like', '%' . $search . '%')
                  ->orWhere('reference_number', 'like', '%' . $search . '%')
                  ->orWhere('custom_type', 'like', '%' . $search . '%');
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSorts = ['created_at', 'updated_at', 'document_name', 'verification_status', 'expiry_date'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Pagination
        $perPage = min($request->input('per_page', 20), 100);
        $documents = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $documents->items(),
            'pagination' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
                'from' => $documents->firstItem(),
                'to' => $documents->lastItem(),
            ]
        ]);
    }

    /**
     * Get specific document details
     */
    public function show($id)
    {
        $document = UserDocument::with(['user:id,username,email,first_name,last_name', 'verifier:id,username'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'document' => $document
        ]);
    }

    /**
     * Get all documents for a specific user
     */
    public function userDocuments($userId)
    {
        $user = User::findOrFail($userId);
        
        $documents = UserDocument::where('user_id', $userId)
            ->with('verifier:id,username')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'name' => $user->first_name . ' ' . $user->last_name
            ],
            'documents' => $documents
        ]);
    }

    /**
     * Verify (approve) a document
     */
    public function verify(Request $request, $id)
    {
        $admin = auth()->user();
        
        $request->validate([
            'verification_notes' => 'nullable|string|max:1000',
        ]);

        $document = UserDocument::findOrFail($id);

        $document->verification_status = 'verified';
        $document->verified_by = $admin->id;
        $document->verified_at = now();
        $document->verification_notes = $request->input('verification_notes');
        $document->save();

        // Send notification to user
        $this->sendVerificationNotification($document, 'verified');

        return response()->json([
            'status' => 'success',
            'message' => 'Document verified successfully',
            'document' => $document->fresh(['user:id,username', 'verifier:id,username'])
        ]);
    }

    /**
     * Reject a document
     */
    public function reject(Request $request, $id)
    {
        $admin = auth()->user();
        
        $request->validate([
            'verification_notes' => 'required|string|max:1000',
        ]);

        $document = UserDocument::findOrFail($id);

        $document->verification_status = 'rejected';
        $document->verified_by = $admin->id;
        $document->verified_at = now();
        $document->verification_notes = $request->input('verification_notes');
        $document->save();

        // Send notification to user
        $this->sendVerificationNotification($document, 'rejected');

        return response()->json([
            'status' => 'success',
            'message' => 'Document rejected',
            'document' => $document->fresh(['user:id,username', 'verifier:id,username'])
        ]);
    }

    /**
     * Reset document verification (back to pending)
     */
    public function resetVerification($id)
    {
        $document = UserDocument::findOrFail($id);

        $document->verification_status = 'pending';
        $document->verified_by = null;
        $document->verified_at = null;
        $document->verification_notes = null;
        $document->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Document verification reset to pending',
            'document' => $document->fresh()
        ]);
    }

    /**
     * Bulk verify documents
     */
    public function bulkVerify(Request $request)
    {
        $admin = auth()->user();
        
        $request->validate([
            'document_ids' => 'required|array|min:1',
            'document_ids.*' => 'exists:user_documents,id',
            'verification_notes' => 'nullable|string|max:1000',
        ]);

        $documents = UserDocument::whereIn('id', $request->document_ids)->get();

        foreach ($documents as $document) {
            $document->verification_status = 'verified';
            $document->verified_by = $admin->id;
            $document->verified_at = now();
            $document->verification_notes = $request->input('verification_notes');
            $document->save();

            $this->sendVerificationNotification($document, 'verified');
        }

        return response()->json([
            'status' => 'success',
            'message' => count($documents) . ' documents verified successfully',
            'count' => count($documents)
        ]);
    }

    /**
     * Bulk reject documents
     */
    public function bulkReject(Request $request)
    {
        $admin = auth()->user();
        
        $request->validate([
            'document_ids' => 'required|array|min:1',
            'document_ids.*' => 'exists:user_documents,id',
            'verification_notes' => 'required|string|max:1000',
        ]);

        $documents = UserDocument::whereIn('id', $request->document_ids)->get();

        foreach ($documents as $document) {
            $document->verification_status = 'rejected';
            $document->verified_by = $admin->id;
            $document->verified_at = now();
            $document->verification_notes = $request->input('verification_notes');
            $document->save();

            $this->sendVerificationNotification($document, 'rejected');
        }

        return response()->json([
            'status' => 'success',
            'message' => count($documents) . ' documents rejected',
            'count' => count($documents)
        ]);
    }

    /**
     * Download document file (admin)
     */
    public function download($id)
    {
        $document = UserDocument::findOrFail($id);

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
     * Delete document (admin hard delete)
     */
    public function destroy($id)
    {
        $document = UserDocument::findOrFail($id);

        // Delete file from storage
        if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
            Storage::disk('public')->delete($document->file_path);
        }

        $document->forceDelete(); // Hard delete for admin

        return response()->json([
            'status' => 'success',
            'message' => 'Document permanently deleted'
        ]);
    }

    /**
     * Get document verification statistics
     */
    public function statistics()
    {
        $stats = [
            'total' => UserDocument::count(),
            'pending' => UserDocument::where('verification_status', 'pending')->count(),
            'verified' => UserDocument::where('verification_status', 'verified')->count(),
            'rejected' => UserDocument::where('verification_status', 'rejected')->count(),
            'expired' => UserDocument::whereNotNull('expiry_date')
                ->where('expiry_date', '<', now())
                ->count(),
            'expiring_soon' => UserDocument::whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [now(), now()->addDays(30)])
                ->count(),
            'by_type' => UserDocument::selectRaw('document_type, COUNT(*) as count')
                ->groupBy('document_type')
                ->get()
                ->mapWithKeys(function($item) {
                    return [$item->document_type => $item->count];
                }),
        ];

        return response()->json([
            'status' => 'success',
            'statistics' => $stats
        ]);
    }

    /**
     * Send verification notification to user
     */
    private function sendVerificationNotification($document, $status)
    {
        $notification = \App\Models\Notification::create([
            'type' => 'document_' . $status,
            'data' => [
                'message' => $status === 'verified' 
                    ? "Your document '{$document->document_name}' has been verified and approved."
                    : "Your document '{$document->document_name}' has been rejected. Reason: {$document->verification_notes}",
                'document_id' => $document->id,
                'document_name' => $document->document_name,
                'status' => $status,
            ],
            'created_by' => $document->verified_by,
        ]);

        \App\Models\UserNotification::create([
            'notification_id' => $notification->id,
            'user_id' => $document->user_id,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'none',
        ]);
    }
}










