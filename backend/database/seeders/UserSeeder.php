<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed the sole admin user used to log into the SPA.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => env('SEED_USER_EMAIL', 'admin@example.com')],
            [
                'name' => 'Admin',
                'password' => Hash::make(env('SEED_USER_PASSWORD', 'password')),
            ]
        );
    }
}
