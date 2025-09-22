<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use App\Models\User;
use App\Models\Core\{Tenant, Role, OrgMember, UserRole};

class TenantOwnerAttachSeeder extends Seeder
{
    public function run(): void
    {
        $seedOwnerEmail = env('SEED_OWNER_EMAIL');   // optional
        $seedOwnerPass  = env('SEED_OWNER_PASS', 'secret123');

        Tenant::orderBy('id')->chunk(100, function ($tenants) use ($seedOwnerEmail, $seedOwnerPass) {
            foreach ($tenants as $tenant) {
                // Ensure Owner role exists
                $ownerRole = Role::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'key' => 'owner'],
                    ['name' => 'Owner', 'description' => 'Full access']
                );

                // If any owner already assigned, skip
                $hasOwner = UserRole::where('tenant_id', $tenant->id)
                    ->where('role_id', $ownerRole->id)
                    ->exists();
                if ($hasOwner) {
                    continue;
                }

                // Choose or create an owner user
                $user = null;

                if ($seedOwnerEmail) {
                    $user = User::firstOrCreate(['email' => $seedOwnerEmail]);
                    $this->ensurePasswordAndFlags($user, $seedOwnerPass);
                }

                if (!$user) {
                    $user = User::where('is_superadmin', true)->first();
                }

                if (!$user) {
                    $fallbackEmail = 'owner+' . $tenant->slug . '@example.com';
                    $user = User::firstOrCreate(['email' => $fallbackEmail]);
                    $this->ensurePasswordAndFlags($user, $seedOwnerPass);
                }

                // Attach to tenant
                OrgMember::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                    ['status' => 'active', 'joined_at' => now()]
                );

                UserRole::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $ownerRole->id],
                    []
                );
            }
        });
    }

    private function ensurePasswordAndFlags(User $user, string $plain): void
    {
        if (Schema::hasColumn('users','password_hash')) {
            if (!$user->password_hash) $user->password_hash = Hash::make($plain);
        } elseif (Schema::hasColumn('users','password')) {
            if (!$user->password) $user->password = Hash::make($plain);
        }
        if (Schema::hasColumn('users','is_superadmin')) {
            $user->is_superadmin = $user->is_superadmin ?? false;
        }
        $user->save();
    }
}
