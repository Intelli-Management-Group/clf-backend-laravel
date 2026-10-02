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
        User::updateOrCreate(
            ['email' => 'admin@clf.local'],
            [
                'name' => 'Admin',
                'email_verified_at' => now(),
                'password' => 'Admin@12345',
            ],
        );

        if (app()->environment(['local', 'testing'])) {
            $this->call(AdminSeeder::class);
        }
    }
}
