<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use Illuminate\Support\Facades\Crypt;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            'super_admin',
            'athletes',
            'organizer',
            'trainer',
            'venue_owner',
            'admin',
            'Team Manager',
        ];

        foreach ($roles as $roleName) {
			Role::firstOrCreate(['name' => $roleName]);
        }
    }
}