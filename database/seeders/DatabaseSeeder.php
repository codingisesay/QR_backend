<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CorePermissionsSeeder::class,
            PlansSeeder::class,
            SuperAdminSeeder::class,
            GlobalCommProviderSeeder::class,  // seeds only if env present (safe)
            RolesAndMappingsSeeder::class,    // creates roles+perm mappings for all existing tenants

                  // NEW (optional but recommended):
            DemoTenantSeeder::class,          // creates a demo tenant (default acme-inc)
            TenantOwnerAttachSeeder::class,   // ensures every tenant has an Owner user
            DomainSharedDemoSeeder::class,    // seeds demo products for shared tenants
        ]);
    }
}
