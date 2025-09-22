<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

use App\Models\User;
use App\Models\Core\{Tenant, Role, OrgMember, UserRole, Plan};

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $name = env('SEED_TENANT_NAME', 'Acme Inc');
        $slug = env('SEED_TENANT_SLUG', 'acme-inc');
        $mode = env('SEED_TENANT_MODE', 'shared'); // shared|schema|database
        $plan = Plan::first(); // safest default

        // Create or fetch tenant
        $tenant = Tenant::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'status' => 'active',
                'isolation_mode' => $mode,
                'plan_id' => $plan?->id
            ]
        );

        // Ensure an owner user exists and is attached
        $ownerEmail = env('SEED_OWNER_EMAIL', 'akashsngh681681@gmail.com');
        $owner = User::firstOrCreate(['email' => $ownerEmail]);
        if (method_exists($owner, 'getAuthPassword') && empty($owner->password_hash ?? $owner->password)) {
            // let SuperAdminSeeder set the password, or do it here if needed
        }

        $ownerRole = Role::firstOrCreate(['tenant_id'=>$tenant->id, 'key'=>'owner'], ['name'=>'Owner']);

        OrgMember::updateOrCreate(
            ['tenant_id'=>$tenant->id,'user_id'=>$owner->id],
            ['status'=>'active','joined_at'=>now()]
        );
        UserRole::updateOrCreate(
            ['tenant_id'=>$tenant->id,'user_id'=>$owner->id,'role_id'=>$ownerRole->id],
            []
        );
    }
}
