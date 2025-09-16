<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Venue;
use App\Models\VenuePhoto;
use App\Models\Facilities;
use App\Models\FacilityPhoto;

class VenueController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $venue = Venue::all();
        $facilities = Facilities::all();

        return response()->json([
            'status' => 'success',
            'data' => [
                'venue' => $venue,
                'facilities' => $facilities
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
