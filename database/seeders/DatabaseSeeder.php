<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Call other seeders in order
        $this->call([
            RoleSeeder::class,
            SportSeeder::class,
            AdminUserSeeder::class,
        ]);
        
        // Uncomment to create test users if needed
        // User::factory(10)->create();
    }
}
