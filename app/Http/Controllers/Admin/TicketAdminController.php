<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use App\Http\Requests\Admin\Tickets\StoreTicketRequest;
use App\Http\Requests\Admin\Tickets\UpdateTicketRequest;

class TicketAdminController extends Controller
{
	public function index(Request $request)
	{
		$query = SupportTicket::query()->with(['submitter', 'assignee']);
		if ($request->filled('status')) {
			$query->where('status', $request->get('status'));
		}
		if ($request->filled('assignee')) {
			$query->where('assigned_to', $request->integer('assignee'));
		}
		if ($request->filled('q')) {
			$q = $request->get('q');
			$query->where(function ($sub) use ($q) {
				$sub->where('subject', 'like', '%'.$q.'%')
					->orWhere('description', 'like', '%'.$q.'%');
			});
		}
		if ($request->filled('date_from')) {
			$query->where('created_at', '>=', $request->date('date_from'));
		}
		if ($request->filled('date_to')) {
			$query->where('created_at', '<=', $request->date('date_to'));
		}
		$perPage = min(max((int) $request->get('per_page', 20), 1), 100);
		return response()->json($query->orderByDesc('id')->paginate($perPage));
	}

	public function show(int $id)
	{
		$ticket = SupportTicket::with(['submitter', 'assignee'])->findOrFail($id);
		return response()->json($ticket);
	}

	public function store(StoreTicketRequest $request)
	{
		$data = $request->validated();
		$data['status'] = 'open';
		$ticket = SupportTicket::create($data);
		return response()->json($ticket, 201);
	}

	public function update(UpdateTicketRequest $request, int $id)
	{
		$ticket = SupportTicket::findOrFail($id);
		$data = $request->validated();
		$ticket->update($data);
		return response()->json($ticket);
	}

	public function close(int $id)
	{
		$ticket = SupportTicket::findOrFail($id);
		$ticket->update(['status' => 'closed']);
		return response()->json(['message' => 'Closed']);
	}
}


