<?php

use App\Models\Core\Tenant;

if (!function_exists('tenant')) {
    /** Get the bound Tenant model (or null) */
    function tenant(): ?Tenant {
        return app()->bound('tenant') ? app('tenant') : null;
    }
}

if (!function_exists('tenant_id')) {
    function tenant_id(): ?int {
        return app()->bound('tenant.id') ? (int) app('tenant.id') : null;
    }
}

if (!function_exists('tenant_conn')) {
    /** Get the connection name for this tenant (e.g., 'tenant' or 'domain_shared') */
    function tenant_conn(): ?string {
        return app()->bound('tenant.conn') ? (string) app('tenant.conn') : null;
    }
}
