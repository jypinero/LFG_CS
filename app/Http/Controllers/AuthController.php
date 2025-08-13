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

class AuthController extends Controller
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['login', 'register', 'getRoles']]);
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
            'user' => $user->load('role', 'userProfile')
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
            'user' => $user->load('role', 'userProfile', 'userCertifications', 'userAdditionalSports.sport')
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

    /**
     * Update user profile photo.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfilePhoto(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'profile_photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = Auth::user();

            // Delete old profile photo if exists
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }

            // Upload new profile photo
            $file = $request->file('profile_photo');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $profilePhotoPath = $file->storeAs('userpfp', $fileName, 'public');

            $user->update(['profile_photo' => $profilePhotoPath]);

            return response()->json([
                'status' => 'success',
                'message' => 'Profile photo updated successfully',
                'profile_photo' => Storage::url($profilePhotoPath)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update profile photo',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}