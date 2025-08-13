<?php


namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sport;

class SportSeeder extends Seeder
{
    public function run()
    {
        $sports = [
            ['name' => 'Basketball', 'category' => 'Team Sports', 'is_active' => true],
            ['name' => 'Volleyball', 'category' => 'Team Sports', 'is_active' => true],
            ['name' => 'Track & Field', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Swimming', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Pickleball', 'category' => 'Individual', 'is_active' => true],
            ['name' => 'Badminton', 'category' => 'Individual', 'is_active' => true],
        ];

        foreach ($sports as $sport) {
            Sport::create($sport);
        }
    }
}