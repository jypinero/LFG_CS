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
            'image' => 'nullable',
            'image.*' => 'image|mimes:jpeg,png,jpg,gif|max:4096',
        ]);

        // Create the Facility
        $facility = Facilities::create([
            'venue_id' => $venueId,
            'price_per_hr' => $validated['price_per_hr'],
            'type' => $validated['type'],
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
        $venue = Venue::with(['photos', 'facilities.photos'])->find($id);
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
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:4096',
        ]);

        // collect allowed fields from raw request (so JSON/form-data both work)
        $allowed = ['name','description','address','latitude','longitude','verified_at','verification_expires_at'];
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
            'image.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        $facility = Facilities::findOrFail($facilityId);
        $addedPhotos = [];

        if ($request->hasFile('image')) {
            foreach ($request->file('image') as $imageFile) {
                $path = $imageFile->store('facility_photo', 'public');

                $photo = $facility->photos()->create([
                    'image_path' => $path,
                ]);

                $addedPhotos[] = $photo;
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Photos added to facility',
            'added_photos' => $addedPhotos,
            'facility' => $facility->load('photos')
        ]);
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

            return response()->json(['status' => 'success', 'message' => 'Facility photo deleted'], 200);
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
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
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
    
}
