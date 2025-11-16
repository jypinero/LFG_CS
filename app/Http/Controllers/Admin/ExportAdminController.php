<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Venue;
use App\Models\EventPlayerRating;
use Illuminate\Http\Request;

class ExportAdminController extends Controller
{
	protected function csvResponse(iterable $rows, array $headers, string $filename)
	{
		$callback = function () use ($rows, $headers) {
			$out = fopen('php://output', 'w');
			fputcsv($out, $headers);
			foreach ($rows as $row) {
				fputcsv($out, $row);
			}
			fclose($out);
		};
		return response()->streamDownload($callback, $filename, [
			'Content-Type' => 'text/csv',
		]);
	}

	public function users(Request $request)
	{
		$users = User::with('role')->orderBy('id')->get();
		$rows = $users->map(function ($u) {
			return [
				$u->id,
				$u->email,
				$u->username,
				trim(($u->first_name ?? '').' '.($u->last_name ?? '')),
				optional($u->role)->name,
				$u->created_at,
			];
		});
		return $this->csvResponse($rows, ['id', 'email', 'username', 'name', 'role', 'created_at'], 'users.csv');
	}

	public function venues(Request $request)
	{
		$venues = Venue::orderBy('id')->get();
		$rows = $venues->map(fn ($v) => [$v->id, $v->name, $v->address, $v->is_closed ? 'closed' : 'active', $v->created_at]);
		return $this->csvResponse($rows, ['id', 'name', 'address', 'status', 'created_at'], 'venues.csv');
	}

	public function events(Request $request)
	{
		$events = Event::with('venue')->orderBy('id')->get();
		$rows = $events->map(fn ($e) => [$e->id, $e->name, $e->sport, optional($e->venue)->name, $e->date, $e->start_time, $e->end_time]);
		return $this->csvResponse($rows, ['id', 'name', 'sport', 'venue', 'date', 'start_time', 'end_time'], 'events.csv');
	}

	public function tickets(Request $request)
	{
		$tickets = SupportTicket::orderBy('id')->get();
		$rows = $tickets->map(fn ($t) => [$t->id, $t->subject, $t->status, $t->submitted_by, $t->assigned_to, $t->created_at]);
		return $this->csvResponse($rows, ['id', 'subject', 'status', 'submitted_by', 'assigned_to', 'created_at'], 'tickets.csv');
	}

	public function ratings(Request $request)
	{
		$ratings = EventPlayerRating::orderBy('id')->get();
		$rows = $ratings->map(fn ($r) => [$r->id, $r->event_id, $r->rater_id, $r->ratee_id, $r->stars, $r->created_at]);
		return $this->csvResponse($rows, ['id', 'event_id', 'rater_id', 'ratee_id', 'stars', 'created_at'], 'ratings.csv');
	}
}


