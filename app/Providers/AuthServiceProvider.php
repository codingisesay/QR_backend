<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * If you add policies, wire them here.
     * protected $policies = [ Model::class => Policy::class ];
     */
    protected $policies = [];

    public function boot(): void
    {
        $this->registerPolicies();

        // Gate: 'perm' → tenant-scoped permission check
        Gate::define('perm', function ($user, string $permKey): bool {
            if (!$user) {
                return false;
            }

            // Global bypass
            if (!empty($user->is_superadmin)) {
                return true;
            }

            // Tenant required (ResolveTenant binds it)
            $tenant = app()->bound('tenant') ? app('tenant') : null;
            if (!$tenant) {
                return false;
            }
            $tenantId = $tenant->id;

            // Cache for 60 seconds to cut DB chatter
                $ck = "gate:perm:u{$user->id}:t{$tenantId}:p:{$permKey}";
        return Cache::remember($ck, 60, function () use ($user, $tenantId, $permKey) {
            return DB::table('user_roles as ur')              // or $db->table(...) if using the alt conn
                ->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->leftJoin('role_permissions as rp', 'rp.role_id', '=', 'r.id')
                ->leftJoin('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('ur.user_id', $user->id)
                ->where('ur.tenant_id', $tenantId)
                ->where(function ($q) use ($permKey) {
                    // IMPORTANT: roles **key** (not slug) & parameterized 'owner'
                    $q->where('r.key', '=', 'owner')
                      ->orWhere('p.key', '=', $permKey);
                })
                ->exists();
            });
        });
    }
}
