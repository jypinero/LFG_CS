<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\UserProfile;
use App\Models\UserAdditionalSport;
use App\Http\Requests\Auth\CompleteSocialRegistrationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Tymon\JWTAuth\Facades\JWTAuth;

class SocialiteController extends Controller
{
    /**
     * Redirect to Google OAuth.
     */
    public function redirectToGoogle()
    {
        $redirectUri = config('services.google.redirect') 
            ?: url('/api/auth/google/callback');
        
        return Socialite::driver('google')
            ->stateless()
            ->scopes(['openid', 'profile', 'email'])
            ->redirectUrl($redirectUri)
            ->redirect();
    }

    /**
     * Handle Google OAuth callback.
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            // Get frontend URL and check if this is a browser request
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $isBrowserRequest = !$request->wantsJson() && !$request->expectsJson() && !$request->ajax();
            
            // Check for OAuth errors
            if ($request->has('error')) {
                $error = $request->get('error');
                
                // If browser request, redirect to frontend error page
                if ($isBrowserRequest) {
                    return redirect("{$frontendUrl}/auth/google/callback?error=" . urlencode($error));
                }
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'OAuth authentication was cancelled or failed.',
                    'error' => $error,
                ], 400);
            }

            $redirectUri = config('services.google.redirect') 
                ?: url('/api/auth/google/callback');
            
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->redirectUrl($redirectUri)
                ->user();

            // Check if user exists by email or provider_id
            $user = User::where('email', $googleUser->getEmail())
                ->orWhere(function ($query) use ($googleUser) {
                    $query->where('provider', 'google')
                        ->where('provider_id', $googleUser->getId());
                })
                ->first();

            if ($user) {
                // Update provider info if not set
                if (!$user->provider || !$user->provider_id) {
                    $user->provider = 'google';
                    $user->provider_id = $googleUser->getId();
                    $user->save();
                }

                // Check if user has all required fields
                $missingFields = $this->getMissingRequiredFields($user);

                if (!empty($missingFields)) {
                    // Generate temporary token for completing registration
                    $tempToken = JWTAuth::fromUser($user);

                    // If browser request, redirect to frontend with data
                    if ($isBrowserRequest) {
                        $params = http_build_query([
                            'status' => 'incomplete',
                            'token' => $tempToken,
                            'missing_fields' => implode(',', $missingFields),
                            'user_id' => $user->id,
                            'first_name' => $user->first_name,
                            'last_name' => $user->last_name,
                            'email' => $user->email,
                            'role_id' => $user->role_id ?? '',
                        ]);
                        return redirect("{$frontendUrl}/auth/google/callback?{$params}");
                    }

                    return response()->json([
                        'status' => 'incomplete',
                        'requires_completion' => true,
                        'missing_fields' => $missingFields,
                        'temp_token' => $tempToken,
                        'user' => $user->only(['id', 'first_name', 'last_name', 'email', 'role_id']),
                    ], 200);
                }

                // User is complete, generate JWT token
                $token = JWTAuth::fromUser($user);

                // If browser request, redirect to frontend with token
                if ($isBrowserRequest) {
                    return redirect("{$frontendUrl}/auth/google/callback?status=success&token=" . urlencode($token));
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Login successful',
                    'authorization' => [
                        'token' => $token,
                        'type' => 'bearer',
                    ],
                    'user' => $user->load('role', 'userProfile', 'userAdditionalSports.sport'),
                ]);
            } else {
                // Create new user
                $nameParts = $this->parseName($googleUser->getName());
                
                // Generate unique username
                $baseUsername = Str::slug($nameParts['first'] . ' ' . $nameParts['last']);
                $username = $baseUsername;
                $counter = 1;
                while (User::where('username', $username)->exists()) {
                    $username = $baseUsername . $counter;
                    $counter++;
                }

                // Get default role (athletes)
                $defaultRole = Role::where('name', 'athletes')->first();

                $user = User::create([
                    'first_name' => $nameParts['first'],
                    'middle_name' => $nameParts['middle'],
                    'last_name' => $nameParts['last'],
                    'username' => $username,
                    'email' => $googleUser->getEmail(),
                    'provider' => 'google',
                    'provider_id' => $googleUser->getId(),
                    'role_id' => $defaultRole ? $defaultRole->id : null,
                    'profile_photo' => $this->downloadProfilePhoto($googleUser->getAvatar(), $username),
                ]);

                // Check missing fields
                $missingFields = $this->getMissingRequiredFields($user);

                if (!empty($missingFields)) {
                    $tempToken = JWTAuth::fromUser($user);

                    // If browser request, redirect to frontend with data
                    if ($isBrowserRequest) {
                        $params = http_build_query([
                            'status' => 'incomplete',
                            'token' => $tempToken,
                            'missing_fields' => implode(',', $missingFields),
                            'user_id' => $user->id,
                            'first_name' => $user->first_name,
                            'last_name' => $user->last_name,
                            'email' => $user->email,
                            'role_id' => $user->role_id ?? '',
                        ]);
                        return redirect("{$frontendUrl}/auth/google/callback?{$params}");
                    }

                    return response()->json([
                        'status' => 'incomplete',
                        'requires_completion' => true,
                        'missing_fields' => $missingFields,
                        'temp_token' => $tempToken,
                        'user' => $user->only(['id', 'first_name', 'last_name', 'email', 'role_id']),
                    ], 200);
                }

                // User is complete
                $token = JWTAuth::fromUser($user);

                // If browser request, redirect to frontend with token
                if ($isBrowserRequest) {
                    return redirect("{$frontendUrl}/auth/google/callback?status=success&token=" . urlencode($token));
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Registration successful',
                    'authorization' => [
                        'token' => $token,
                        'type' => 'bearer',
                    ],
                    'user' => $user->load('role', 'userProfile', 'userAdditionalSports.sport'),
                ], 201);
            }
        } catch (\Exception $e) {
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $isBrowserRequest = !$request->wantsJson() && !$request->expectsJson() && !$request->ajax();
            
            if ($isBrowserRequest) {
                return redirect("{$frontendUrl}/auth/google/callback?error=" . urlencode($e->getMessage()));
            }
            
            return response()->json([
                'status' => 'error',
                'message' => 'Authentication failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get missing required fields for user.
     */
    private function getMissingRequiredFields(User $user): array
    {
        $missingFields = [];

        if (!$user->birthday) {
            $missingFields[] = 'birthday';
        }
        if (!$user->sex) {
            $missingFields[] = 'sex';
        }
        if (!$user->contact_number) {
            $missingFields[] = 'contact_number';
        }
        if (!$user->barangay) {
            $missingFields[] = 'barangay';
        }
        if (!$user->city) {
            $missingFields[] = 'city';
        }
        if (!$user->province) {
            $missingFields[] = 'province';
        }
        if (!$user->zip_code) {
            $missingFields[] = 'zip_code';
        }
        if (!$user->role_id) {
            $missingFields[] = 'role_id';
        }
        if (!$user->userProfile || !$user->userProfile->main_sport_id) {
            $missingFields[] = 'sports';
        }

        return $missingFields;
    }

