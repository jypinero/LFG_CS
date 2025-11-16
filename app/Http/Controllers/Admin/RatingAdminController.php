<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventPlayerRating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RatingAdminController extends Controller
{
	public function listByEvent(int $eventId)
	{
		$rows = EventPlayerRating::where('event_id', $eventId)->orderByDesc('id')->get();
		return response()->json($rows);
	}

	public function leaderboard(Request $request)
	{
		// simple leaderboard by average stars across timeframe
		$query = EventPlayerRating::query();
		if ($request->filled('date_from')) {
			$query->whereDate('created_at', '>=', $request->get('date_from'));
		}
		if ($request->filled('date_to')) {
			$query->whereDate('created_at', '<=', $request->get('date_to'));
		}
		$rows = $query->select('ratee_id', DB::raw('AVG(stars) as avg_stars'), DB::raw('COUNT(*) as votes'))
			->groupBy('ratee_id')
			->orderByDesc('avg_stars')
			->orderByDesc('votes')
			->limit(100)
			->get();
		return response()->json($rows);
	}
}


