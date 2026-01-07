<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlayerBan;
use App\Models\Role;
use App\Models\User;
use App\Models\EntityDocument;
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
		
		// Filter by pro athlete status
		if ($request->filled('is_pro_athlete')) {
			if ($request->get('is_pro_athlete') === 'true' || $request->get('is_pro_athlete') === '1') {
				$query->where('is_pro_athlete', true);
			} else {
				$query->where('is_pro_athlete', false);
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
		$users = $query->orderByDesc('id')->paginate($perPage);
		
		// Add verification info to response
		$users->getCollection()->transform(function ($user) {
			$user->verification_source = $user->verified_by_ai ? 'ai' : ($user->verified_by ? 'manual' : null);
			return $user;
		});
		
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
	
	public function approve(Request $request, int $id)
	{
		$admin = auth()->user();
		$user = User::findOrFail($id);
		
		$request->validate([
			'verification_notes' => 'nullable|string|max:1000',
		]);
		
		$user->update([
			'is_pro_athlete' => true,
			'verified_at' => now(),
			'verified_by' => $admin->id,
			'verification_notes' => $request->input('verification_notes'),
			'verified_by_ai' => false, // Manual approval
		]);
		
		return response()->json([
			'status' => 'success',
			'message' => 'User verified as Pro Athlete successfully',
			'user' => $user->fresh(['verifier:id,username'])
		]);
	}
	
	public function reject(Request $request, int $id)
	{
		$admin = auth()->user();
		$user = User::findOrFail($id);
		
		$request->validate([
			'verification_notes' => 'required|string|max:1000',
		]);
		
		$user->update([
			'is_pro_athlete' => false,
			'verified_at' => null,
			'verified_by' => $admin->id,
			'verification_notes' => $request->input('verification_notes'),
			'verified_by_ai' => false, // Manual rejection
		]);
		
		return response()->json([
			'status' => 'success',
			'message' => 'Pro Athlete verification rejected',
			'user' => $user->fresh(['verifier:id,username'])
		]);
	}
	
	public function documents(int $id)
	{
		$user = User::findOrFail($id);
		
		$documents = EntityDocument::where('documentable_type', User::class)
			->where('documentable_id', $id)
			->where('document_category', 'athlete_certification')
			->with('verifier:id,username')
			->orderBy('created_at', 'desc')
			->get();
		
		return response()->json([
			'status' => 'success',
			'user' => [
				'id' => $user->id,
				'username' => $user->username,
				'email' => $user->email,
				'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
			],
			'documents' => $documents
		]);
	}
	
	public function statistics()
	{
		$stats = [
			'total_users' => User::count(),
			'pro_athletes' => User::where('is_pro_athlete', true)->count(),
			'verified_by_ai' => User::where('verified_by_ai', true)->count(),
			'verified_manually' => User::where('is_pro_athlete', true)
				->where('verified_by_ai', false)
				->whereNotNull('verified_at')
				->count(),
		];
		
		return response()->json([
			'status' => 'success',
			'statistics' => $stats
		]);
	}
}


