<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Venue;
use Carbon\Carbon;

class VenueSeeder extends Seeder
{
    public function run()
    {
        $venues = [
            [
                'name' => 'Olongapo City Sports Complex',
                'description' => 'Main sports complex for various events and tournaments.',
                'address' => 'Rizal Ave., East Tapinac, Olongapo City',
                'latitude' => 14.8333,
                'longitude' => 120.2833,
                'verified_at' => Carbon::now(),
                'verification_expires_at' => Carbon::now()->addYear(),
                'created_by' => 1,
            ],
            [
                'name' => 'Brgy. Barretto Covered Court',
                'description' => 'Covered court for basketball and volleyball.',
                'address' => 'Barretto, Olongapo City',
                'latitude' => 14.8600,
                'longitude' => 120.2640,
                'verified_at' => Carbon::now(),
                'verification_expires_at' => Carbon::now()->addYear(),
                'created_by' => 1,
            ],
            [
                'name' => 'Pag-Asa Covered Court',
                'description' => 'Community court for local sports activities.',
                'address' => 'Pag-Asa, Olongapo City',
                'latitude' => 14.8400,
                'longitude' => 120.2820,
                'verified_at' => Carbon::now(),
                'verification_expires_at' => Carbon::now()->addYear(),
                'created_by' => 1,
            ],
            [
                'name' => 'Sta. Rita Basketball Court',
                'description' => 'Open basketball court for Sta. Rita residents.',
                'address' => 'Sta. Rita, Olongapo City',
                'latitude' => 14.8450,
                'longitude' => 120.2750,
                'verified_at' => Carbon::now(),
                'verification_expires_at' => Carbon::now()->addYear(),
                'created_by' => 1,
            ],
        ];

        foreach ($venues as $venue) {
            Venue::create($venue);
        }
    }
}