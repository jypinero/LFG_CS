<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Venue;
use App\Models\VenuePhoto;
use App\Models\Facilities;
use App\Models\FacilityPhoto;
use App\Models\VenueUser; // ADDED

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
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:4096',
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

        $venuePhoto = null;
        $imageUrl = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $imagePath = $file->storeAs('venue_photos', $fileName, 'public');

            $venuePhoto = VenuePhoto::create([
                'venue_id' => $venue->id,
                'image_path' => $imagePath,
                'uploaded_at' => now(),
            ]);
            $imageUrl = Storage::url($imagePath);
        }

        return response()->json([
            'message' => 'Venue created successfully',
            'venue' => $venue,
            'photo' => $venuePhoto,
            'image_url' => $imageUrl
        ], 201);
    }

    public function storeFacility(Request $request, $venueId){
        $validated = $request->validate([
            'price_per_hr' => 'required|numeric|min:0',
            'type' => 'required|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:4096',
        ]);

        //Create the Facility
        $facility = Facilities::create([
            'venue_id' => $venueId,
            'price_per_hr' => $validated['price_per_hr'],
            'type' => $validated['type'],
        ]);

        $facilityPhoto = null;
        $imgaeUrl = null;

        if ($request->hasFile('image')){
            $file = $request->file('image');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $imagePath = $file->storeAs('facility_photo', $fileName, 'public');

            $facilityPhoto = FacilityPhoto::create([
                'facility_id' => $facility->id,
                'image_path' => $imagePath,
                'uploaded_at' => now(),
            ]);
            $imageUrl = Storage::url($imagePath);
        }

        return response()->json([
            'facility_id' => $facility->id,
            'facility' => $facility,
            'photo' => $facilityPhoto,
            'image_url' => $imageUrl
        ], 201);
    }



    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
