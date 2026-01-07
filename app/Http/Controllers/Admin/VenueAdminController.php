<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Models\EntityDocument;
use Illuminate\Http\Request;
use App\Http\Requests\Admin\Venues\UpdateVenueRequest;
use App\Http\Requests\Admin\Venues\RejectVenueRequest;

class VenueAdminController extends Controller
{
	public function index(Request $request)
	{
		$query = Venue::with(['photos', 'facilities']);
		if ($request->filled('q')) {
			$q = $request->get('q');
			$query->where(function ($sub) use ($q) {
				$sub->where('name', 'like', '%'.$q.'%')
					->orWhere('address', 'like', '%'.$q.'%')
					->orWhere('description', 'like', '%'.$q.'%');
			});
		}
		if ($request->filled('status')) {
			if ($request->get('status') === 'closed') {
				$query->where('is_closed', true);
			} elseif ($request->get('status') === 'active') {
				$query->where('is_closed', false);
			} elseif ($request->get('status') === 'verified') {
				$query->whereNotNull('verified_at');
			} elseif ($request->get('status') === 'unverified') {
				$query->whereNull('verified_at');
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
		$venues = $query->orderByDesc('id')->paginate($perPage);
		
		// Add verification info to response
		$venues->getCollection()->transform(function ($venue) {
			$venue->verification_source = $venue->verified_by_ai ? 'ai' : ($venue->verified_at ? 'manual' : null);
			return $venue;
		});
		
		return response()->json($venues);
	}

	public function show(int $id)
	{
		$venue = Venue::with([
			'photos', 
			'facilities', 
			'operatingHours', 
			'amenities', 
			'closureDates',
			'entityDocuments' => function($q) {
				$q->orderBy('created_at', 'desc');
			}
		])->findOrFail($id);
		
		$venue->verification_source = $venue->verified_by_ai ? 'ai' : ($venue->verified_at ? 'manual' : null);
		
		return response()->json($venue);
	}

	public function update(UpdateVenueRequest $request, int $id)
	{
		$venue = Venue::findOrFail($id);
		$data = $request->validated();
		if (array_key_exists('is_closed', $data)) {
			$data['closed_at'] = $data['is_closed'] ? now() : null;
		}
		$venue->update($data);
		return response()->json($venue);
	}

	public function approve(int $id)
	{
		$admin = auth()->user();
		$venue = Venue::findOrFail($id);
		$venue->update([
			'verified_at' => now(), 
			'verification_expires_at' => now()->addYear(),
			'verified_by' => $admin->id,
			'verified_by_ai' => false, // Manual approval
		]);
		return response()->json([
			'status' => 'success',
			'message' => 'Approved',
			'venue' => $venue->fresh()
		]);
	}

	public function reject(RejectVenueRequest $request, int $id)
	{
		$admin = auth()->user();
		$venue = Venue::findOrFail($id);
		$reason = $request->input('reason', 'Rejected by admin');
		$venue->update([
			'verified_at' => null, 
			'verification_expires_at' => null, 
			'closed_reason' => $reason,
			'verified_by' => $admin->id,
			'verified_by_ai' => false, // Manual rejection
		]);
		return response()->json([
			'status' => 'success',
			'message' => 'Rejected',
			'venue' => $venue->fresh()
		]);
	}
	
	public function resetVerification(int $id)
	{
		$venue = Venue::findOrFail($id);
		$venue->update([
			'verified_at' => null,
			'verification_expires_at' => null,
			'verified_by' => null,
			'verified_by_ai' => false,
		]);
		
		return response()->json([
			'status' => 'success',
			'message' => 'Verification reset to pending',
			'venue' => $venue->fresh()
		]);
	}
	
	public function documents(int $id)
	{
		$venue = Venue::findOrFail($id);
		
		$documents = EntityDocument::where('documentable_type', Venue::class)
			->where('documentable_id', $id)
			->with('verifier:id,username')
			->orderBy('created_at', 'desc')
			->get();
		
		return response()->json([
			'status' => 'success',
			'venue' => [
				'id' => $venue->id,
				'name' => $venue->name,
			],
			'documents' => $documents
		]);
	}
}


