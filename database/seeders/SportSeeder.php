<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sport;

class SportSeeder extends Seeder
{
    public function run()
    {
        $sports = [

            /* =========================
             * TEAM SPORTS (PH-FAMOUS)
             * ========================= */
            ['name' => 'Basketball', 'category' => 'Team Sports', 'is_active' => true],
            ['name' => '3x3 Basketball', 'category' => 'Team Sports', 'is_active' => true],
            ['name' => 'Volleyball', 'category' => 'Team Sports', 'is_active' => true],
            ['name' => 'Beach Volleyball', 'category' => 'Team Sports', 'is_active' => true],
            ['name' => 'Football (Soccer)', 'category' => 'Team Sports', 'is_active' => true],
            ['name' => 'Futsal', 'category' => 'Team Sports', 'is_active' => true],
            ['name' => 'Rugby Sevens', 'category' => 'Team Sports', 'is_active' => true],
            ['name' => 'Baseball', 'category' => 'Team Sports', 'is_active' => true],
            ['name' => 'Softball', 'category' => 'Team Sports', 'is_active' => true],

            /* =========================
             * INDIVIDUAL SPORTS (VERY POPULAR)
             * ========================= */
            ['name' => 'Athletics (Track & Field)', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Swimming', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Weightlifting', 'category' => 'Individual', 'is_active' => true], // 🇵🇭 Olympic gold
            ['name' => 'Boxing', 'category' => 'Individual', 'is_active' => true],        // 🇵🇭 Olympic medals
            ['name' => 'Taekwondo', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Judo', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Karate', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Wrestling', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Gymnastics', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Badminton', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Table Tennis', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Tennis', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Golf', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Archery', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Shooting', 'category' => 'Individual', 'is_active' => true],

            /* =========================
             * AQUATIC / WATER SPORTS
             * ========================= */
            ['name' => 'Diving', 'category' => 'Aquatic', 'is_active' => true],
            ['name' => 'Surfing', 'category' => 'Aquatic', 'is_active' => true],
            ['name' => 'Rowing', 'category' => 'Water Sports', 'is_active' => true],
            ['name' => 'Sailing', 'category' => 'Water Sports', 'is_active' => true],

            /* =========================
             * CYCLING / URBAN SPORTS
             * ========================= */
            ['name' => 'Cycling (Road)', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Cycling (BMX)', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Skateboarding', 'category' => 'Individual', 'is_active' => true],

            /* =========================
             * LOCAL / EMERGING (NON-OLYMPIC BUT PH-POPULAR)
             * ========================= */
            ['name' => 'Pickleball', 'category' => 'Recreational', 'is_active' => true],
            ['name' => 'Chess', 'category' => 'Mind Sports', 'is_active' => true],
        ];

        foreach ($sports as $sport) {
            Sport::updateOrCreate(
                ['name' => $sport['name']],
                $sport
            );
        }
    }
}