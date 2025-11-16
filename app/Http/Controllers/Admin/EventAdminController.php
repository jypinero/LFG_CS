<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use App\Http\Requests\Admin\Events\UpdateEventRequest;

class EventAdminController extends Controller
{
	public function index(Request $request)
	{
		$query = Event::query()->with(['venue', 'creator']);
		if ($request->filled('q')) {
			$q = $request->get('q');
			$query->where('name', 'like', '%'.$q.'%');
		}
		if ($request->filled('sport')) {
			$query->where('sport', $request->get('sport'));
		}
		if ($request->filled('venue_id')) {
			$query->where('venue_id', $request->integer('venue_id'));
		}
		if ($request->filled('date_from')) {
			$query->where('date', '>=', $request->get('date_from'));
		}
		if ($request->filled('date_to')) {
			$query->where('date', '<=', $request->get('date_to'));
		}
		$perPage = min(max((int) $request->get('per_page', 20), 1), 100);
		return response()->json($query->orderByDesc('id')->paginate($perPage));
	}

	public function show(int $id)
	{
		$event = Event::with(['venue', 'creator', 'participants', 'teams', 'scores', 'checkins'])->findOrFail($id);
		$stats = [
			'participants' => $event->participants()->count(),
			'checkins' => $event->checkins()->count(),
			'teams' => $event->teams()->count(),
			'scores' => $event->scores()->count(),
			'status' => $event->status,
		];
		return response()->json(['event' => $event, 'stats' => $stats]);
	}

	public function participants(int $id)
	{
		$event = Event::findOrFail($id);
		$list = $event->participants()->with('user')->get();
		return response()->json($list);
	}

	public function scores(int $id)
	{
		$event = Event::findOrFail($id);
		$list = $event->scores()->get();
		return response()->json($list);
	}

	public function update(UpdateEventRequest $request, int $id)
	{
		$event = Event::findOrFail($id);
		$data = $request->validated();
		if (array_key_exists('is_approved', $data)) {
			$data['approved_at'] = $data['is_approved'] ? now() : null;
		}
		$event->update($data);
		return response()->json($event);
	}
}


