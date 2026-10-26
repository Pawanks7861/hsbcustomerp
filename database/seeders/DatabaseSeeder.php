<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@hsb.local'],
            [
                'name' => 'Administrator',
                'password' => 'password',
            ],
        );

        if ($admin->wasRecentlyCreated) {
            $admin->forceFill([
                'status' => User::STATUS_ACTIVE,
                'is_admin' => true,
            ])->save();
        }
    }
}
