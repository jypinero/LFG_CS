<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\EntityDocument;
use Illuminate\Http\Request;

class TeamAdminController extends Controller
{
    public function index(Request $request)
    {
        $query = Team::with(['creator:id,username,email', 'sport', 'certificationVerifier:id,username']);

        if ($request->filled('q')) {
            $q = $request->get('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', '%'.$q.'%');
            });
        }

        // Filter by verification status
        if ($request->filled('verification_status')) {
            $status = $request->get('verification_status');
            if ($status === 'verified') {
                $query->where('certification_status', 'verified');
            } elseif ($status === 'pending') {
                $query->where('certification_status', 'pending');
            } elseif ($status === 'rejected') {
                $query->where('certification_status', 'rejected');
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
        $teams = $query->orderByDesc('id')->paginate($perPage);
        
        // Add verification info to response
        $teams->getCollection()->transform(function ($team) {
            $team->verification_source = $team->verified_by_ai ? 'ai' : ($team->certification_verified_by ? 'manual' : null);
            return $team;
        });
        
        return response()->json($teams);
    }

    public function show($id)
    {
        $team = Team::with([
            'creator:id,username,email,first_name,last_name',
            'sport',
            'certificationVerifier:id,username',
            'entityDocuments' => function($q) {
                $q->orderBy('created_at', 'desc');
            }
        ])->findOrFail($id);
        
        $team->verification_source = $team->verified_by_ai ? 'ai' : ($team->certification_verified_by ? 'manual' : null);
        
        return response()->json($team);
    }

    public function approve(Request $request, $id)
    {
        $admin = auth()->user();
        $team = Team::findOrFail($id);
        
        $request->validate([
            'verification_notes' => 'nullable|string|max:1000',
        ]);

        $team->update([
            'certification_status' => 'verified',
            'certification_verified_at' => now(),
            'certification_verified_by' => $admin->id,
            'certification_ai_notes' => $request->input('verification_notes'),
            'verified_by_ai' => false, // Manual approval
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Team verified successfully',
            'team' => $team->fresh(['certificationVerifier:id,username'])
        ]);
    }

    public function reject(Request $request, $id)
    {
        $admin = auth()->user();
        $team = Team::findOrFail($id);
        
        $request->validate([
            'verification_notes' => 'required|string|max:1000',
        ]);

        $team->update([
            'certification_status' => 'rejected',
            'certification_verified_at' => now(),
            'certification_verified_by' => $admin->id,
            'certification_ai_notes' => $request->input('verification_notes'),
            'verified_by_ai' => false, // Manual rejection
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Team verification rejected',
            'team' => $team->fresh(['certificationVerifier:id,username'])
        ]);
    }

    public function resetVerification($id)
    {
        $team = Team::findOrFail($id);

        $team->update([
            'certification_status' => 'pending',
            'certification_verified_at' => null,
            'certification_verified_by' => null,
            'certification_ai_notes' => null,
            'verified_by_ai' => false,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Team verification reset to pending',
            'team' => $team->fresh()
        ]);
    }

    public function documents($id)
    {
        $team = Team::findOrFail($id);
        
        $documents = EntityDocument::where('documentable_type', Team::class)
            ->where('documentable_id', $id)
            ->with('verifier:id,username')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'documents' => $documents
        ]);
    }

    public function statistics()
    {
        $stats = [
            'total' => Team::count(),
            'verified' => Team::where('certification_status', 'verified')->count(),
            'pending' => Team::where('certification_status', 'pending')->count(),
            'rejected' => Team::where('certification_status', 'rejected')->count(),
            'verified_by_ai' => Team::where('verified_by_ai', true)->count(),
            'verified_manually' => Team::where('certification_status', 'verified')
                ->where('verified_by_ai', false)
                ->count(),
        ];

        return response()->json([
            'status' => 'success',
            'statistics' => $stats
        ]);
    }
}

