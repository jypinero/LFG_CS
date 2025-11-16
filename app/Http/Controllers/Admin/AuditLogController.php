<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
	public function index(Request $request)
	{
		$query = AuditLog::query()->with('actor');

		if ($request->filled('actor_id')) {
			$query->where('actor_id', $request->integer('actor_id'));
		}
		if ($request->filled('action')) {
			$query->where('action', 'like', '%'.$request->get('action').'%');
		}
		if ($request->filled('entity_type')) {
			$query->where('entity_type', $request->get('entity_type'));
		}
		if ($request->filled('date_from')) {
			$query->where('created_at', '>=', $request->date('date_from'));
		}
		if ($request->filled('date_to')) {
			$query->where('created_at', '<=', $request->date('date_to'));
		}

		$perPage = min(max((int) $request->get('per_page', 20), 1), 100);
		$logs = $query->orderByDesc('id')->paginate($perPage);

		return response()->json($logs);
	}
}


