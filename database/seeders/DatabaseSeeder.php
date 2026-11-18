<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create the ONLY administrator - this is the only way to create administrator
        // Root user credentials should be changed immediately after first login
        User::firstOrCreate(
            ['email' => 'root@gym.local'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('ChangeMe123!@#'),
                'role' => 'administrator',
                'email_verified_at' => now(),
            ]
        );

        $this->call([
            GymClassSeeder::class,
        ]);

        // Optional: Create test users for development
        // User::factory(10)->create();

        // User::firstOrCreate(
        //     ['email' => 'test@example.com'],
        //     [
        //         'name' => 'Test User',
        //         'password' => Hash::make('password'),
        //         'email_verified_at' => now(),
        //     ]
        // );
    }
}
