<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\EventGame;

class UnseedMockDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // adjust condition to target only seed data you want removed
        EventGame::where('challonge_match_id','like','mock_%')->delete();
    }
}