    /**
     * Parse full name into parts.
     */
    private function parseName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName));
        
        if (count($parts) === 1) {
            return [
                'first' => $parts[0],
                'middle' => null,
                'last' => $parts[0],
            ];
        } elseif (count($parts) === 2) {
            return [
                'first' => $parts[0],
                'middle' => null,
                'last' => $parts[1],
            ];
        } else {
            return [
                'first' => $parts[0],
                'middle' => implode(' ', array_slice($parts, 1, -1)),
                'last' => end($parts),
            ];
        }
    }

    /**
     * Download and store profile photo from URL.
     */
    private function downloadProfilePhoto(?string $url, string $username): ?string
    {
        if (!$url) {
            return null;
        }

        try {
            $imageData = file_get_contents($url);
            if ($imageData === false) {
                return null;
            }

            $extension = 'jpg'; // Default extension
            $fileName = time() . '_' . $username . '_google.' . $extension;
            $path = 'userpfp/' . $fileName;

            Storage::disk('public')->put($path, $imageData);

            return $path;
        } catch (\Exception $e) {
            \Log::warning('Failed to download profile photo: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Complete social registration with missing fields.
     */
    public function completeSocialRegistration(CompleteSocialRegistrationRequest $request)
    {
        try {
            $user = Auth::guard('api')->user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated',
                ], 401);
            }

            // Update user with provided fields (only update fields that are sent)
            $updateData = [];
            
            if ($request->has('birthday')) {
                $updateData['birthday'] = $request->birthday;
            }
            if ($request->has('sex')) {
                $updateData['sex'] = $request->sex;
            }
            if ($request->has('contact_number')) {
                $updateData['contact_number'] = $request->contact_number;
            }
            if ($request->has('barangay')) {
                $updateData['barangay'] = $request->barangay;
            }
            if ($request->has('city')) {
                $updateData['city'] = $request->city;
            }
            if ($request->has('province')) {
                $updateData['province'] = $request->province;
            }
            if ($request->has('zip_code')) {
                $updateData['zip_code'] = $request->zip_code;
            }
            if ($request->has('role_id')) {
                $updateData['role_id'] = $request->role_id;
            }
            
            if (!empty($updateData)) {
                $user->update($updateData);
            }

            // Handle sports (only if provided)
            if ($request->has('sports') && is_array($request->sports) && count($request->sports) > 0) {
                $sports = $request->sports;
                $mainSport = $sports[0];

                // Create or update user profile with main sport
                $userProfile = $user->userProfile ?: new UserProfile(['user_id' => $user->id]);
                $userProfile->main_sport_id = $mainSport['id'];
                $userProfile->main_sport_level = $mainSport['level'];
                $userProfile->save();

                // Delete existing additional sports and create new ones
                UserAdditionalSport::where('user_id', $user->id)->delete();

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
            }

            // Generate new JWT token
            $token = JWTAuth::fromUser($user);

            return response()->json([
                'status' => 'success',
                'message' => 'Registration completed successfully',
                'authorization' => [
                    'token' => $token,
                    'type' => 'bearer',
                ],
                'user' => $user->load('role', 'userProfile', 'userProfile.mainSport', 'userAdditionalSports.sport'),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to complete registration',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

