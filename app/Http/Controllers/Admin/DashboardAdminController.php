<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Venue;
use App\Models\EventPlayerRating;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardAdminController extends Controller
{
	public function overview(Request $request)
	{
		$now = Carbon::now();
		$thirtyDaysAgo = $now->copy()->subDays(30)->startOfDay();

		$totals = [
			'users' => User::count(),
			'venues' => Venue::count(),
			'events_upcoming' => Event::whereDate('date', '>', $now->toDateString())->count(),
			'events_active' => Event::whereDate('date', $now->toDateString())->count(),
			'tickets_open' => SupportTicket::whereIn('status', ['open', 'pending'])->count(),
		];

		$usersTrend = User::select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(*) as c'))
			->where('created_at', '>=', $thirtyDaysAgo)
			->groupBy(DB::raw('DATE(created_at)'))
			->orderBy('d')
			->get();

		$eventsTrend = Event::select(DB::raw('date as d'), DB::raw('COUNT(*) as c'))
			->where('date', '>=', $thirtyDaysAgo->toDateString())
			->groupBy('date')
			->orderBy('d')
			->get();

		$ticketsTrend = SupportTicket::select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(*) as c'))
			->where('created_at', '>=', $thirtyDaysAgo)
			->groupBy(DB::raw('DATE(created_at)'))
			->orderBy('d')
			->get();

		return response()->json([
			'totals' => $totals,
			'trends' => [
				'users' => $usersTrend,
				'events' => $eventsTrend,
				'tickets' => $ticketsTrend,
			],
		]);
	}

	public function events(Request $request)
	{
		$from = $request->get('date_from');
		$to = $request->get('date_to');
		$query = Event::query();
		if ($from) $query->where('date', '>=', $from);
		if ($to) $query->where('date', '<=', $to);

		$perSport = $query->clone()->select('sport', DB::raw('COUNT(*) as c'))->groupBy('sport')->get();

		return response()->json([
			'per_sport' => $perSport,
		]);
	}

	public function venues(Request $request)
	{
		$topVenues = Venue::select('id', 'name')
			->withCount(['events'])
			->orderByDesc('events_count')
			->limit(10)->get();
		$closures = Venue::where('is_closed', true)->count();
		return response()->json([
			'top_venues' => $topVenues,
			'closures' => $closures,
		]);
	}

	public function support(Request $request)
	{
		$backlog = SupportTicket::whereIn('status', ['open', 'pending'])->count();
		$resolved = SupportTicket::where('status', 'resolved')->count();
		return response()->json([
			'backlog' => $backlog,
			'resolved' => $resolved,
		]);
	}

	public function ratings(Request $request)
	{
		$from = $request->get('date_from');
		$to = $request->get('date_to');
		$query = EventPlayerRating::query();
		if ($from) $query->whereDate('created_at', '>=', $from);
		if ($to) $query->whereDate('created_at', '<=', $to);

		$avg = $query->clone()->avg('stars');
		$count = $query->clone()->count();
		$byStars = $query->clone()->select('stars', DB::raw('COUNT(*) as c'))->groupBy('stars')->orderBy('stars')->get();

		return response()->json([
			'average' => (float) $avg,
			'count' => $count,
			'distribution' => $byStars,
		]);
	}
}


