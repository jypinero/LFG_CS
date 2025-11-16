<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlayerBan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Requests\Admin\Users\StoreUserRequest;
use App\Http\Requests\Admin\Users\UpdateUserRequest;
use App\Http\Requests\Admin\Users\BanUserRequest;
use Illuminate\Support\Facades\Hash;

class UserAdminController extends Controller
{
	public function index(Request $request)
	{
		$query = User::query()->with('role');

		if ($request->filled('role')) {
			$role = Role::where('name', $request->get('role'))->first();
			if ($role) {
				$query->where('role_id', $role->id);
			}
		}
		if ($request->filled('q')) {
			$q = $request->get('q');
			$query->where(function ($sub) use ($q) {
				$sub->where('email', 'like', '%'.$q.'%')
					->orWhere('username', 'like', '%'.$q.'%')
					->orWhere('first_name', 'like', '%'.$q.'%')
					->orWhere('last_name', 'like', '%'.$q.'%');
			});
		}
		if ($request->filled('date_from')) {
			$query->where('created_at', '>=', $request->date('date_from'));
		}
		if ($request->filled('date_to')) {
			$query->where('created_at', '<=', $request->date('date_to'));
		}

		$perPage = min(max((int) $request->get('per_page', 20), 1), 100);
		$users = $query->orderByDesc('id')->paginate($perPage);
		return response()->json($users);
	}

	public function show(int $id)
	{
		$user = User::with(['role'])->findOrFail($id);
		return response()->json($user);
	}

	public function store(StoreUserRequest $request)
	{
		$data = $request->validated();
		$roleId = $data['role_id'] ?? null;
		if (!$roleId && !empty($data['role'])) {
			$roleId = optional(Role::where('name', $data['role'])->first())->id;
		}
		$user = User::create([
			'first_name' => $data['first_name'],
			'last_name' => $data['last_name'],
			'username' => $data['username'] ?? null,
			'email' => $data['email'],
			'password' => Hash::make($data['password']),
			'role_id' => $roleId,
		]);
		return response()->json($user, 201);
	}

	public function update(UpdateUserRequest $request, int $id)
	{
		$user = User::findOrFail($id);
		$data = $request->validated();
		if (isset($data['password'])) {
			$data['password'] = Hash::make($data['password']);
		}
		if (array_key_exists('role', $data) && $data['role'] && empty($data['role_id'])) {
			$data['role_id'] = optional(Role::where('name', $data['role'])->first())->id;
		}
		unset($data['role']);
		$user->update($data);
		return response()->json($user);
	}

	public function destroy(int $id)
	{
		$user = User::findOrFail($id);
		$user->delete();
		return response()->json(['message' => 'Deleted']);
	}

	public function ban(BanUserRequest $request, int $id)
	{
		$request->validated();
		$ban = PlayerBan::create([
			'user_id' => $id,
			'banned_by' => $request->user()->id,
			'event_id' => $request->input('event_id'),
			'reason' => $request->input('reason'),
			'ban_type' => $request->input('ban_type'),
			'start_date' => $request->input('start_date'),
			'end_date' => $request->input('end_date'),
		]);
		return response()->json($ban, 201);
	}

	public function unban(int $id)
	{
		PlayerBan::where('user_id', $id)
			->whereNull('end_date')
			->update(['end_date' => now()]);
		return response()->json(['message' => 'Unbanned']);
	}

	public function activity(int $id)
	{
		$user = User::findOrFail($id);
		$summary = [
			'posts' => method_exists($user, 'messagesSent') ? $user->messagesSent()->count() : 0,
			'events_participated' => method_exists($user, 'eventParticipants') ? $user->eventParticipants()->count() : 0,
			'events_checkins' => method_exists($user, 'eventCheckins') ? $user->eventCheckins()->count() : 0,
			'tickets_submitted' => method_exists($user, 'supportTicketsSubmitted') ? $user->supportTicketsSubmitted()->count() : 0,
		];
		return response()->json($summary);
	}
}


