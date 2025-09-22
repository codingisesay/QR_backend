<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

use App\Models\Core\{Tenant, Role, Permission, RolePermission};

class RolesAndMappingsSeeder extends Seeder
{
    public function run(): void
    {
        // Build a map of permission keys -> ids once
        $permIdByKey = Permission::pluck('id','key')->all();

        $ownerPerms = array_values($permIdByKey); // owner gets everything

        $adminKeys = [
            'product.read','product.write',
            'comm.provider.read','comm.provider.write','comm.send',
            'role.read','role.write','user.read','user.write',
            'dashboard.read',
            // admin also can read plans/tenants, but not create or write core-level
            'plan.read','tenant.read',
        ];
        $adminPerms = array_values(array_intersect_key($permIdByKey, array_flip($adminKeys)));

        $viewerKeys = ['product.read','dashboard.read'];
        $viewerPerms = array_values(array_intersect_key($permIdByKey, array_flip($viewerKeys)));

        Tenant::orderBy('id')->chunk(100, function ($tenants) use ($ownerPerms, $adminPerms, $viewerPerms) {
            foreach ($tenants as $tenant) {
                DB::transaction(function () use ($tenant, $ownerPerms, $adminPerms, $viewerPerms) {
                    // Create roles if missing
                    $owner = Role::firstOrCreate(['tenant_id'=>$tenant->id, 'key'=>'owner'], ['name'=>'Owner']);
                    $admin = Role::firstOrCreate(['tenant_id'=>$tenant->id, 'key'=>'admin'], ['name'=>'Admin']);
                    $viewer= Role::firstOrCreate(['tenant_id'=>$tenant->id, 'key'=>'viewer'], ['name'=>'Viewer']);

                    // Helper to replace mappings
                    $sync = function (int $roleId, array $permIds) use ($tenant) {
                        RolePermission::where('tenant_id', $tenant->id)->where('role_id', $roleId)->delete();
                        foreach ($permIds as $pid) {
                            RolePermission::firstOrCreate([
                                'tenant_id' => $tenant->id,
                                'role_id'   => $roleId,
                                'permission_id' => $pid,
                            ]);
                        }
                    };

                    $sync($owner->id,  $ownerPerms);
                    $sync($admin->id,  $adminPerms);
                    $sync($viewer->id, $viewerPerms);
                });
            }
        });
    }
}
