<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\Role;
use App\Models\UserProfile;
use App\Models\UserAdditionalSport;
use App\Models\Event;
use App\Models\Post;
use App\Models\Sport;
use App\Models\EventParticipant;
use App\Models\TeamMember;
use App\Models\Team;

class AuthController extends Controller
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['login', 'register', 'getRoles', 'getSports', 'checkAvailability']]);
    }

    /**
     * Register a new user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'birthday' => 'required|date',
            'sex' => 'required|in:male,female,other',
            'contact_number' => 'required|string|max:255',
            'barangay' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'province' => 'required|string|max:255',
            'zip_code' => 'required|string|max:255',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'role_id' => 'required|exists:roles,id',
            'sports' => 'required|array|min:1',
            'sports.*.id' => 'required|exists:sports,id',
            'sports.*.level' => 'required|in:beginner,competitive,professional',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Handle profile photo upload
            $profilePhotoPath = null;
            if ($request->hasFile('profile_photo')) {
                $file = $request->file('profile_photo');
                $fileName = time() . '_' . $file->getClientOriginalName();
                $profilePhotoPath = $file->storeAs('userpfp', $fileName, 'public');
            }

            $user = User::create([
                'first_name' => $request->first_name,
                'middle_name' => $request->middle_name,
                'last_name' => $request->last_name,
                'username' => $request->username,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'birthday' => $request->birthday,
                'sex' => $request->sex,
                'contact_number' => $request->contact_number,
                'barangay' => $request->barangay,
                'city' => $request->city,
                'province' => $request->province,
                'zip_code' => $request->zip_code,
                'role_id' => $request->role_id,
                'profile_photo' => $profilePhotoPath,
            ]);

            $sports = $request->sports;
            $mainSport = $sports[0];

            // Create user profile with main sport
            $userProfile = UserProfile::create([
                'user_id' => $user->id,
                'main_sport_id' => $mainSport['id'],
                'main_sport_level' => $mainSport['level'],
                // Add other profile fields if needed
            ]);

            // Save additional sports (if any)
            if (count($sports) > 1) {
                $additionalSports = array_slice($sports, 1);
                foreach ($additionalSports as $sport) {
                    UserAdditionalSport::create([
                        'user_id' => $user->id,
                        'sport_id' => $sport['id'],
                        'level' => $sport['level'],
                    ]);
                }
            }

            $token = Auth::guard('api')->login($user);

            return response()->json([
                'status' => 'success',
                'message' => 'User registered successfully',
                'user' => $user->load('userProfile', 'userProfile.mainSport', 'userAdditionalSports.sport'),
                'authorization' => [
                    'token' => $token,
                    'type' => 'bearer',
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

     /**
     * Live availability + optional email validity check.
     * Query params: field (email|username|contact_number), value
     * Example: GET /api/auth/check-availability?field=email&value=user@example.com
     */
    public function checkAvailability(Request $request)
    {
        $field = $request->query('field');
        $value = trim($request->query('value', ''));

        if (! in_array($field, ['email', 'username', 'contact_number'])) {
            return response()->json(['status' => 'error', 'message' => 'Invalid field'], 400);
        }

        if ($value === '') {
            return response()->json(['status' => 'error', 'message' => 'Value is required'], 400);
        }

        $exists = User::where($field, $value)->exists();

        $response = [
            'status' => 'success',
            'field' => $field,
            'value' => $value,
            'available' => ! $exists,
        ];

        // If checking email, add format + DNS checks (best-effort)
        if ($field === 'email') {
            $validFormat = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            $hasMx = false;
            $hasA = false;
            $deliverable = false;

            if ($validFormat) {
                $domain = explode('@', $value)[1] ?? null;
                if ($domain) {
                    if (function_exists('idn_to_ascii')) {
                        $domain = idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46) ?: $domain;
                    }
                    $hasMx = checkdnsrr($domain, 'MX');
                    $hasA = checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
                }
                $deliverable = ($hasMx || $hasA);
            }

            $response['valid_format'] = $validFormat;
            $response['has_mx'] = $hasMx;
            $response['has_a_record'] = $hasA;
            $response['deliverable'] = $deliverable;
        }

        return response()->json($response, 200);
    }

    /**
     * Get a JWT via given credentials.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find user by username
        $user = User::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Generate token using JWT with api guard
        $token = Auth::guard('api')->login($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'authorization' => [
                'token' => $token,
                'type' => 'bearer',
            ],
            'user' => $user->load('role', 'userProfile', 'userAdditionalSports.sport'),
            'has_team' => TeamMember::where('user_id', $user->id)->exists(),
            'teams' => Team::whereIn('id', TeamMember::where('user_id', $user->id)->pluck('team_id'))
                ->get(['id', 'name'])
                ->map(function ($t) { return ['id' => $t->id, 'name' => $t->name]; })
        ]);
    }

    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        $user = Auth::guard('api')->user();
        
        return response()->json([
            'status' => 'success',
            'user' => $user->load('role', 'userProfile', 'userCertifications', 'userAdditionalSports.sport'),
            'has_team' => TeamMember::where('user_id', $user->id)->exists(),
            'teams' => Team::whereIn('id', TeamMember::where('user_id', $user->id)->pluck('team_id'))
                ->get(['id', 'name'])
                ->map(function ($t) { return ['id' => $t->id, 'name' => $t->name]; })
        ]);
    }

    public function myprofile()
    {
        $user = \Auth::guard('api')->user();
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not authenticated',
            ], 401);
        }
        // Reuse the showprofile logic
        return $this->showprofile($user->id);
    }

    public function showprofileByUsername($username)
    {
        $user = \App\Models\User::where('username', $username)->first();
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }
        return $this->showprofile($user->id);
    }

    public function showprofile($id)
    {
        $user = \App\Models\User::with([
            'role',
            'userProfile.mainSport',
            'userCertifications',
            'userAdditionalSports.sport'
        ])->find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        // Posts
        $posts = \App\Models\Post::where('author_id', $id)->latest()->get();

        // Events participated
        $eventParticipants = \App\Models\EventParticipant::where('user_id', $id)->with('event')->get();
        $eventIds = $eventParticipants->pluck('event_id');

        // Games played
        $gamesPlayed = $eventParticipants->count();

        // Total hours played (fix: use event date + time, only add if end > start)
        $totalHours = 0;
        foreach ($eventParticipants as $ep) {
            $event = $ep->event;
            if ($event && $event->start_time && $event->end_time) {
                $eventDate = $event->date ?? now()->toDateString();
                $start = \Carbon\Carbon::parse($eventDate . ' ' . $event->start_time);
                $end = \Carbon\Carbon::parse($eventDate . ' ' . $event->end_time);
                $diff = $start->diffInMinutes($end) / 60;
                // Debug output
                \Log::info([
                    'event_id' => $event->id,
                    'start' => $start,
                    'end' => $end,
                    'diff' => $diff,
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                ]);
                if ($diff > 0) {
                    $totalHours += $diff;
                }
            } else {
                \Log::info([
                    'event_id' => $event ? $event->id : null,
                    'reason' => 'Missing event or times',
                    'start_time' => $event->start_time ?? null,
                    'end_time' => $event->end_time ?? null,
                ]);
            }
        }

        // All sports (main + additional)
        $sports = [];
        if ($user->userProfile && $user->userProfile->mainSport) {
            $sports[] = [
                'id' => $user->userProfile->mainSport->id,
                'name' => $user->userProfile->mainSport->name,
                'level' => $user->userProfile->main_sport_level,
                'main' => true,
            ];
        }
        foreach ($user->userAdditionalSports as $additional) {
            if ($additional->sport) {
                $sports[] = [
                    'id' => $additional->sport->id,
                    'name' => $additional->sport->name,
                    'level' => $additional->level,
                    'main' => false,
                ];
            }
        }

        // Recently played with (distinct users from same events, excluding self)
        $recentPlayers = \App\Models\EventParticipant::whereIn('event_id', $eventIds)
            ->where('user_id', '!=', $id)
            ->with('user')
            ->get()
            ->pluck('user')
            ->unique('id')
            ->values()
            ->map(function($u) {
                return [
                    'id' => $u->id,
                    'username' => $u->username,
                    'first_name' => $u->first_name,
                    'last_name' => $u->last_name,
                    'profile_photo' => $u->profile_photo ? \Storage::url($u->profile_photo) : null,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'username' => $user->username,
                'city' => $user->city,
                'province' => $user->province,
                'profile_photo' => $user->profile_photo ? \Storage::url($user->profile_photo) : null,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'bio' => optional($user->userProfile)->bio,
                'sports' => $sports,
                'games_played' => $gamesPlayed,
                'total_hours_played' => round($totalHours, 2),
                'recent_players' => $recentPlayers,
                'posts' => $posts,
            ],
        ]);
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        Auth::guard('api')->logout();

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully logged out',
        ]);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        $token = Auth::guard('api')->refresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Token refreshed successfully',
            'authorization' => [
                'token' => $token,
                'type' => 'bearer',
            ]
        ]);
    }

    /**
     * Get available roles for registration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRoles()
    {
        $roles = Role::all();

        return response()->json([
            'status' => 'success',
            'roles' => $roles
        ]);
    }

    public function getSports(){

        $sports = Sport::all();

        return response()->json([
            'status' => 'success',
            'sports' => $sports
        ]);
    }
    
    public function updateProfile(Request $request)
    {
        $user = Auth::guard('api')->user(); // Authenticated user via API guard

        $validator = \Validator::make($request->all(), [
            'username' => 'nullable|string|max:255|unique:users,username,' . $user->id,
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'bio' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // ✅ Update simple user fields
            $user->fill($request->only(['username', 'city', 'province']));

            // ✅ Handle profile photo upload
            if ($request->hasFile('profile_photo')) {
                $oldPhoto = $user->profile_photo;

                // Delete old photo if it exists
                if ($oldPhoto && \Storage::disk('public')->exists($oldPhoto)) {
                    \Storage::disk('public')->delete($oldPhoto);
                }

                $file = $request->file('profile_photo');
                $fileName = time() . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('userpfp', $fileName, 'public');
                $user->profile_photo = $path;
            }

            $user->save();

            // ✅ Update or create UserProfile for bio
            if ($request->filled('bio')) {
                $userProfile = $user->userProfile ?: new \App\Models\UserProfile(['user_id' => $user->id]);
                $userProfile->bio = $request->bio;
                $userProfile->save();
            }

            // ✅ Include profile photo full URL for frontend
            $userData = $user->load('userProfile')->toArray();
            $userData['profile_photo_url'] = $user->profile_photo
                ? asset('storage/' . $user->profile_photo)
                : null;

            return response()->json([
                'status' => 'success',
                'message' => 'Profile updated successfully!',
                'user' => $userData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update profile',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}