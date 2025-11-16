<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use Illuminate\Http\Request;
use App\Http\Requests\Admin\Venues\UpdateVenueRequest;
use App\Http\Requests\Admin\Venues\RejectVenueRequest;

class VenueAdminController extends Controller
{
	public function index(Request $request)
	{
		$query = Venue::query();
		if ($request->filled('q')) {
			$q = $request->get('q');
			$query->where(function ($sub) use ($q) {
				$sub->where('name', 'like', '%'.$q.'%')
					->orWhere('address', 'like', '%'.$q.'%')
					->orWhere('city', 'like', '%'.$q.'%');
			});
		}
		if ($request->filled('status')) {
			if ($request->get('status') === 'closed') {
				$query->where('is_closed', true);
			} elseif ($request->get('status') === 'active') {
				$query->where('is_closed', false);
			}
		}
		$perPage = min(max((int) $request->get('per_page', 20), 1), 100);
		return response()->json($query->orderByDesc('id')->paginate($perPage));
	}

	public function show(int $id)
	{
		$venue = Venue::with(['photos', 'facilities', 'operatingHours', 'amenities', 'closureDates'])->findOrFail($id);
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
		$venue = Venue::findOrFail($id);
		$venue->update(['verified_at' => now(), 'verification_expires_at' => now()->addYear()]);
		return response()->json(['message' => 'Approved']);
	}

	public function reject(RejectVenueRequest $request, int $id)
	{
		$venue = Venue::findOrFail($id);
		$reason = $request->input('reason', 'Rejected by admin');
		$venue->update(['verified_at' => null, 'verification_expires_at' => null, 'closed_reason' => $reason]);
		return response()->json(['message' => 'Rejected']);
	}
}


