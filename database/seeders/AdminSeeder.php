<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Crea o actualiza el usuario administrador admin@mayoreo.cloud.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@mayoreo.cloud'],
            [
                'name' => 'Admin',
                'password' => Hash::make(
                    env('ADMIN_PASSWORD', 'mayoreo-cloud-2026')
                ),
            ]
        );
    }
}
