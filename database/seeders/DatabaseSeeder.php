<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@hsb.local'],
            [
                'name' => 'Administrator',
                'password' => 'password',
                'status' => User::STATUS_ACTIVE,
                'is_admin' => true,
            ],
        );
    }
}
