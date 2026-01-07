<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CoachProfile;
use App\Models\EntityDocument;
use Illuminate\Http\Request;

class CoachAdminController extends Controller
{
    public function index(Request $request)
    {
        $query = CoachProfile::with(['user:id,username,email,first_name,last_name', 'verifier:id,username']);

        if ($request->filled('q')) {
            $q = $request->get('q');
            $query->whereHas('user', function ($sub) use ($q) {
                $sub->where('username', 'like', '%'.$q.'%')
                    ->orWhere('email', 'like', '%'.$q.'%')
                    ->orWhere('first_name', 'like', '%'.$q.'%')
                    ->orWhere('last_name', 'like', '%'.$q.'%');
            });
        }

        // Filter by verification status
        if ($request->filled('verification_status')) {
            $status = $request->get('verification_status');
            if ($status === 'verified') {
                $query->where('is_verified', true)->whereNotNull('verified_at');
            } elseif ($status === 'pending') {
                $query->where(function($q) {
                    $q->where('is_verified', false)
                      ->orWhereNull('verified_at');
                });
            }
        }

        // Filter by AI verification
        if ($request->filled('ai_verified')) {
            if ($request->get('ai_verified') === 'true' || $request->get('ai_verified') === '1') {
                $query->where('verified_by_ai', true);
            } else {
                $query->where('verified_by_ai', false);
            }
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        $coaches = $query->orderByDesc('id')->paginate($perPage);
        
        // Add verification info to response
        $coaches->getCollection()->transform(function ($coach) {
            $coach->verification_source = $coach->verified_by_ai ? 'ai' : ($coach->verified_by ? 'manual' : null);
            return $coach;
        });
        
        return response()->json($coaches);
    }

    public function show($id)
    {
        $coach = CoachProfile::with([
            'user:id,username,email,first_name,last_name',
            'verifier:id,username',
            'entityDocuments' => function($q) {
                $q->orderBy('created_at', 'desc');
            }
        ])->findOrFail($id);
        
        $coach->verification_source = $coach->verified_by_ai ? 'ai' : ($coach->verified_by ? 'manual' : null);
        
        return response()->json($coach);
    }

    public function approve(Request $request, $id)
    {
        $admin = auth()->user();
        $coach = CoachProfile::findOrFail($id);
        
        $request->validate([
            'verification_notes' => 'nullable|string|max:1000',
        ]);

        $coach->update([
            'is_verified' => true,
            'verified_at' => now(),
            'verified_by' => $admin->id,
            'verification_notes' => $request->input('verification_notes'),
            'verified_by_ai' => false, // Manual approval
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Coach verified successfully',
            'coach' => $coach->fresh(['verifier:id,username', 'user'])
        ]);
    }

    public function reject(Request $request, $id)
    {
        $admin = auth()->user();
        $coach = CoachProfile::findOrFail($id);
        
        $request->validate([
            'verification_notes' => 'required|string|max:1000',
        ]);

        $coach->update([
            'is_verified' => false,
            'verified_at' => null,
            'verified_by' => $admin->id,
            'verification_notes' => $request->input('verification_notes'),
            'verified_by_ai' => false, // Manual rejection
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Coach verification rejected',
            'coach' => $coach->fresh(['verifier:id,username', 'user'])
        ]);
    }

    public function resetVerification($id)
    {
        $coach = CoachProfile::findOrFail($id);

        $coach->update([
            'is_verified' => false,
            'verified_at' => null,
            'verified_by' => null,
            'verification_notes' => null,
            'verified_by_ai' => false,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Coach verification reset to pending',
            'coach' => $coach->fresh()
        ]);
    }

    public function documents($id)
    {
        $coach = CoachProfile::findOrFail($id);
        
        $documents = EntityDocument::where('documentable_type', CoachProfile::class)
            ->where('documentable_id', $id)
            ->with('verifier:id,username')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'coach' => [
                'id' => $coach->id,
                'user' => $coach->user ? [
                    'id' => $coach->user->id,
                    'name' => trim(($coach->user->first_name ?? '') . ' ' . ($coach->user->last_name ?? '')),
                ] : null,
            ],
            'documents' => $documents
        ]);
    }

    public function statistics()
    {
        $stats = [
            'total' => CoachProfile::count(),
            'verified' => CoachProfile::where('is_verified', true)->whereNotNull('verified_at')->count(),
            'pending' => CoachProfile::where(function($q) {
                $q->where('is_verified', false)->orWhereNull('verified_at');
            })->count(),
            'verified_by_ai' => CoachProfile::where('verified_by_ai', true)->count(),
            'verified_manually' => CoachProfile::where('is_verified', true)
                ->where('verified_by_ai', false)
                ->whereNotNull('verified_at')
                ->count(),
        ];

        return response()->json([
            'status' => 'success',
            'statistics' => $stats
        ]);
    }
}

