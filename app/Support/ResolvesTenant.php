<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ResolvesTenant
{
    protected function sharedConn(): string
    {
        return config('database.connections.domain_shared') ? 'domain_shared' : config('database.default', 'mysql');
    }

    protected function coreConn(): string
    {
        if (config('database.connections.core')) return 'core';
        if (config('database.connections.saas_core')) return 'saas_core';
        return config('database.default', 'mysql');
    }

    protected function tenant(Request $req): ?object
    {
        if (app()->bound('tenant')) return app('tenant');
        $core = $this->coreConn();
        if (!Schema::connection($core)->hasTable('tenants')) return null;

        // Header first (id or slug)
        $key = $req->header('X-Tenant');
        if ($key) {
            $q = DB::connection($core)->table('tenants');
            $t = ctype_digit($key) ? $q->where('id', (int)$key)->first() : $q->where('slug', $key)->first();
            if ($t) return $t;
        }

        // Fallback to auth
        $u = $req->user();
        if ($u && isset($u->tenant_id)) {
            return DB::connection($core)->table('tenants')->where('id', (int)$u->tenant_id)->first();
        }
        return null;
    }
}
