<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
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
use App\Mail\PasswordResetMail;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Traits\HandlesImageCompression;

class AuthController extends Controller
{
    use HandlesImageCompression;
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['login', 'register', 'getRoles', 'getSports', 'checkAvailability', 'forgotPassword', 'resetPassword']]);
    }

    /**
     * Register a new user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        // Get role to determine if sports are required
        $roleId = $request->input('role_id');
        $role = Role::find($roleId);
        
        // Define roles that require sports
        $rolesRequiringSports = ['athletes', 'trainer'];
        $requiresSports = $role && in_array(strtolower($role->name), $rolesRequiringSports);
        
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
            // Make sports conditional based on role
            'sports' => $requiresSports ? 'required|array|min:1' : 'nullable|array',
            'sports.*.id' => $requiresSports ? 'required|exists:sports,id' : 'nullable|exists:sports,id',
            'sports.*.level' => $requiresSports ? 'required|in:beginner,competitive,professional' : 'nullable|in:beginner,competitive,professional',
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
                // Compress and store profile photo (max 800x800 for profile pics)
                $profilePhotoPath = $this->compressAndStoreImage($file, 'userpfp', 800, 800, 85);
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

            // Only process sports if provided
            $sports = $request->sports ?? [];
            if (!empty($sports) && count($sports) > 0) {
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
            } else {
                // Create user profile without sports for roles that don't require them
                UserProfile::create([
                    'user_id' => $user->id,
                    // main_sport_id and main_sport_level will be null
                ]);
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

        // Send welcome notification (async, won't block login)
        try {
            $welcomeService = app(\App\Services\WelcomeNotificationService::class);
            $welcomeService->sendWelcomeNotification($user->id);
        } catch (\Exception $e) {
            // Log but don't fail login
            \Log::error('Failed to send welcome notification on login', ['error' => $e->getMessage()]);
        }

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
        try {
            $user = Auth::guard('api')->user();
            
            // Check if user exists in database
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated',
                    'code' => 'USER_NOT_AUTHENTICATED'
                ], 401);
            }
            
            // Verify user still exists in database (in case user was deleted after token was issued)
            $userExists = User::find($user->id);
            if (!$userExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User account not found. Please log in again.',
                    'code' => 'USER_NOT_FOUND',
                    'user_id' => $user->id
                ], 404);
            }
            
            // Get team memberships with team details
            $memberships = TeamMember::with('team')
                ->where('user_id', $user->id)
                ->get();

            // Current teams (active memberships)
            $currentTeams = $memberships->filter(function($m) {
                return ($m->is_active === true) || ($m->roster_status === 'active');
            })->values()->map(function($m) {
                return [
                    'team_id' => $m->team_id,
                    'team_photo' => $m->team && $m->team->team_photo ? Storage::url($m->team->team_photo) : null,
                    'team_name' => $m->team->name ?? null,
                    'role' => $m->role,
                    'position' => $m->position,
                    'is_active' => (bool) $m->is_active,
                    'roster_status' => $m->roster_status,
                    'joined_at' => $m->joined_at ?? $m->created_at,
                ];
            })->filter(function($team) {
                // Only include teams that actually exist (team relationship loaded)
                return $team['team_name'] !== null;
            });

            // Past teams (left, removed, or inactive)
            $pastTeams = $memberships->filter(function($m) {
                return ($m->roster_status === 'left' || $m->roster_status === 'removed')
                    || ($m->is_active === false && $m->roster_status !== 'active');
            })->values()->map(function($m) {
                return [
                    'team_id' => $m->team_id,
                    'team_photo' => $m->team && $m->team->team_photo ? Storage::url($m->team->team_photo) : null,
                    'team_name' => $m->team->name ?? null,
                    'role' => $m->role,
                    'position' => $m->position,
                    'roster_status' => $m->roster_status,
                    'joined_at' => $m->joined_at ?? $m->created_at,
                    'removed_at' => $m->removed_at ?? $m->updated_at,
                ];
            })->filter(function($team) {
                // Only include teams that actually exist (team relationship loaded)
                return $team['team_name'] !== null;
            });

            return response()->json([
                'status' => 'success',
                'user' => $user->load('role', 'userProfile.mainSport', 'userCertifications', 'userDocuments', 'userAdditionalSports.sport'),
                'has_team' => TeamMember::where('user_id', $user->id)->exists(),
                'current_teams' => $currentTeams,
                'past_teams' => $pastTeams,
            ]);
        } catch (\Tymon\JWTAuth\Exceptions\UserNotDefinedException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'User account not found. Please log in again.',
                'code' => 'USER_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Error in /me endpoint: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Authentication error',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
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

    /**
     * Search user by exact username (for messaging)
     * GET /api/users/search?username=exact_username
     */
    public function searchUsers(Request $request)
    {
        $request->validate([
            'username' => 'required|string|min:1|max:100',
        ]);

        $username = $request->input('username');
        $currentUser = auth()->user();

        // Exact username match only
        $user = User::where('username', $username)
            ->where('id', '!=', $currentUser->id) // Exclude current user
            ->select('id', 'username', 'first_name', 'last_name', 'profile_photo', 'role_id')
            ->with('role:id,name')
            ->first();

        if (!$user) {
            return response()->json([
                'status' => 'success',
                'user' => null,
                'message' => 'User not found'
            ]);
        }

        return response()->json([
            'status' => 'success',
            'user' => $user
        ]);
    }

    public function showprofile($id)
    {
        $currentUser = auth()->user();
        $isOwnProfile = $currentUser && $currentUser->id == $id;
        
        // Only load sensitive documents if viewing own profile
        $relations = [
            'role',
            'userProfile.mainSport',
            'userCertifications',
            'userAdditionalSports.sport'
        ];
        
        if ($isOwnProfile) {
            $relations[] = 'userDocuments';
        }
        
        $user = \App\Models\User::with($relations)->find($id);

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
                try {
                    $eventDate = $event->date ? \Carbon\Carbon::parse($event->date)->toDateString() : now()->toDateString();
                } catch (\Throwable $e) {
                    $eventDate = now()->toDateString();
                }
                $start = \Carbon\Carbon::parse($eventDate . ' ' . $event->start_time);
                $end = \Carbon\Carbon::parse($eventDate . ' ' . $event->end_time);
                $diff = $start->diffInMinutes($end) / 60;
                \Log::info([
                    'event_id' => $event->id ?? null,
                    'start' => $start,
                    'end' => $end,
                    'diff' => $diff,
                    'start_time' => $event->start_time ?? null,
                    'end_time' => $event->end_time ?? null,
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

        // Current and past teams
        $memberships = \App\Models\TeamMember::with('team')
            ->where('user_id', $id)
            ->get();

        $currentTeams = $memberships->filter(function($m) {
            return ($m->is_active === true) || ($m->roster_status === 'active');
        })->values()->map(function($m) {
            return [
                'team_id' => $m->team_id,
                'team_photo' => $m->team->team_photo ? \Storage::url($m->team->team_photo) : null,
                'team_name' => $m->team->name ?? null,
                'role' => $m->role,
                'is_active' => (bool) $m->is_active,
                'roster_status' => $m->roster_status,
                'joined_at' => $m->joined_at ?? $m->created_at,
            ];
        });

        $pastTeams = $memberships->filter(function($m) {
            return ($m->roster_status === 'left' || $m->roster_status === 'removed')
                || ($m->is_active === false && $m->roster_status !== 'active');
        })->values()->map(function($m) {
            return [
                'team_id' => $m->team_id,
                'team_photo' => $m->team->team_photo ? \Storage::url($m->team->team_photo) : null,
                'team_name' => $m->team->name ?? null,
                'role' => $m->role,
                'roster_status' => $m->roster_status,
                'joined_at' => $m->joined_at ?? $m->created_at,
                'removed_at' => $m->removed_at ?? $m->updated_at,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'username' => $user->username,
                'city' => $user->city,
                'role' => $user->role->name,
                'province' => $user->province,
                'profile_photo' => $user->profile_photo ? \Storage::url($user->profile_photo) : null,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'is_developer' => $user->is_developer ?? false,
                'bio' => optional($user->userProfile)->bio,
                'sports' => $sports,
                'games_played' => $gamesPlayed,
                'total_hours_played' => round($totalHours, 2),
                'rating_score' => $user->rating_score ?? null,
                'rating_star' => $user->rating_star ?? null,
                'rating_count' => $user->rating_count ?? null,
                'recent_players' => $recentPlayers,
                'posts' => $posts,
                'current_teams' => $currentTeams,
                'past_teams' => $pastTeams,
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
     * Validate session - check if token is valid and user exists
     * Used by frontend to verify session before redirecting
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateSession()
    {
        try {
            $userId = auth()->id();
            
            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No valid session',
                    'code' => 'NO_SESSION',
                    'valid' => false
                ], 401);
            }
            
            // Verify user exists in database
            $user = User::find($userId);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User account not found',
                    'code' => 'USER_NOT_FOUND',
                    'valid' => false,
                    'user_id' => $userId
                ], 404);
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Session is valid',
                'valid' => true,
                'user_id' => $userId
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Session validation failed',
                'code' => 'VALIDATION_ERROR',
                'valid' => false
            ], 401);
        }
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
    
        // Parse additional_sports if it's a JSON string (from FormData)
        $requestData = $request->all();
        if ($request->has('additional_sports') && is_string($request->additional_sports)) {
            $decoded = json_decode($request->additional_sports, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $requestData['additional_sports'] = $decoded;
                // Merge the decoded data back into the request
                $request->merge(['additional_sports' => $decoded]);
            } else {
                // If JSON decode fails, set to empty array to avoid validation errors
                $requestData['additional_sports'] = [];
                $request->merge(['additional_sports' => []]);
            }
        }
    
        $validator = \Validator::make($requestData, [
            'first_name' => 'nullable|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'username' => 'nullable|string|max:255|unique:users,username,' . $user->id,
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'bio' => 'nullable|string|max:1000',
            'occupation' => 'nullable|string|max:255',
            'main_sport_id' => 'nullable|exists:sports,id',
            'main_sport_level' => 'nullable|in:beginner,competitive,professional',
            'additional_sports' => 'nullable|array',
            'additional_sports.*.id' => 'required_with:additional_sports|exists:sports,id',
            'additional_sports.*.level' => 'required_with:additional_sports|in:beginner,competitive,professional',
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
            $user->fill($request->only(['first_name', 'middle_name', 'last_name', 'username', 'city', 'province']));
    
            // ✅ Handle profile photo upload
            if ($request->hasFile('profile_photo')) {
                $oldPhoto = $user->profile_photo;
    
                // Delete old photo if it exists
                if ($oldPhoto && \Storage::disk('public')->exists($oldPhoto)) {
                    \Storage::disk('public')->delete($oldPhoto);
                }
    
                $file = $request->file('profile_photo');
                // Compress and store profile photo (max 800x800 for profile pics)
                $path = $this->compressAndStoreImage($file, 'userpfp', 800, 800, 85);
                $user->profile_photo = $path;
            }
    
            $user->save();
    
            // ✅ Get or create UserProfile
            $userProfile = $user->userProfile ?: new \App\Models\UserProfile(['user_id' => $user->id]);
            
            // Update bio
            if ($request->filled('bio')) {
                $userProfile->bio = $request->bio;
            }
            
            // Update occupation
            if ($request->filled('occupation')) {
                $userProfile->occupation = $request->occupation;
            }
            
            // Update main sport
            if ($request->filled('main_sport_id')) {
                $userProfile->main_sport_id = $request->main_sport_id;
                $userProfile->main_sport_level = $request->input('main_sport_level', 'beginner');
            }
            
            $userProfile->save();
    
            // ✅ Handle additional sports
            if ($request->has('additional_sports')) {
                // Delete existing additional sports
                \App\Models\UserAdditionalSport::where('user_id', $user->id)->delete();
                
                // Add new additional sports (exclude main sport if it's in the list)
                $additionalSports = $request->additional_sports;
                $mainSportId = $userProfile->main_sport_id;
                
                if (is_array($additionalSports) && count($additionalSports) > 0) {
                    foreach ($additionalSports as $sport) {
                        // Don't add if it's the same as main sport
                        if (isset($sport['id']) && $sport['id'] != $mainSportId) {
                            \App\Models\UserAdditionalSport::create([
                                'user_id' => $user->id,
                                'sport_id' => $sport['id'],
                                'level' => $sport['level'] ?? 'beginner',
                            ]);
                        }
                    }
                }
            }
    
            // ✅ Include profile photo full URL and load relationships for frontend
            $userData = $user->load([
                'userProfile.mainSport',
                'userAdditionalSports.sport'
            ])->toArray();
            
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

    /**
     * Send password reset link to user's email.
     *
     * @param  \App\Http\Requests\Auth\ForgotPasswordRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'We could not find a user with that email address.',
                ], 404);
            }

            // Use Laravel's password reset repository to create token properly
            $tokenRepository = new DatabaseTokenRepository(
                DB::connection(),
                app('hash')->driver(),
                config('auth.passwords.users.table', 'password_reset_tokens'),
                config('app.key'),
                config('auth.passwords.users.expire', 10080)
            );
            $token = $tokenRepository->create($user);

            // Send custom email with token
            try {
                Mail::to($user->email)->send(new PasswordResetMail(
                    $token,
                    $request->email,
                    config('auth.passwords.users.expire', 10080)
                ));
            } catch (\Exception $mailException) {
                // Log mail error but don't fail the request
                \Log::error('Failed to send password reset email: ' . $mailException->getMessage());
                // Still return success since token was generated
                // In production, you might want to handle this differently
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Password reset link has been sent to your email address.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send password reset link.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reset user password using token.
     *
     * @param  \App\Http\Requests\Auth\ResetPasswordRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        try {
            $status = Password::broker()->reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    $user->password = Hash::make($password);
                    $user->save();
                }
            );

            if ($status === Password::PASSWORD_RESET) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Password has been reset successfully.',
                ]);
            }

            if ($status === Password::INVALID_TOKEN) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid or expired reset token.',
                ], 400);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to reset password. Please try again.',
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reset password.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Change password for authenticated user.
     *
     * @param  \App\Http\Requests\Auth\ChangePasswordRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        try {
            $user = Auth::guard('api')->user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated',
                ], 401);
            }

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Current password is incorrect.',
                ], 422);
            }

            // Update password
            $user->password = Hash::make($request->password);
            $user->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Password changed successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to change password.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}