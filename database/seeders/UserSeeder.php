<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserAdditionalSport;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // User 1
        $sports1 = [
            ['id' => 2, 'level' => 'competitive'], // main sport
            ['id' => 1, 'level' => 'beginner'],    // additional sport
        ];

        $user1 = User::create([
            'first_name' => 'John',
            'middle_name' => 'A',
            'last_name' => 'Doe',
            'username' => 'johndoe',
            'email' => 'johndoe@example.com',
            'password' => Hash::make('password123'),
            'birthday' => '1990-01-01',
            'sex' => 'male',
            'contact_number' => '09123456789',
            'barangay' => 'Sample Barangay',
            'city' => 'Sample City',
            'province' => 'Sample Province',
            'zip_code' => '2200',
            'role_id' => 1,
            'profile_photo' => null,
        ]);

        UserProfile::create([
            'user_id' => $user1->id,
            'main_sport_id' => $sports1[0]['id'],
            'main_sport_level' => $sports1[0]['level'],
        ]);

        foreach (array_slice($sports1, 1) as $sport) {
            UserAdditionalSport::create([
                'user_id' => $user1->id,
                'sport_id' => $sport['id'],
                'level' => $sport['level'],
            ]);
        }

        // User 2
        $sports2 = [
            ['id' => 2, 'level' => 'professional'], // main sport
            ['id' => 1, 'level' => 'beginner'],     // additional sport
        ];

        $user2 = User::create([
            'first_name' => 'Jane',
            'middle_name' => 'B',
            'last_name' => 'Smith',
            'username' => 'janesmith',
            'email' => 'janesmith@example.com',
            'password' => Hash::make('password456'),
            'birthday' => '1992-02-02',
            'sex' => 'female',
            'contact_number' => '09987654321',
            'barangay' => 'Another Barangay',
            'city' => 'Another City',
            'province' => 'Another Province',
            'zip_code' => '3300',
            'role_id' => 1,
            'profile_photo' => null,
        ]);

        UserProfile::create([
            'user_id' => $user2->id,
            'main_sport_id' => $sports2[0]['id'],
            'main_sport_level' => $sports2[0]['level'],
        ]);

        foreach (array_slice($sports2, 1) as $sport) {
            UserAdditionalSport::create([
                'user_id' => $user2->id,
                'sport_id' => $sport['id'],
                'level' => $sport['level'],
            ]);
        }
    }
}
