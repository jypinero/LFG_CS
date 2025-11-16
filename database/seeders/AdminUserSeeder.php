<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
	public function run(): void
	{
		$adminRole = Role::where('name', 'admin')->first();
		if (!$adminRole) {
			$adminRole = Role::create(['name' => 'admin']);
		}

		$user = User::updateOrCreate(
			['email' => 'fquilantip@gmail.com'],
			[
				'first_name' => 'Francisco',
				'last_name' => 'Quilantip',
				'username' => 'francisco.quilantip',
				'password' => Hash::make('ciscoqui123'),
				'role_id' => $adminRole->id,
			]
		);
	}
}


