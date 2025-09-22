<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Core\Permission;

class CorePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Keep this list in sync with your controllers & middleware usage
        $keys = [
            // Plans / Tenants / Subscriptions (admin/core)
            'plan.read','plan.write',
            'tenant.read','tenant.create','tenant.write',
            'subscription.write',

            // RBAC inside tenant
            'role.read','role.write','user.read','user.write',

            // Domain examples
            'product.read','product.write',

            // Communications
            'comm.provider.read','comm.provider.write','comm.send',

            // Dashboard
            'dashboard.read',
        ];

        foreach ($keys as $key) {
            Permission::firstOrCreate(
                ['key' => $key],
                ['name' => ucfirst(str_replace('.', ' ', $key))]
            );
        }
    }
}
