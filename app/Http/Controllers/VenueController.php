<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenuePhoto;
use App\Models\Facilities;
use App\Models\FacilityPhoto;
use App\Models\VenueUser; // ADDED
use App\Models\Booking;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Notification;
use App\Models\UserNotification;
use Carbon\Carbon;
use App\Models\VenueReview;
use Illuminate\Support\Facades\Schema;
use App\Models\VenueOperatingHours;
use App\Models\VenueAmenity;
use App\Models\VenueClosureDate;

class VenueController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $venues = Venue::with(['photos', 'facilities.photos'])->get()->map(function ($venue) {
            return [
                'id' => $venue->id,
                'name' => $venue->name,
                'description' => $venue->description,
                'address' => $venue->address,
                'latitude' => $venue->latitude,
                'longitude' => $venue->longitude,
                // Show all venue photos
                'photos' => $venue->photos->map(function ($photo) {
                    return [
                        'id' => $photo->id,
                        'image_url' => Storage::url($photo->image_path),
                        'uploaded_at' => $photo->uploaded_at,
                    ];
                }),
                'verified_at' => $venue->verified_at,
                'verification_expires_at' => $venue->verification_expires_at,
                'created_by' => $venue->created_by,
                'created_at' => $venue->created_at,
                'updated_at' => $venue->updated_at,
                'facilities' => $venue->facilities->map(function ($facility) {
                    return [
                        'id' => $facility->id,
                        'venue_id' => $facility->venue_id,
                        'price_per_hr' => $facility->price_per_hr,
                        'type' => $facility->type,
                        // Show all facility photos
                        'photos' => $facility->photos->map(function ($photo) {
                            return [
                                'id' => $photo->id,
                                'image_url' => Storage::url($photo->image_path),
                                'uploaded_at' => $photo->uploaded_at,
                            ];
                        }),
                        'created_at' => $facility->created_at,
                        'updated_at' => $facility->updated_at,
                    ];
                }),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'venues' => $venues
            ]
        ]);
    }

    public function OwnerVenues()
    {
        $userId = auth()->id();

        $venues = Venue::whereHas('venue_users', function ($query) use ($userId) {
            $query->where('user_id', $userId)
                  ->where('role', 'owner');
        })->with(['photos', 'facilities.photos'])->get()->map(function ($venue) {
            return [
                'id' => $venue->id,
                'name' => $venue->name,
                'description' => $venue->description,
                'address' => $venue->address,
                'latitude' => $venue->latitude,
                'longitude' => $venue->longitude,
                'photos' => $venue->photos->map(function ($photo) {
                    return [
                        'id' => $photo->id,
                        'image_url' => Storage::url($photo->image_path),
                        'uploaded_at' => $photo->uploaded_at,
                    ];
                }),
                'verified_at' => $venue->verified_at,
                'verification_expires_at' => $venue->verification_expires_at,
                'created_by' => $venue->created_by,
                'created_at' => $venue->created_at,
                'updated_at' => $venue->updated_at,
                'facilities' => $venue->facilities->map(function ($facility) {
                    return [
                        'id' => $facility->id,
                        'venue_id' => $facility->venue_id,
                        'price_per_hr' => $facility->price_per_hr,
                        'type' => $facility->type,
                        'photos' => $facility->photos->map(function ($photo) {
                            return [
                                'id' => $photo->id,
                                'image_url' => Storage::url($photo->image_path),
                                'uploaded_at' => $photo->uploaded_at,
                            ];
                        }),
                        'created_at' => $facility->created_at,
                        'updated_at' => $facility->updated_at,
                    ];
                }),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'venues' => $venues
            ]
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'address' => 'required|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'verified_at' => 'nullable|date',
            'verification_expires_at' => 'nullable|date',
            'phone_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'facebook_url' => 'nullable|url|max:255',
            'instagram_url' => 'nullable|url|max:255',
            'website' => 'nullable|url|max:255',
            'house_rules' => 'nullable|string',
            // allow single or multiple files under "image" input
            'image' => 'nullable',
            'image.*' => 'image|mimes:jpeg,png,jpg,gif|max:4096',
        ]);

        $venue = Venue::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'address' => $validated['address'],
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'verified_at' => $validated['verified_at'] ?? null,
            'verification_expires_at' => $validated['verification_expires_at'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'email' => $validated['email'] ?? null,
            'facebook_url' => $validated['facebook_url'] ?? null,
            'instagram_url' => $validated['instagram_url'] ?? null,
            'website' => $validated['website'] ?? null,
            'house_rules' => $validated['house_rules'] ?? null,
            'created_by' => auth()->id(),
        ]);

        // Add creator to venue_users (avoid duplicates)
        VenueUser::firstOrCreate(
            ['venue_id' => $venue->id, 'user_id' => auth()->id()],
            ['role' => 'owner', 'is_primary_owner' => true]
        );

        $photos = [];

        if ($request->hasFile('image')) {
            $files = $request->file('image');
            // normalize to array
            if (! is_array($files)) {
                $files = [$files];
            }

            foreach ($files as $file) {
                $fileName = time() . '_' . $file->getClientOriginalName();
                $imagePath = $file->storeAs('venue_photos', $fileName, 'public');

                $venuePhoto = VenuePhoto::create([
                    'venue_id' => $venue->id,
                    'image_path' => $imagePath,
                    'uploaded_at' => now(),
                ]);

                $photos[] = [
                    'id' => $venuePhoto->id,
                    'image_url' => Storage::url($imagePath),
                    'uploaded_at' => $venuePhoto->uploaded_at,
                ];
            }
        }

        return response()->json([
            'message' => 'Venue created successfully',
            'venue' => $venue,
            'photos' => $photos
        ], 201);
    }

    public function storeFacility(Request $request, $venueId)
    {
        $validated = $request->validate([
            'price_per_hr' => 'required|numeric|min:0',
            'type' => 'required|string',
            'name' => 'nullable|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'covered' => 'nullable|boolean',
            'image' => 'nullable',
            'image.*' => 'image|mimes:jpeg,png,jpg,gif|max:4096',
        ]);

        // Create the Facility
        $facility = Facilities::create([
            'venue_id' => $venueId,
            'price_per_hr' => $validated['price_per_hr'],
            'type' => $validated['type'],
            'name' => $validated['name'] ?? null,
            'capacity' => $validated['capacity'] ?? null,
            'covered' => $validated['covered'] ?? false,
        ]);

        $photos = [];

        if ($request->hasFile('image')) {
            $files = $request->file('image');
            if (! is_array($files)) {
                $files = [$files];
            }

            foreach ($files as $file) {
                $fileName = time() . '_' . $file->getClientOriginalName();
                $imagePath = $file->storeAs('facility_photo', $fileName, 'public');

                $facilityPhoto = FacilityPhoto::create([
                    'facility_id' => $facility->id,
                    'image_path' => $imagePath,
                    'uploaded_at' => now(),
                ]);

                $photos[] = [
                    'id' => $facilityPhoto->id,
                    'image_url' => Storage::url($imagePath),
                    'uploaded_at' => $facilityPhoto->uploaded_at,
                ];
            }
        }

        return response()->json([
            'facility_id' => $facility->id,
            'facility' => $facility,
            'photos' => $photos
        ], 201);
    }



    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $venue = Venue::with(['photos', 'facilities.photos', 'operatingHours', 'amenities', 'closureDates'])->find($id);
        if (! $venue) {
            return response()->json(['status' => 'error', 'message' => 'Venue not found'], 404);
        }

        $venueData = [
            'id' => $venue->id,
            'name' => $venue->name,
            'description' => $venue->description,
            'address' => $venue->address,
            'latitude' => $venue->latitude,
            'longitude' => $venue->longitude,
            'phone_number' => $venue->phone_number,
            'email' => $venue->email,
            'facebook_url' => $venue->facebook_url,
            'instagram_url' => $venue->instagram_url,
            'website' => $venue->website,
            'house_rules' => $venue->house_rules,
            'photos' => $venue->photos->map(function ($photo) {
                return [
                    'id' => $photo->id,
                    'image_url' => Storage::url($photo->image_path),
                    'uploaded_at' => $photo->uploaded_at,
                ];
            }),
            'verified_at' => $venue->verified_at,
            'verification_expires_at' => $venue->verification_expires_at,
            'created_by' => $venue->created_by,
            'created_at' => $venue->created_at,
            'updated_at' => $venue->updated_at,
            'facilities' => $venue->facilities->map(function ($facility) {
                return [
                    'id' => $facility->id,
                    'venue_id' => $facility->venue_id,
                    'name' => $facility->name,
                    'price_per_hr' => $facility->price_per_hr,
                    'type' => $facility->type,
                    'capacity' => $facility->capacity,
                    'covered' => $facility->covered,
                    'photos' => $facility->photos->map(function ($photo) {
                        return [
                            'id' => $photo->id,
                            'image_url' => Storage::url($photo->image_path),
                            'uploaded_at' => $photo->uploaded_at,
                        ];
                    }),
                    'created_at' => $facility->created_at,
                    'updated_at' => $facility->updated_at,
                ];
            }),
            'operating_hours' => $venue->operatingHours,
            'amenities' => $venue->amenities,
            'closure_dates' => $venue->closureDates,
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'venue' => $venueData
            ]
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update venue (owner only).
     */
    public function update(Request $request, string $id)
    {
        $venue = Venue::find($id);
        if (! $venue) {
            \Log::warning('venue.update.not_found', ['venue_id' => $id, 'request' => $request->all()]);
            return response()->json(['status' => 'error', 'message' => 'Venue not found'], 404);
        }

        $user = auth()->user();

        // owner checks
        $isCreator = $user && $user->id === $venue->created_by;
        $isVenueUserOwner = false;
        if (class_exists(\App\Models\VenueUser::class)) {
            $isVenueUserOwner = \App\Models\VenueUser::where('venue_id', $venue->id)
                ->where('user_id', $user->id)
                ->where(function($q) {
                    $q->where('role', 'owner')->orWhere('is_primary_owner', true);
                })
                ->exists();
        }

        // Debug log owner check + payload
        \Log::debug('venue.update.request', [
            'venue_id' => $venue->id,
            'auth_user_id' => $user->id ?? null,
            'isCreator' => $isCreator,
            'isVenueUserOwner' => $isVenueUserOwner,
            'payload' => $request->all(),
            'files' => array_keys($request->files->all()),
            'headers' => [
                'content_type' => $request->header('Content-Type'),
                'accept' => $request->header('Accept'),
            ],
            'route' => \Illuminate\Support\Facades\Route::currentRouteName(),
        ]);

        \Log::debug('venue.update.raw_input', [
            'php_input' => substr(file_get_contents('php://input'), 0, 2000),
            '_POST' => $_POST,
            '_FILES' => array_map(function($f){ return ['name'=>$f['name'] ?? null,'size'=>$f['size'] ?? null,'type'=>$f['type'] ?? null]; }, $_FILES),
            'headers' => getallheaders(),
        ]);

        if (! $isCreator && ! $isVenueUserOwner) {
            \Log::warning('venue.update.forbidden', ['venue_id' => $venue->id, 'user_id' => $user->id ?? null]);
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'verified_at' => 'nullable|date',
            'verification_expires_at' => 'nullable|date',
            'phone_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'facebook_url' => 'nullable|url|max:255',
            'instagram_url' => 'nullable|url|max:255',
            'website' => 'nullable|url|max:255',
            'house_rules' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:4096',
        ]);

        // collect allowed fields from raw request (so JSON/form-data both work)
        $allowed = ['name','description','address','latitude','longitude','verified_at','verification_expires_at','phone_number','email','facebook_url','instagram_url','website','house_rules'];
        $data = [];
        foreach ($allowed as $f) {
            if ($request->has($f)) {
                $data[$f] = $request->input($f);
            }
        }

        // remove null/empty strings
        $data = array_filter($data, function ($v) { return !is_null($v) && $v !== ''; });

        \Log::debug('venue.update.prep', [
            'venue_id' => $venue->id,
            'validated' => $validated,
            'prepared_data' => $data,
        ]);

        try {
            // ensure Venue model has $fillable for these fields
            $newPhoto = null;
            $imageUrl = null;

            if (! empty($data)) {
                $venue->fill($data);
                \Log::debug('venue.update.before_save', [
                    'venue_id' => $venue->id,
                    'dirty_before_save' => $venue->getDirty(),
                ]);

                $venue->save();

                \Log::info('venue.update.saved', [
                    'venue_id' => $venue->id,
                    'changes' => $venue->getChanges(),
                    'updated_at' => $venue->updated_at,
                ]);
            }

            if ($request->hasFile('image')) {
                // replace creation with an update of the first photo
                $first = $venue->photos()->first();
                if ($first) {
                    Storage::disk('public')->delete($first->image_path);
                    $file = $request->file('image');
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $imagePath = $file->storeAs('venue_photos', $fileName, 'public');
                    $first->update(['image_path' => $imagePath, 'uploaded_at' => now()]);
                    $newPhoto = $first;
                } else {
                    // create as before
                    $file = $request->file('image');
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $imagePath = $file->storeAs('venue_photos', $fileName, 'public');

                    $newPhoto = VenuePhoto::create([
                        'venue_id' => $venue->id,
                        'image_path' => $imagePath,
                        'uploaded_at' => now(),
                    ]);
                    $imageUrl = Storage::url($imagePath);
                }

                \Log::info('venue.update.image_replaced', [
                    'venue_id' => $venue->id,
                    'new_image_path' => $imagePath,
                    'new_photo_id' => $newPhoto->id,
                ]);
            }

            if (empty($data) && ! $newPhoto) {
                \Log::debug('venue.update.no_changes', [
                    'venue_id' => $venue->id,
                    'request' => $request->all(),
                ]);
            }

            $venue->refresh();
            $venue->load(['photos', 'facilities.photos']);

            return response()->json([
                'status' => 'success',
                'message' => empty($data) && ! $newPhoto ? 'No changes made' : 'Venue updated successfully',
                'debug' => [
                    'received_data' => $data,
                    'uploaded_file' => $request->hasFile('image'),
                    'is_creator' => $isCreator,
                    'is_venue_user_owner' => $isVenueUserOwner,
                ],
                'venue' => [
                    'id' => $venue->id,
                    'name' => $venue->name,
                    'description' => $venue->description,
                    'address' => $venue->address,
                    'latitude' => $venue->latitude,
                    'longitude' => $venue->longitude,
                    'photos' => $venue->photos->map(fn($p) => ['id'=>$p->id,'image_url'=>Storage::url($p->image_path),'uploaded_at'=>$p->uploaded_at]),
                    'facilities' => $venue->facilities->map(function ($facility) {
                        return [
                            'id' => $facility->id,
                            'venue_id' => $facility->venue_id,
                            'price_per_hr' => $facility->price_per_hr,
                            'type' => $facility->type,
                            'photos' => $facility->photos->map(fn($p) => ['id'=>$p->id,'image_url'=>Storage::url($p->image_path),'uploaded_at'=>$p->uploaded_at]),
                        ];
                    }),
                    'verified_at' => $venue->verified_at,
                    'verification_expires_at' => $venue->verification_expires_at,
                    'created_by' => $venue->created_by,
                    'created_at' => $venue->created_at,
                    'updated_at' => $venue->updated_at,
                ],
                'uploaded_photo' => $newPhoto ? ['id'=>$newPhoto->id,'image_url'=>$imageUrl,'uploaded_at'=>$newPhoto->uploaded_at] : null,
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('venue.update.exception', [
                'venue_id' => $venue->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['status'=>'error','message'=>'Update failed','error'=>$e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $venue = Venue::with(['photos', 'facilities.photos'])->find($id);
        if (! $venue) {
            \Log::warning('venue.delete.not_found', ['venue_id' => $id]);
            return response()->json(['status' => 'error', 'message' => 'Venue not found'], 404);
        }

        $user = auth()->user();

        // owner checks (creator or VenueUser owner)
        $isCreator = $user && $user->id === $venue->created_by;
        $isVenueUserOwner = false;
        if (class_exists(\App\Models\VenueUser::class)) {
            $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
                ->where('user_id', $user->id)
                ->where(function ($q) {
                    $q->where('role', 'owner')->orWhere('is_primary_owner', true);
                })
                ->exists();
        }

        if (! $isCreator && ! $isVenueUserOwner) {
            \Log::warning('venue.delete.forbidden', ['venue_id' => $venue->id, 'user_id' => $user->id ?? null]);
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        try {
            DB::transaction(function () use ($venue) {
                // Delete facility photos and facilities
                foreach ($venue->facilities as $facility) {
                    if ($facility->photos) {
                        foreach ($facility->photos as $fphoto) {
                            try {
                                Storage::disk('public')->delete($fphoto->image_path);
                            } catch (\Throwable $e) {
                                \Log::warning('venue.delete.delete_facility_photo_failed', ['photo_id' => $fphoto->id, 'error' => $e->getMessage()]);
                            }
                            $fphoto->delete();
                        }
                    }
                    $facility->delete();
                }

                // Delete venue photos
                foreach ($venue->photos as $vphoto) {
                    try {
                        Storage::disk('public')->delete($vphoto->image_path);
                    } catch (\Throwable $e) {
                        \Log::warning('venue.delete.delete_venue_photo_failed', ['photo_id' => $vphoto->id, 'error' => $e->getMessage()]);
                    }
                    $vphoto->delete();
                }

                // Delete venue_user relations
                if (class_exists(\App\Models\VenueUser::class)) {
                    VenueUser::where('venue_id', $venue->id)->delete();
                }

                // Finally delete the venue
                $venue->delete();
            });

            \Log::info('venue.deleted', ['venue_id' => $id, 'deleted_by' => $user->id ?? null]);

            return response()->json(['status' => 'success', 'message' => 'Venue deleted'], 200);
        } catch (\Throwable $e) {
            \Log::error('venue.delete.exception', [
                'venue_id' => $venue->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['status' => 'error', 'message' => 'Delete failed', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * View a single facility
     * GET /api/facilities/{id}
     */
    public function showFacility(string $id)
    {
        $facility = Facilities::with(['photos', 'venue'])->find($id);
        if (! $facility) {
            return response()->json(['status' => 'error', 'message' => 'Facility not found'], 404);
        }

        $data = [
            'id' => $facility->id,
            'venue_id' => $facility->venue_id,
            'price_per_hr' => $facility->price_per_hr,
            'type' => $facility->type,
            'photos' => $facility->photos->map(function ($photo) {
                return [
                    'id' => $photo->id,
                    'image_url' => Storage::url($photo->image_path),
                    'uploaded_at' => $photo->uploaded_at,
                ];
            }),
            'created_at' => $facility->created_at,
            'updated_at' => $facility->updated_at,
        ];

        return response()->json(['status' => 'success', 'data' => ['facility' => $data]], 200);
    }

    /**
     * Update facility details (owner only)
     * PUT/PATCH /api/facilities/{id}
     */
    public function updateFacility(Request $request, string $id)
    {
        $facility = Facilities::with(['photos', 'venue'])->find($id);
        if (! $facility) {
            \Log::warning('facility.update.not_found', ['facility_id' => $id]);
            return response()->json(['status' => 'error', 'message' => 'Facility not found'], 404);
        }

        $user = auth()->user();
        $venue = $facility->venue;

        // owner check: venue creator or venue user owner
        $isCreator = $user && $venue && $user->id === $venue->created_by;
        $isVenueUserOwner = false;
        if ($venue && class_exists(\App\Models\VenueUser::class)) {
            $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
                ->where('user_id', $user->id)
                ->where(function($q) {
                    $q->where('role', 'owner')->orWhere('is_primary_owner', true);
                })->exists();
        }

        if (! $isCreator && ! $isVenueUserOwner) {
            \Log::warning('facility.update.forbidden', ['facility_id' => $facility->id, 'user_id' => $user->id ?? null]);
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'price_per_hr' => 'nullable|numeric|min:0',
            'type' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:8192',
        ]);

        $allowed = ['price_per_hr','type'];
        $data = [];
        foreach ($allowed as $f) {
            if ($request->has($f)) {
                $data[$f] = $request->input($f);
            }
        }
        $data = array_filter($data, fn($v) => !is_null($v) && $v !== '');

        \Log::debug('facility.update.prep', ['facility_id' => $facility->id, 'prepared' => $data, 'has_file' => $request->hasFile('image')]);

        try {
            if (! empty($data)) {
                $facility->fill($data);
                \Log::debug('facility.update.before_save', ['facility_id' => $facility->id, 'dirty' => $facility->getDirty()]);
                $facility->save();
                \Log::info('facility.update.saved', ['facility_id' => $facility->id, 'changes' => $facility->getChanges()]);
            }

            $newPhoto = null;
            $imageUrl = null;
            if ($request->hasFile('image')) {
                // replace first photo if exists, otherwise create new
                $first = $facility->photos()->first();
                $file = $request->file('image');
                $fileName = time() . '_' . $file->getClientOriginalName();
                $imagePath = $file->storeAs('facility_photo', $fileName, 'public');

                if ($first) {
                    try {
                        Storage::disk('public')->delete($first->image_path);
                    } catch (\Throwable $e) {
                        \Log::warning('facility.update.delete_old_photo_failed', ['photo_id' => $first->id, 'error' => $e->getMessage()]);
                    }
                    $first->update(['image_path' => $imagePath, 'uploaded_at' => now()]);
                    $newPhoto = $first;
                } else {
                    $newPhoto = FacilityPhoto::create([
                        'facility_id' => $facility->id,
                        'image_path' => $imagePath,
                        'uploaded_at' => now(),
                    ]);
                }

                $imageUrl = Storage::url($imagePath);
                \Log::info('facility.update.image_saved', ['facility_id' => $facility->id, 'path' => $imagePath, 'photo_id' => $newPhoto->id ?? null]);
            }

            $facility->refresh();
            $facility->load('photos');

            return response()->json([
                'status' => 'success',
                'message' => empty($data) && ! $newPhoto ? 'No changes made' : 'Facility updated successfully',
                'facility' => [
                    'id' => $facility->id,
                    'venue_id' => $facility->venue_id,
                    'price_per_hr' => $facility->price_per_hr,
                    'type' => $facility->type,
                    'photos' => $facility->photos->map(fn($p) => ['id'=>$p->id,'image_url'=>Storage::url($p->image_path),'uploaded_at'=>$p->uploaded_at]),
                    'created_at' => $facility->created_at,
                    'updated_at' => $facility->updated_at,
                ],
                'uploaded_photo' => $newPhoto ? ['id' => $newPhoto->id, 'image_url' => $imageUrl, 'uploaded_at' => $newPhoto->uploaded_at] : null,
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('facility.update.exception', ['facility_id' => $facility->id, 'error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Update failed', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * View single facility by venue
     * GET /api/venues/{venueId}/facilities/{facilityId}
     */
    public function showFacilityByVenue(string $venueId, string $facilityId)
    {
        $facility = Facilities::with(['photos', 'venue'])
            ->where('id', $facilityId)
            ->where('venue_id', $venueId)
            ->first();

        if (! $facility) {
            return response()->json(['status' => 'error', 'message' => 'Facility not found for this venue'], 404);
        }

        $data = [
            'id' => $facility->id,
            'venue_id' => $facility->venue_id,
            'price_per_hr' => $facility->price_per_hr,
            'type' => $facility->type,
            'photos' => $facility->photos->map(fn($p) => [
                'id' => $p->id,
                'image_url' => Storage::url($p->image_path),
                'uploaded_at' => $p->uploaded_at,
            ]),
            'created_at' => $facility->created_at,
            'updated_at' => $facility->updated_at,
        ];

        return response()->json(['status' => 'success', 'data' => ['facility' => $data]], 200);
    }

    /**
     * Update facility by venue (owner only)
     * PUT/PATCH /api/venues/{venueId}/facilities/{facilityId}
     */
    public function updateFacilityByVenue(Request $request, string $venueId, string $facilityId)
    {
        $facility = Facilities::with(['photos', 'venue'])
            ->where('id', $facilityId)
            ->where('venue_id', $venueId)
            ->first();

        if (! $facility) {
            \Log::warning('facility.update.not_found_for_venue', ['venue_id'=>$venueId,'facility_id'=>$facilityId]);
            return response()->json(['status' => 'error', 'message' => 'Facility not found for this venue'], 404);
        }

        $venue = $facility->venue;
        $user = auth()->user();

        $isCreator = $user && $venue && $user->id === $venue->created_by;
        $isVenueUserOwner = false;
        if ($venue && class_exists(\App\Models\VenueUser::class)) {
            $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
                ->where('user_id', $user->id)
                ->where(function($q){ $q->where('role','owner')->orWhere('is_primary_owner', true); })
                ->exists();
        }

        if (! $isCreator && ! $isVenueUserOwner) {
            \Log::warning('facility.update.forbidden_for_venue', ['venue_id'=>$venueId,'facility_id'=>$facilityId,'user_id'=>$user->id ?? null]);
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'price_per_hr' => 'nullable|numeric|min:0',
            'type' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:8192',
        ]);

        $allowed = ['price_per_hr','type'];
        $data = [];
        foreach ($allowed as $f) {
            if ($request->has($f)) $data[$f] = $request->input($f);
        }
        $data = array_filter($data, fn($v) => !is_null($v) && $v !== '');

        try {
            if (! empty($data)) {
                $facility->fill($data);
                $facility->save();
                \Log::info('facility.update.saved_for_venue', ['facility_id'=>$facility->id,'venue_id'=>$venueId]);
            }

            $newPhoto = null;
            $imageUrl = null;
            if ($request->hasFile('image')) {
                $first = $facility->photos()->first();
                $file = $request->file('image');
                $fileName = time() . '_' . $file->getClientOriginalName();
                $imagePath = $file->storeAs('facility_photo', $fileName, 'public');

                if ($first) {
                    try { Storage::disk('public')->delete($first->image_path); } catch (\Throwable $e) { \Log::warning('facility.update.delete_old_photo_failed', ['photo_id'=>$first->id,'error'=>$e->getMessage()]); }
                    $first->update(['image_path'=>$imagePath, 'uploaded_at'=>now()]);
                    $newPhoto = $first;
                } else {
                    $newPhoto = FacilityPhoto::create(['facility_id'=>$facility->id,'image_path'=>$imagePath,'uploaded_at'=>now()]);
                }

                $imageUrl = Storage::url($imagePath);
                \Log::info('facility.update.image_saved_for_venue', ['facility_id'=>$facility->id,'path'=>$imagePath]);
            }

            $facility->refresh();
            $facility->load('photos');

            return response()->json([
                'status' => 'success',
                'message' => empty($data) && ! $newPhoto ? 'No changes made' : 'Facility updated successfully',
                'facility' => [
                    'id' => $facility->id,
                    'venue_id' => $facility->venue_id,
                    'price_per_hr' => $facility->price_per_hr,
                    'type' => $facility->type,
                    'photos' => $facility->photos->map(fn($p) => ['id'=>$p->id,'image_url'=>Storage::url($p->image_path),'uploaded_at'=>$p->uploaded_at]),
                    'created_at' => $facility->created_at,
                    'updated_at' => $facility->updated_at,
                ],
                'uploaded_photo' => $newPhoto ? ['id' => $newPhoto->id, 'image_url' => $imageUrl, 'uploaded_at' => $newPhoto->uploaded_at] : null,
            ], 200);
        } catch (\Throwable $e) {
            \Log::error('facility.update.exception_for_venue', ['error'=>$e->getMessage(),'facility_id'=>$facilityId,'venue_id'=>$venueId]);
            return response()->json(['status'=>'error','message'=>'Update failed','error'=>$e->getMessage()], 500);
        }
    }

    /**
     * Add one or more photos to a facility
     * POST /api/venues/{venueId}/facilities/{facilityId}/photos
     */
    public function addFacilityPhoto(Request $request, $venueId, $facilityId)
    {
        $request->validate([
            'image' => 'required', // accept either single or multiple
            'image.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $facility = Facilities::findOrFail($facilityId);
        $addedPhotos = [];

        // Normalize to array — even if only one file is uploaded
        $files = $request->file('image');
        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $imageFile) {
            $path = $imageFile->store('facility_photo', 'public');

            $photo = $facility->photos()->create([
                'image_path' => $path,
            ]);

            $addedPhotos[] = [
                'id' => $photo->id,
                'image_url' => Storage::url($photo->image_path),
                'uploaded_at' => $photo->created_at,
            ];
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Photo(s) added to facility successfully',
            'added_photos' => $addedPhotos,
            'facility' => $facility->load('photos')
        ], 201);
    }

    /**
     * Delete a facility photo
     * DELETE /api/venues/{venueId}/facilities/{facilityId}/photos/{photoId}
     */
    public function destroyFacilityPhoto(string $venueId, string $facilityId, string $photoId)
    {
        $facility = Facilities::with(['photos','venue'])->where('id', $facilityId)->where('venue_id', $venueId)->first();
        if (! $facility) {
            \Log::warning('facility.photo.delete.facility_not_found', ['venue_id'=>$venueId,'facility_id'=>$facilityId,'photo_id'=>$photoId]);
            return response()->json(['status' => 'error', 'message' => 'Facility not found for this venue'], 404);
        }

        $photo = $facility->photos->firstWhere('id', (int) $photoId);
        if (! $photo) {
            \Log::warning('facility.photo.delete.not_found', ['facility_id'=>$facilityId,'photo_id'=>$photoId]);
            return response()->json(['status' => 'error', 'message' => 'Photo not found for this facility'], 404);
        }

        $user = auth()->user();
        $venue = $facility->venue;
        $isCreator = $user && $venue && $user->id === $venue->created_by;
        $isVenueUserOwner = false;
        if ($venue && class_exists(\App\Models\VenueUser::class)) {
            $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
                ->where('user_id', $user->id)
                ->where(function ($q) { $q->where('role','owner')->orWhere('is_primary_owner', true); })
                ->exists();
        }

        if (! $isCreator && ! $isVenueUserOwner) {
            \Log::warning('facility.photo.delete.forbidden', ['venue_id'=>$venueId,'facility_id'=>$facilityId,'photo_id'=>$photoId,'user_id'=>$user->id ?? null]);
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        try {
            Storage::disk('public')->delete($photo->image_path);
            $photo->delete();

            \Log::info('facility.photo.deleted', ['facility_id'=>$facilityId,'photo_id'=>$photoId,'deleted_by'=>$user->id ?? null]);

            return response()->json(['status' => 'success', 'message' => 'Facility photo deleted', 'data' => ['photo_id' => $photoId]], 200);
        } catch (\Throwable $e) {
            \Log::error('facility.photo.delete.exception', ['facility_id'=>$facilityId,'photo_id'=>$photoId,'error'=>$e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Delete failed', 'error' => $e->getMessage()], 500);
        }
    }

    public function addMember(Request $request, string $venueId)
    {
        $auth = auth()->user();
        $venue = Venue::find($venueId);
        if (! $venue) {
            return response()->json(['status' => 'error', 'message' => 'Venue not found'], 404);
        }

        // only creator can add members
        if (! $auth || $auth->id !== $venue->created_by) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden', 'venue' => $venue], 403);
        }

        $validator = Validator::make($request->all(), [
            'username' => 'nullable|string',
            'user_id' => 'nullable|integer|exists:users,id',
            'role' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Resolve user by id or username
        if ($request->filled('user_id')) {
            $newUser = User::find($request->user_id);
        } elseif ($request->filled('username')) {
            $newUser = User::where('username', $request->username)->first();
        } else {
            return response()->json(['status' => 'error', 'message' => 'username or user_id is required'], 422);
        }

        if (! $newUser) {
            return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
        }

        // avoid duplicate membership
        if (VenueUser::where('venue_id', $venue->id)->where('user_id', $newUser->id)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'User is already added to this venue'], 409);
        }

        $role = $request->role ? $request->role : 'Staff';

        try {
            DB::transaction(function () use ($venue, $newUser, $role, &$venueUser, $auth) {
                $venueUser = VenueUser::create([
                    'venue_id' => $venue->id,
                    'user_id' => $newUser->id,
                    'role' => $role,
                    'is_primary_owner' => false,
                ]);

                Log::info('venue.user.added', [
                    'venue_id' => $venue->id,
                    'user_id' => $newUser->id,
                    'role' => $role,
                    'added_by' => $auth->id ?? null,
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('venue.user.add.db_error', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Database error adding venue user',
                'error' => $e->getMessage()
            ], 500);
        } catch (\Throwable $e) {
            Log::error('venue.user.add.exception', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Failed to add user', 'error' => $e->getMessage()], 500);
        }

        return response()->json(['status' => 'success', 'message' => ucfirst(strtolower($role)) . ' added to venue', 'venue_user' => $venueUser], 201);
    }

    public function staff(string $venueId)
    {
        // load venue with its venue_users and the related user records
        $venue = Venue::with(['venue_users.user'])->find($venueId);
        if (! $venue) {
            return response()->json(['status' => 'error', 'message' => 'Venue not found'], 404);
        }

        $members = $venue->venue_users->map(function ($vu) {
            return [
                'id' => $vu->id,
                'user_id' => $vu->user_id,
                'username' => $vu->user->username ?? null,
                'email' => $vu->user->email ?? null,
                'contact_person' => $vu->user->contact_number ?? null,
                'role' => $vu->role,
                'is_primary_owner' => (bool) ($vu->is_primary_owner ?? false),
                'joined_at' => $vu->created_at,
            ];
        });

        // load creator user (fallback if relation not defined on model)
        $creator = $venue->creator ?? \App\Models\User::find($venue->created_by);

        $venueInfo = [
            'id' => $venue->id,
            'name' => $venue->name,
            'address' => $venue->address,
            'latitude' => $venue->latitude,
            'longitude' => $venue->longitude,
            'created_by' => $venue->created_by,
            'created_at' => $venue->created_at,
            'updated_at' => $venue->updated_at,
            'creator' => $creator ? [
                'id' => $creator->id,
                'username' => $creator->username,
                'email' => $creator->email,
            ] : null,
        ];

        return response()->json([
            'status' => 'success',
            'venue' => $venueInfo,
            'members' => $members,
        ]);
    }

    /**
     * Get all bookings for venues managed by the authenticated user
     */
    public function getBookings()
    {
        $user = auth()->user();
        
        // Get venues where user is owner/manager
        $venueIds = VenueUser::where('user_id', $user->id)
            ->whereIn('role', ['owner', 'manager'])
            ->pluck('venue_id');
        
        // Get bookings for these venues
        $bookings = Booking::with(['venue', 'event', 'user'])
            ->whereIn('venue_id', $venueIds)
            ->orderBy('date', 'desc')
            ->orderBy('start_time', 'desc')
            ->get()
            ->map(function($booking) {
                return [
                    'id' => $booking->id,
                    'status' => $booking->status,
                    'date' => $booking->date,
                    'start_time' => $booking->start_time,
                    'end_time' => $booking->end_time,
                    'sport' => $booking->sport,
                    'purpose' => $booking->purpose,
                    'venue' => [
                        'id' => $booking->venue->id,
                        'name' => $booking->venue->name,
                        'address' => $booking->venue->address,
                    ],
                    'user' => [
                        'id' => $booking->user->id,
                        'username' => $booking->user->username,
                        'email' => $booking->user->email,
                    ],
                    'event' => [
                        'id' => $booking->event->id,
                        'name' => $booking->event->name,
                        'description' => $booking->event->description,
                        'event_type' => $booking->event->event_type,
                        'slots' => $booking->event->slots,
                    ],
                    'created_at' => $booking->created_at,
                    'updated_at' => $booking->updated_at,
                ];
            });
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'bookings' => $bookings,
                'total' => $bookings->count(),
            ]
        ]);
    }

    /**
     * Update booking status (approve/deny)
     */
    public function updateBookingStatus(Request $request, $id)
    {
        $user = auth()->user();
        
        $booking = Booking::with(['venue', 'event'])->find($id);
        if (!$booking) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found'
            ], 404);
        }
        
        // Check if user is owner/manager of the venue
        $isAuthorized = VenueUser::where('user_id', $user->id)
            ->where('venue_id', $booking->venue_id)
            ->whereIn('role', ['owner', 'manager'])
            ->exists();
            
        if (!$isAuthorized) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to manage this venue'
            ], 403);
        }
        
        $validated = $request->validate([
            'status' => 'required|in:approved,denied'
        ]);
        
        // Update booking status
        $booking->update(['status' => $validated['status']]);
        
        // Create notification for event creator
        $notification = Notification::create([
            'type' => 'booking_' . $validated['status'],
            'data' => [
                'message' => "Your booking request for {$booking->venue->name} has been {$validated['status']}",
                'booking_id' => $booking->id,
                'event_id' => $booking->event_id,
            ],
            'created_by' => $user->id,
        ]);
        
        UserNotification::create([
            'notification_id' => $notification->id,
            'user_id' => $booking->user_id,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'none',
        ]);
        
        return response()->json([
            'status' => 'success',
            'message' => 'Booking status updated successfully',
            'data' => [
                'booking' => $booking->fresh()
            ]
        ]);
    }

    /**
     * Cancel an event booking
     */
    public function cancelEventBooking(Request $request, $id)
    {
        $user = auth()->user();
        
        $booking = Booking::with(['venue', 'event'])->find($id);
        if (!$booking) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found'
            ], 404);
        }
        
        // Check if user is owner/manager of the venue
        $isAuthorized = VenueUser::where('user_id', $user->id)
            ->where('venue_id', $booking->venue_id)
            ->whereIn('role', ['owner', 'manager'])
            ->exists();
            
        if (!$isAuthorized) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to manage this venue'
            ], 403);
        }
        
        $validated = $request->validate([
            'reason' => 'required|string|max:500'
        ]);
        
        // Update booking and event
        $booking->update(['status' => 'cancelled']);
        $booking->event->update(['cancelled_at' => now()]);
        
        // Notify event creator
        $notification = Notification::create([
            'type' => 'booking_cancelled',
            'data' => [
                'message' => "Your event '{$booking->event->name}' at {$booking->venue->name} has been cancelled. Reason: {$validated['reason']}",
                'booking_id' => $booking->id,
                'event_id' => $booking->event_id,
                'reason' => $validated['reason'],
            ],
            'created_by' => $user->id,
        ]);
        
        UserNotification::create([
            'notification_id' => $notification->id,
            'user_id' => $booking->user_id,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'none',
        ]);
        
        // Notify all participants
        $participants = EventParticipant::where('event_id', $booking->event_id)->get();
        foreach ($participants as $participant) {
            if ($participant->user_id !== $booking->user_id) {
                $participantNotification = Notification::create([
                    'type' => 'event_cancelled',
                    'data' => [
                        'message' => "Event '{$booking->event->name}' has been cancelled by the venue. Reason: {$validated['reason']}",
                        'event_id' => $booking->event_id,
                        'reason' => $validated['reason'],
                    ],
                    'created_by' => $user->id,
                ]);
                
                UserNotification::create([
                    'notification_id' => $participantNotification->id,
                    'user_id' => $participant->user_id,
                    'pinned' => false,
                    'is_read' => false,
                    'action_state' => 'none',
                ]);
            }
        }
        
        return response()->json([
            'status' => 'success',
            'message' => 'Event booking cancelled successfully',
            'data' => [
                'booking' => $booking->fresh(),
                'event' => $booking->event->fresh()
            ]
        ]);
    }

    /**
     * Reschedule an event booking
     */
    public function rescheduleEventBooking(Request $request, $id)
    {
        $user = auth()->user();
        
        $booking = Booking::with(['venue', 'event'])->find($id);
        if (!$booking) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found'
            ], 404);
        }
        
        // Check if user is owner/manager of the venue
        $isAuthorized = VenueUser::where('user_id', $user->id)
            ->where('venue_id', $booking->venue_id)
            ->whereIn('role', ['owner', 'manager'])
            ->exists();
            
        if (!$isAuthorized) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to manage this venue'
            ], 403);
        }
        
        $validated = $request->validate([
            'new_date' => 'required|date',
            'new_start_time' => 'required|date_format:H:i:s',
            'new_end_time' => 'required|date_format:H:i:s|after:new_start_time',
            'reason' => 'required|string|max:500'
        ]);
        
        // Update booking
        $booking->update([
            'date' => $validated['new_date'],
            'start_time' => $validated['new_start_time'],
            'end_time' => $validated['new_end_time'],
        ]);
        
        // Update event
        $booking->event->update([
            'date' => $validated['new_date'],
            'start_time' => $validated['new_start_time'],
            'end_time' => $validated['new_end_time'],
        ]);
        
        // Create notification for event creator
        $notification = Notification::create([
            'type' => 'booking_rescheduled',
            'data' => [
                'message' => "Your event '{$booking->event->name}' at {$booking->venue->name} has been rescheduled to {$validated['new_date']} at {$validated['new_start_time']}. Reason: {$validated['reason']}",
                'booking_id' => $booking->id,
                'event_id' => $booking->event_id,
                'new_date' => $validated['new_date'],
                'new_start_time' => $validated['new_start_time'],
                'new_end_time' => $validated['new_end_time'],
                'reason' => $validated['reason'],
            ],
            'created_by' => $user->id,
        ]);
        
        UserNotification::create([
            'notification_id' => $notification->id,
            'user_id' => $booking->user_id,
            'pinned' => false,
            'is_read' => false,
            'action_state' => 'none',
        ]);
        
        return response()->json([
            'status' => 'success',
            'message' => 'Event booking rescheduled successfully',
            'data' => [
                'booking' => $booking->fresh(),
                'event' => $booking->event->fresh()
            ]
        ]);
    }

    public function PostReview(Request $request, string $venueId)
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        $venue = Venue::find($venueId);
        if (! $venue) {
            return response()->json(['status' => 'error', 'message' => 'Venue not found'], 404);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        try {
            $review = VenueReview::updateOrCreate(
                ['venue_id' => $venue->id, 'user_id' => $user->id],
                ['rating' => $validated['rating'], 'comment' => $validated['comment'] ?? null, 'reviewed_at' => now()]
            );
        } catch (\Throwable $e) {
            \Log::error('venue.review.save_failed', ['venue_id' => $venue->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Failed to save review', 'error' => $e->getMessage()], 500);
        }

        $average = VenueReview::where('venue_id', $venue->id)->avg('rating');

        return response()->json([
            'status' => 'success',
            'message' => 'Review saved',
            'review' => $review,
            'average_rating' => $average !== null ? round((float) $average, 2) : null,
        ], 201);
    }

    public function venueReviews(Request $request, string $venueId)
    {
        $venue = Venue::find($venueId);
        if (! $venue) {
            return response()->json(['status' => 'error', 'message' => 'Venue not found'], 404);
        }

        $perPage = (int) $request->input('per_page', 20);

        // join with users to include username/email
        $query = DB::table('venue_reviews as vr')
            ->where('vr.venue_id', $venueId)
            ->leftJoin('users as u', 'u.id', '=', 'vr.user_id')
            ->select(
                'vr.id',
                'vr.user_id',
                'u.username',
                'u.email',
                'vr.rating',
                'vr.comment',
                'vr.reviewed_at',
                'vr.created_at',
                'vr.updated_at'
            )
            ->orderBy('vr.reviewed_at', 'desc');

        $paginated = $query->paginate($perPage);

        $average = DB::table('venue_reviews')->where('venue_id', $venueId)->avg('rating');
        $total = DB::table('venue_reviews')->where('venue_id', $venueId)->count();

        return response()->json([
            'status' => 'success',
            'venue_id' => $venueId,
            'average_rating' => $average !== null ? round((float) $average, 2) : null,
            'total_reviews' => (int) $total,
            'reviews' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ], 200);
    }

    public function search(Request $request)
    {
        $v = $request->validate([
            'q' => 'required|string|max:255',
        ]);

        $q = $v['q'];

        $venues = Venue::with(['photos', 'facilities.photos'])
            ->where('name', 'like', "%{$q}%")
            ->orderBy('created_at', 'desc')
            ->get();

        $results = $venues->map(function ($venue) {
            return [
                'id' => $venue->id,
                'name' => $venue->name,
                'description' => $venue->description,
                'address' => $venue->address,
                'latitude' => $venue->latitude,
                'longitude' => $venue->longitude,
                'photos' => $venue->photos->map(fn($p) => [
                    'id' => $p->id,
                    'image_url' => Storage::url($p->image_path),
                ]),
                'facilities_count' => $venue->facilities->count(),
                'created_at' => $venue->created_at,
                'updated_at' => $venue->updated_at,
            ];
        });

        return response()->json([
            'status' => 'success',
            'query' => $q,
            'results' => $results,
        ], 200);
    }

    public function getAnalytics(Request $request, $venueId = null)
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        // collect venues the user owns/manages or created
        $ownedIds = Venue::where('created_by', $user->id)->pluck('id')->toArray();

        $managedIds = [];
        if (class_exists(\App\Models\VenueUser::class)) {
            $managedIds = VenueUser::where('user_id', $user->id)
                ->whereIn('role', ['owner', 'manager', 'Owner', 'Manager'])
                ->pluck('venue_id')
                ->toArray();
        }

        $venueIds = array_values(array_unique(array_merge($ownedIds, $managedIds)));

        if (empty($venueIds)) {
            return response()->json(['status' => 'error', 'message' => 'No venues found for this user'], 403);
        }

        // Get filter parameters from request
        $filterVenueId = $request->input('venue_id', $venueId);
        $facilityId = $request->input('facility_id');
        $period = $request->input('period', 'all'); // all, this_week, month, semi_annual, annual, custom
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Validate venue filter
        if ($filterVenueId && !in_array($filterVenueId, $venueIds)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized venue access'], 403);
        }

        // Apply venue filter if specified
        if ($filterVenueId) {
            $venueIds = [$filterVenueId];
        }

        // Validate facility filter (requires venue_id)
        if ($facilityId && !$filterVenueId) {
            return response()->json(['status' => 'error', 'message' => 'facility_id requires venue_id'], 422);
        }

        // Validate and calculate date range
        $dateRange = $this->calculateDateRange($period, $startDate, $endDate);
        if (!$dateRange) {
            return response()->json(['status' => 'error', 'message' => 'Invalid date range parameters'], 422);
        }

        $filtersApplied = [
            'venue_id' => $filterVenueId,
            'facility_id' => $facilityId,
            'period' => $period,
            'date_range' => $dateRange,
        ];

        // Get facilities list if venue is selected
        $facilities = [];
        if ($filterVenueId) {
            $venue = Venue::with('facilities')->find($filterVenueId);
            if ($venue) {
                $facilities = $venue->facilities->map(function ($facility) {
                    return [
                        'id' => $facility->id,
                        'name' => $facility->name ?? $facility->type,
                        'type' => $facility->type,
                        'price_per_hr' => $facility->price_per_hr,
                    ];
                });
            }
        }

        // Build base query with filters
        $baseQuery = function() use ($venueIds, $facilityId, $dateRange) {
            $query = DB::table('events')
                ->whereIn('venue_id', $venueIds)
                ->whereNull('cancelled_at');

            // Apply facility filter
            if ($facilityId) {
                $query->where('facility_id', $facilityId);
            }

            // Apply date range filter
            if ($dateRange['start'] && $dateRange['end']) {
                $useDate = Schema::hasColumn('events', 'date');
                if ($useDate) {
                    $query->whereBetween('date', [$dateRange['start'], $dateRange['end']]);
                } else {
                    $query->whereBetween('created_at', [
                        $dateRange['start'] . ' 00:00:00',
                        $dateRange['end'] . ' 23:59:59'
                    ]);
                }
            }

            return $query;
        };

        // detect revenue column on events
        $possible = ['price','total_fee','amount','fee','price_per_booking'];
        $revenueColumn = null;
        foreach ($possible as $col) {
            if (Schema::hasColumn('events', $col)) {
                $revenueColumn = $col;
                break;
            }
        }

        // total events (exclude cancelled)
        $totalEvents = $baseQuery()->count();

        // totalRevenue: prefer events.{revenueColumn} else join facilities.price_per_hr
        if ($revenueColumn) {
            $totalRevenue = (float) $baseQuery()->sum($revenueColumn);
        } elseif (Schema::hasTable('facilities') && Schema::hasColumn('facilities', 'price_per_hr')) {
            $revenueQuery = DB::table('events')
                ->join('facilities', 'events.facility_id', '=', 'facilities.id')
                ->whereIn('events.venue_id', $venueIds)
                ->whereNull('events.cancelled_at');

            if ($facilityId) {
                $revenueQuery->where('events.facility_id', $facilityId);
            }

            if ($dateRange['start'] && $dateRange['end']) {
                $useDate = Schema::hasColumn('events', 'date');
                if ($useDate) {
                    $revenueQuery->whereBetween('events.date', [$dateRange['start'], $dateRange['end']]);
                } else {
                    $revenueQuery->whereBetween('events.created_at', [
                        $dateRange['start'] . ' 00:00:00',
                        $dateRange['end'] . ' 23:59:59'
                    ]);
                }
            }

            $totalRevenue = (float) $revenueQuery->sum('facilities.price_per_hr');
        } else {
            $totalRevenue = 0;
        }

        // total participants (use event_participants if available)
        if (Schema::hasTable('event_participants')) {
            $participantsQuery = DB::table('event_participants')
                ->join('events', 'event_participants.event_id', '=', 'events.id')
                ->whereIn('events.venue_id', $venueIds)
                ->whereNull('events.cancelled_at');

            if ($facilityId) {
                $participantsQuery->where('events.facility_id', $facilityId);
            }

            if ($dateRange['start'] && $dateRange['end']) {
                $useDate = Schema::hasColumn('events', 'date');
                if ($useDate) {
                    $participantsQuery->whereBetween('events.date', [$dateRange['start'], $dateRange['end']]);
                } else {
                    $participantsQuery->whereBetween('events.created_at', [
                        $dateRange['start'] . ' 00:00:00',
                        $dateRange['end'] . ' 23:59:59'
                    ]);
                }
            }

            $totalParticipants = (int) $participantsQuery->count();
        } else {
            $totalParticipants = 0;
        }

        $averageParticipants = $totalEvents > 0 ? round($totalParticipants / $totalEvents, 2) : 0;

        // recent events - use date range if specified, otherwise last 7 days
        $recentEventsQuery = DB::table('events')
            ->whereIn('venue_id', $venueIds)
            ->whereNull('cancelled_at');

        if ($facilityId) {
            $recentEventsQuery->where('facility_id', $facilityId);
        }

        if ($dateRange['start'] && $dateRange['end']) {
            $useDate = Schema::hasColumn('events', 'date');
            if ($useDate) {
                $recentEventsQuery->whereBetween('date', [$dateRange['start'], $dateRange['end']])
                    ->orderBy('date', 'desc');
            } else {
                $recentEventsQuery->whereBetween('created_at', [
                    $dateRange['start'] . ' 00:00:00',
                    $dateRange['end'] . ' 23:59:59'
                ])->orderBy('created_at', 'desc');
            }
        } else {
            $recentEventsQuery->where('created_at', '>=', now()->subDays(7))
                ->orderBy('created_at', 'desc');
        }

        $recentEvents = $recentEventsQuery
            ->select('id', 'venue_id', 'name', 'created_at', 'date', 'facility_id')
            ->limit(20)
            ->get()
            ->map(fn($e) => [
                'id' => $e->id,
                'venue_id' => $e->venue_id,
                'name' => $e->name,
                'date' => $e->date ?? \Carbon\Carbon::parse($e->created_at)->toDateString(),
                'facility_id' => $e->facility_id ?? null,
            ]);

        // weekly revenue calculation - adapt to date range or use current week
        $useDate = Schema::hasColumn('events', 'date');
        $weeklyRevenueDays = $this->getWeeklyRevenueDays($dateRange);
        
        $weeklyRevenue = collect($weeklyRevenueDays)->map(function ($day) use ($venueIds, $facilityId, $revenueColumn, $useDate, $dateRange) {
            if ($revenueColumn) {
                $q = DB::table('events')
                    ->whereIn('venue_id', $venueIds)
                    ->whereNull('cancelled_at');
                if ($facilityId) $q->where('facility_id', $facilityId);
                
                // Apply date filter if custom range, otherwise filter by day
                if ($dateRange['start'] && $dateRange['end'] && $dateRange['period'] === 'custom') {
                    if ($useDate) {
                        $q->where('date', '>=', $dateRange['start'])
                          ->where('date', '<=', $dateRange['end'])
                          ->whereDate('date', $day);
                    } else {
                        $q->whereDate('created_at', $day)
                          ->where('created_at', '>=', $dateRange['start'] . ' 00:00:00')
                          ->where('created_at', '<=', $dateRange['end'] . ' 23:59:59');
                    }
                } else {
                    if ($useDate) {
                        $q->where('date', $day->toDateString());
                    } else {
                        $q->whereDate('created_at', $day);
                    }
                }
                $revenue = (float) $q->sum($revenueColumn);
            } elseif (Schema::hasTable('facilities') && Schema::hasColumn('facilities', 'price_per_hr')) {
                $q = DB::table('events')
                    ->join('facilities', 'events.facility_id', '=', 'facilities.id')
                    ->whereIn('events.venue_id', $venueIds)
                    ->whereNull('events.cancelled_at');
                if ($facilityId) $q->where('events.facility_id', $facilityId);
                
                if ($dateRange['start'] && $dateRange['end'] && $dateRange['period'] === 'custom') {
                    if ($useDate) {
                        $q->where('events.date', '>=', $dateRange['start'])
                          ->where('events.date', '<=', $dateRange['end'])
                          ->whereDate('events.date', $day);
                    } else {
                        $q->whereDate('events.created_at', $day)
                          ->where('events.created_at', '>=', $dateRange['start'] . ' 00:00:00')
                          ->where('events.created_at', '<=', $dateRange['end'] . ' 23:59:59');
                    }
                } else {
                    if ($useDate) {
                        $q->where('events.date', $day->toDateString());
                    } else {
                        $q->whereDate('events.created_at', $day);
                    }
                }
                $revenue = (float) $q->sum('facilities.price_per_hr');
            } else {
                $revenue = 0;
            }

            return ['day' => $day->format('D'), 'revenue' => $revenue, 'date' => $day->toDateString()];
        });

        // per-venue breakdown (respect filters)
        $venues = Venue::whereIn('id', $venueIds)->get();
        $venuePerformance = $venues->map(function ($v) use ($revenueColumn, $facilityId, $dateRange) {
            $vid = $v->id;
            $eventsQuery = DB::table('events')->where('venue_id', $vid)->whereNull('cancelled_at');
            
            if ($facilityId) {
                $eventsQuery->where('facility_id', $facilityId);
            }
            
            if ($dateRange['start'] && $dateRange['end']) {
                $useDate = Schema::hasColumn('events', 'date');
                if ($useDate) {
                    $eventsQuery->whereBetween('date', [$dateRange['start'], $dateRange['end']]);
                } else {
                    $eventsQuery->whereBetween('created_at', [
                        $dateRange['start'] . ' 00:00:00',
                        $dateRange['end'] . ' 23:59:59'
                    ]);
                }
            }
            
            $eventsCount = $eventsQuery->count();

            if ($revenueColumn) {
                $earningsQuery = DB::table('events')->where('venue_id', $vid)->whereNull('cancelled_at');
                if ($facilityId) $earningsQuery->where('facility_id', $facilityId);
                if ($dateRange['start'] && $dateRange['end']) {
                    $useDate = Schema::hasColumn('events', 'date');
                    if ($useDate) {
                        $earningsQuery->whereBetween('date', [$dateRange['start'], $dateRange['end']]);
                    } else {
                        $earningsQuery->whereBetween('created_at', [
                            $dateRange['start'] . ' 00:00:00',
                            $dateRange['end'] . ' 23:59:59'
                        ]);
                    }
                }
                $earnings = (float) $earningsQuery->sum($revenueColumn);
            } elseif (Schema::hasTable('facilities') && Schema::hasColumn('facilities', 'price_per_hr')) {
                $earningsQuery = DB::table('events')
                    ->join('facilities', 'events.facility_id', '=', 'facilities.id')
                    ->where('events.venue_id', $vid)
                    ->whereNull('events.cancelled_at');
                if ($facilityId) $earningsQuery->where('events.facility_id', $facilityId);
                if ($dateRange['start'] && $dateRange['end']) {
                    $useDate = Schema::hasColumn('events', 'date');
                    if ($useDate) {
                        $earningsQuery->whereBetween('events.date', [$dateRange['start'], $dateRange['end']]);
                    } else {
                        $earningsQuery->whereBetween('events.created_at', [
                            $dateRange['start'] . ' 00:00:00',
                            $dateRange['end'] . ' 23:59:59'
                        ]);
                    }
                }
                $earnings = (float) $earningsQuery->sum('facilities.price_per_hr');
            } else {
                $earnings = 0;
            }

            $participantsQuery = DB::table('event_participants')
                ->join('events', 'event_participants.event_id', '=', 'events.id')
                ->where('events.venue_id', $vid)
                ->whereNull('events.cancelled_at');
                
            if ($facilityId) {
                $participantsQuery->where('events.facility_id', $facilityId);
            }
            
            if ($dateRange['start'] && $dateRange['end']) {
                $useDate = Schema::hasColumn('events', 'date');
                if ($useDate) {
                    $participantsQuery->whereBetween('events.date', [$dateRange['start'], $dateRange['end']]);
                } else {
                    $participantsQuery->whereBetween('events.created_at', [
                        $dateRange['start'] . ' 00:00:00',
                        $dateRange['end'] . ' 23:59:59'
                    ]);
                }
            }

            $participants = Schema::hasTable('event_participants')
                ? (int) $participantsQuery->count()
                : 0;

            return [
                'venue_id' => $v->id,
                'venue_name' => $v->name,
                'address' => $v->address,
                'events' => $eventsCount,
                'participants' => $participants,
                'earnings' => $earnings,
            ];
        })->values();

        // Revenue by facility breakdown (when venue is selected)
        $revenueByFacility = [];
        if ($filterVenueId && !$facilityId) {
            $venueFacilities = Facilities::where('venue_id', $filterVenueId)->get();
            foreach ($venueFacilities as $facility) {
                $facilityEventsQuery = DB::table('events')
                    ->where('venue_id', $filterVenueId)
                    ->where('facility_id', $facility->id)
                    ->whereNull('cancelled_at');

                if ($dateRange['start'] && $dateRange['end']) {
                    $useDate = Schema::hasColumn('events', 'date');
                    if ($useDate) {
                        $facilityEventsQuery->whereBetween('date', [$dateRange['start'], $dateRange['end']]);
                    } else {
                        $facilityEventsQuery->whereBetween('created_at', [
                            $dateRange['start'] . ' 00:00:00',
                            $dateRange['end'] . ' 23:59:59'
                        ]);
                    }
                }

                $facilityEvents = $facilityEventsQuery->count();
                
                if ($revenueColumn) {
                    $facilityRevenue = (float) $facilityEventsQuery->sum($revenueColumn);
                } elseif (Schema::hasColumn('facilities', 'price_per_hr')) {
                    $facilityRevenue = (float) $facilityEvents * $facility->price_per_hr;
                } else {
                    $facilityRevenue = 0;
                }

                $revenueByFacility[] = [
                    'facility_id' => $facility->id,
                    'facility_name' => $facility->name ?? $facility->type,
                    'facility_type' => $facility->type,
                    'events' => $facilityEvents,
                    'revenue' => $facilityRevenue,
                ];
            }
        }

        $response = [
            'status' => 'success',
            'analytics' => [
                'filters_applied' => $filtersApplied,
                'summary' => [
                    'revenue' => $totalRevenue,
                    'events' => $totalEvents,
                    'participants' => $totalParticipants,
                    'average_participants' => $averageParticipants,
                ],
                'weekly_revenue' => $weeklyRevenue,
                'recent_events' => $recentEvents,
                'venue_performance' => $venuePerformance,
            ]
        ];

        // Add facilities list if venue is selected
        if ($filterVenueId) {
            $response['analytics']['facilities'] = $facilities;
        }

        // Add revenue by facility if venue selected but no specific facility
        if (!empty($revenueByFacility)) {
            $response['analytics']['revenue_by_facility'] = $revenueByFacility;
        }

        return response()->json($response);
    }

    /**
     * Calculate date range based on period parameter
     */
    private function calculateDateRange($period, $startDate = null, $endDate = null)
    {
        switch ($period) {
            case 'this_week':
                return [
                    'period' => 'this_week',
                    'start' => now()->startOfWeek()->toDateString(),
                    'end' => now()->endOfWeek()->toDateString(),
                ];
            
            case 'month':
                return [
                    'period' => 'month',
                    'start' => now()->startOfMonth()->toDateString(),
                    'end' => now()->endOfMonth()->toDateString(),
                ];
            
            case 'semi_annual':
                return [
                    'period' => 'semi_annual',
                    'start' => now()->subMonths(6)->startOfDay()->toDateString(),
                    'end' => now()->endOfDay()->toDateString(),
                ];
            
            case 'annual':
                return [
                    'period' => 'annual',
                    'start' => now()->subMonths(12)->startOfDay()->toDateString(),
                    'end' => now()->endOfDay()->toDateString(),
                ];
            
            case 'custom':
                if (!$startDate || !$endDate) {
                    return null; // Invalid custom range
                }
                try {
                    $start = Carbon::parse($startDate)->toDateString();
                    $end = Carbon::parse($endDate)->toDateString();
                    if ($start > $end) {
                        return null; // Invalid range
                    }
                    return [
                        'period' => 'custom',
                        'start' => $start,
                        'end' => $end,
                    ];
                } catch (\Exception $e) {
                    return null; // Invalid date format
                }
            
            case 'all':
            default:
                return [
                    'period' => 'all',
                    'start' => null,
                    'end' => null,
                ];
        }
    }

    /**
     * Get days for weekly revenue calculation based on date range
     */
    private function getWeeklyRevenueDays($dateRange)
    {
        // If custom range spans more than a week, use the range's days
        // Otherwise, use current week (Mon-Sun)
        if ($dateRange['period'] === 'custom' && $dateRange['start'] && $dateRange['end']) {
            $start = Carbon::parse($dateRange['start']);
            $end = Carbon::parse($dateRange['end']);
            $daysDiff = $start->diffInDays($end);
            
            // If range is 7 days or less, return those days
            if ($daysDiff <= 7) {
                $days = [];
                $current = $start->copy();
                while ($current->lte($end)) {
                    $days[] = $current->copy();
                    $current->addDay();
                }
                return $days;
            }
        }
        
        // Default to current week (Mon-Sun)
        return collect(range(0, 6))->map(function ($i) {
            return now()->startOfWeek()->addDays($i);
        });
    }

    /**
     * Get facilities list for a venue (lightweight endpoint for dropdown)
     */
    public function getFacilitiesList($venueId)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        $venue = Venue::find($venueId);
        if (!$venue) {
            return response()->json(['status' => 'error', 'message' => 'Venue not found'], 404);
        }

        // Check if user owns/manages this venue
        $isCreator = $user->id === $venue->created_by;
        $isVenueUserOwner = false;
        if (class_exists(\App\Models\VenueUser::class)) {
            $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
                ->where('user_id', $user->id)
                ->whereIn('role', ['owner', 'manager', 'Owner', 'Manager'])
                ->exists();
        }

        if (!$isCreator && !$isVenueUserOwner) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $facilities = Facilities::where('venue_id', $venueId)
            ->select('id', 'name', 'type', 'price_per_hr', 'capacity', 'covered')
            ->get()
            ->map(function ($facility) {
                return [
                    'id' => $facility->id,
                    'name' => $facility->name ?? $facility->type,
                    'type' => $facility->type,
                    'price_per_hr' => $facility->price_per_hr,
                    'capacity' => $facility->capacity,
                    'covered' => $facility->covered,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'venue_id' => $venueId,
                'facilities' => $facilities,
            ]
        ]);
    }
    
    // ============ OPERATING HOURS MANAGEMENT ============
    
    /**
     * Get operating hours for a venue
     */
    public function getOperatingHours($venueId)
    {
        $venue = Venue::findOrFail($venueId);
        $hours = VenueOperatingHours::where('venue_id', $venueId)->get();
        
        return response()->json([
            'status' => 'success',
            'data' => ['operating_hours' => $hours]
        ]);
    }
    
    /**
     * Add operating hours for a venue
     */
    public function addOperatingHours(Request $request, $venueId)
    {
        $venue = Venue::findOrFail($venueId);
        
        // Check ownership
        $user = auth()->user();
        $isCreator = $user && $user->id === $venue->created_by;
        $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'Manager'])
            ->exists();
        
        if (!$isCreator && !$isVenueUserOwner) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        
        $validated = $request->validate([
            'day_of_week' => 'required|integer|min:0|max:6',
            'open_time' => 'required|date_format:H:i:s',
            'close_time' => 'required|date_format:H:i:s',
            'is_closed' => 'nullable|boolean',
        ]);
        
        $validated['venue_id'] = $venueId;
        
        // Check if entry already exists for this day
        $existing = VenueOperatingHours::where('venue_id', $venueId)
            ->where('day_of_week', $validated['day_of_week'])
            ->first();
        
        if ($existing) {
            $existing->update($validated);
            return response()->json([
                'status' => 'success',
                'message' => 'Operating hours updated successfully',
                'data' => ['operating_hours' => $existing]
            ], 200);
        }
        
        $hours = VenueOperatingHours::create($validated);
        
        return response()->json([
            'status' => 'success',
            'message' => 'Operating hours added successfully',
            'data' => ['operating_hours' => $hours]
        ], 201);
    }
    
    /**
     * Update operating hours
     */
    public function updateOperatingHours(Request $request, $venueId, $id)
    {
        $hours = VenueOperatingHours::findOrFail($id);
        
        // Check ownership
        $user = auth()->user();
        $venue = $hours->venue;
        $isCreator = $user && $venue && $user->id === $venue->created_by;
        $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'Manager'])
            ->exists();
        
        if (!$isCreator && !$isVenueUserOwner) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        
        $validated = $request->validate([
            'open_time' => 'required|date_format:H:i:s',
            'close_time' => 'required|date_format:H:i:s',
            'is_closed' => 'nullable|boolean',
        ]);
        
        $hours->update($validated);
        
        return response()->json([
            'status' => 'success',
            'message' => 'Operating hours updated successfully',
            'data' => ['operating_hours' => $hours]
        ]);
    }
    
    /**
     * Delete operating hours
     */
    public function deleteOperatingHours($venueId, $id)
    {
        $hours = VenueOperatingHours::findOrFail($id);
        
        // Check ownership
        $user = auth()->user();
        $venue = $hours->venue;
        $isCreator = $user && $venue && $user->id === $venue->created_by;
        $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'Manager'])
            ->exists();
        
        if (!$isCreator && !$isVenueUserOwner) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        
        $hours->delete();
        
        return response()->json([
            'status' => 'success',
            'message' => 'Operating hours deleted successfully'
        ]);
    }
    
    // ============ AMENITIES MANAGEMENT ============
    
    /**
     * Get amenities for a venue
     */
    public function getAmenities($venueId)
    {
        $venue = Venue::findOrFail($venueId);
        $amenities = VenueAmenity::where('venue_id', $venueId)->get();
        
        return response()->json([
            'status' => 'success',
            'data' => ['amenities' => $amenities]
        ]);
    }
    
    /**
     * Add amenity for a venue
     */
    public function addAmenity(Request $request, $venueId)
    {
        $venue = Venue::findOrFail($venueId);
        
        // Check ownership
        $user = auth()->user();
        $isCreator = $user && $user->id === $venue->created_by;
        $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'Manager'])
            ->exists();
        
        if (!$isCreator && !$isVenueUserOwner) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'available' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);
        
        $validated['venue_id'] = $venueId;
        $amenity = VenueAmenity::create($validated);
        
        return response()->json([
            'status' => 'success',
            'message' => 'Amenity added successfully',
            'data' => ['amenity' => $amenity]
        ], 201);
    }
    
    /**
     * Update amenity
     */
    public function updateAmenity(Request $request, $venueId, $id)
    {
        $amenity = VenueAmenity::findOrFail($id);
        
        // Check ownership
        $user = auth()->user();
        $venue = $amenity->venue;
        $isCreator = $user && $venue && $user->id === $venue->created_by;
        $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'Manager'])
            ->exists();
        
        if (!$isCreator && !$isVenueUserOwner) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'available' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);
        
        $amenity->update($validated);
        
        return response()->json([
            'status' => 'success',
            'message' => 'Amenity updated successfully',
            'data' => ['amenity' => $amenity]
        ]);
    }
    
    /**
     * Delete amenity
     */
    public function deleteAmenity($venueId, $id)
    {
        $amenity = VenueAmenity::findOrFail($id);
        
        // Check ownership
        $user = auth()->user();
        $venue = $amenity->venue;
        $isCreator = $user && $venue && $user->id === $venue->created_by;
        $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'Manager'])
            ->exists();
        
        if (!$isCreator && !$isVenueUserOwner) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        
        $amenity->delete();
        
        return response()->json([
            'status' => 'success',
            'message' => 'Amenity deleted successfully'
        ]);
    }
    
    // ============ CLOSURE DATES MANAGEMENT ============
    
    /**
     * Get closure dates for a venue
     */
    public function getClosureDates($venueId)
    {
        $venue = Venue::findOrFail($venueId);
        $closures = VenueClosureDate::where('venue_id', $venueId)->get();
        
        return response()->json([
            'status' => 'success',
            'data' => ['closure_dates' => $closures]
        ]);
    }
    
    /**
     * Add closure date for a venue
     */
    public function addClosureDate(Request $request, $venueId)
    {
        $venue = Venue::findOrFail($venueId);
        
        // Check ownership
        $user = auth()->user();
        $isCreator = $user && $user->id === $venue->created_by;
        $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'Manager'])
            ->exists();
        
        if (!$isCreator && !$isVenueUserOwner) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        
        $validated = $request->validate([
            'closure_date' => 'required|date',
            'reason' => 'nullable|string|max:255',
            'all_day' => 'nullable|boolean',
            'start_time' => 'nullable|date_format:H:i:s',
            'end_time' => 'nullable|date_format:H:i:s',
        ]);
        
        $validated['venue_id'] = $venueId;
        $closure = VenueClosureDate::create($validated);
        
        return response()->json([
            'status' => 'success',
            'message' => 'Closure date added successfully',
            'data' => ['closure_date' => $closure]
        ], 201);
    }
    
    /**
     * Update closure date
     */
    public function updateClosureDate(Request $request, $venueId, $id)
    {
        $closure = VenueClosureDate::findOrFail($id);
        
        // Check ownership
        $user = auth()->user();
        $venue = $closure->venue;
        $isCreator = $user && $venue && $user->id === $venue->created_by;
        $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'Manager'])
            ->exists();
        
        if (!$isCreator && !$isVenueUserOwner) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        
        $validated = $request->validate([
            'closure_date' => 'required|date',
            'reason' => 'nullable|string|max:255',
            'all_day' => 'nullable|boolean',
            'start_time' => 'nullable|date_format:H:i:s',
            'end_time' => 'nullable|date_format:H:i:s',
        ]);
        
        $closure->update($validated);
        
        return response()->json([
            'status' => 'success',
            'message' => 'Closure date updated successfully',
            'data' => ['closure_date' => $closure]
        ]);
    }
    
    /**
     * Delete closure date
     */
    public function deleteClosureDate($venueId, $id)
    {
        $closure = VenueClosureDate::findOrFail($id);
        
        // Check ownership
        $user = auth()->user();
        $venue = $closure->venue;
        $isCreator = $user && $venue && $user->id === $venue->created_by;
        $isVenueUserOwner = VenueUser::where('venue_id', $venue->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'Manager'])
            ->exists();
        
        if (!$isCreator && !$isVenueUserOwner) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        
        $closure->delete();
        
        return response()->json([
            'status' => 'success',
            'message' => 'Closure date deleted successfully'
        ]);
    }
    
}
