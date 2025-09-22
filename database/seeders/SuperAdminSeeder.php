<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'akashsngh681681@gmail.com';
        $now   = now();

        $data = [
            'email'         => $email,
            'is_superadmin' => 1,
            'updated_at'    => $now,
            'created_at'    => $now,
        ];

        // Fill optional/required columns if present
        if (Schema::hasColumn('users', 'name')) {
            $data['name'] = 'Akash Singh'; // change if you want
        }

        // Prefer 'password' if it exists (Laravel default).
        if (Schema::hasColumn('users', 'password')) {
            $data['password'] = Hash::make('akash@1234'); // change if you want
        } elseif (Schema::hasColumn('users', 'password_hash')) {
            // Your earlier migration showed 'password_hash'
            $data['password_hash'] = Hash::make('akash@1234');
        }

        if (Schema::hasColumn('users', 'email_verified_at')) {
            $data['email_verified_at'] = $now;
        }

        // Upsert by email so re-running the seeder is safe
        $data['password'] = Hash::make('akash@1234');
        DB::table('users')->updateOrInsert(['email' => $email], $data);
    }
}
