<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        // 1) Header
        $headerTenant = trim((string) $request->headers->get('X-Tenant', ''));

        // 2) Route param /t/{tenant}
        $routeTenant = $request->route('tenant');
        if (is_array($routeTenant)) $routeTenant = reset($routeTenant) ?: null;
        $routeTenant = $routeTenant ? trim((string) $routeTenant) : null;

        // 3) Subdomain
        $host = $request->getHost();
        $subdomainTenant = $this->extractSubdomain($host);

        // Priority: header > route > subdomain
        $candidate = null;
        if ($headerTenant !== '') {
            $candidate = $headerTenant;
        } elseif ($routeTenant) {
            $candidate = $routeTenant;
        } elseif ($subdomainTenant) {
            $candidate = $subdomainTenant;
        }

        // If both header and route present but different → conflict
        if ($headerTenant !== '' && $routeTenant && $headerTenant !== $routeTenant) {
            throw new HttpException(409, 'Tenant mismatch between X-Tenant header and URL.');
        }

        // Optional pass-through for superadmin w/o tenant
        if ($candidate === null) {
            $user = $request->user();
            if ($user && !empty($user->is_superadmin)) {
                return $next($request);
            }
            throw new HttpException(404, 'Tenant not active or not found.');
        }

        // Locate registry (connection + table) and fetch tenant
        [$regConn, $regTable] = $this->locateRegistry();
        $tenant = $this->findTenant($regConn, $regTable, $candidate);
        if (!$tenant) {
            throw new HttpException(404, 'Tenant not active or not found.');
        }

        // Bind tenant context
        app()->instance('tenant', $tenant);
        app()->instance('tenant.id',   (int) ($tenant->id ?? 0));
        app()->instance('tenant.slug', (string) ($tenant->slug ?? ''));
        app()->instance('tenant.mode', (string) ($tenant->isolation_mode ?? 'shared'));

        // Switch connection if needed
        $this->switchTenantConnection($tenant);

        return $next($request);
    }

    /** Try to find which connection/table holds the tenant registry. */
    protected function locateRegistry(): array
    {
        // Allow explicit override via config/env
        $overrideConn  = config('tenancy.registry_connection') ?? env('TENANCY_REGISTRY_CONNECTION');
        $overrideTable = config('tenancy.registry_table') ?? env('TENANCY_REGISTRY_TABLE');
        if ($overrideConn && $overrideTable) {
            if (Schema::connection($overrideConn)->hasTable($overrideTable)) {
                return [$overrideConn, $overrideTable];
            }
        }

        // Build candidate connection list: domain_shared (if any), default, then all others
        $all = array_keys(config('database.connections', []));
        $candidates = [];
        if (in_array('domain_shared', $all, true)) $candidates[] = 'domain_shared';
        $def = config('database.default', 'mysql');
        if (!in_array($def, $candidates, true)) $candidates[] = $def;
        foreach ($all as $c) if (!in_array($c, $candidates, true)) $candidates[] = $c;

        // Common registry table names (shared-suffixed first)
        $tables = [
            'tenants_s','tenants',
            'organizations_s','organizations',
            'orgs_s','orgs',
            'accounts_s','accounts',
            'companies_s','companies',
            'clients_s','clients',
        ];

        foreach ($candidates as $conn) {
            foreach ($tables as $t) {
                try {
                    if (Schema::connection($conn)->hasTable($t)) {
                        return [$conn, $t];
                    }
                } catch (\Throwable $e) {
                    // ignore connection errors while probing
                }
            }
        }

        throw new HttpException(500,
            'Tenant registry table not found on any configured connection. ' .
            'Set TENANCY_REGISTRY_CONNECTION and TENANCY_REGISTRY_TABLE in .env.'
        );
    }

    /** Find tenant by slug or id; accept different column conventions and active flags. */
    protected function findTenant(string $conn, string $table, string $slugOrId): ?object
    {
        $cols = [];
        try {
            $cols = Schema::connection($conn)->getColumnListing($table);
        } catch (\Throwable $e) {
            // continue; we’ll probe common columns below
        }

        $q = DB::connection($conn)->table($table);

        $hasId   = in_array('id', $cols, true);
        $hasSlug = in_array('slug', $cols, true);
        $hasCode = in_array('code', $cols, true);
        $hasName = in_array('name', $cols, true);

        if (ctype_digit($slugOrId) && $hasId) {
            $q->where('id', (int) $slugOrId);
        } else {
            // Prefer slug → code → name
            if     ($hasSlug) $q->where('slug', $slugOrId);
            elseif ($hasCode) $q->where('code', $slugOrId);
            elseif ($hasName) $q->where('name', $slugOrId);
            else return null; // no usable key column
        }

        $row = $q->first();
        if (!$row) return null;

        // Active checks (support multiple conventions)
        $isActive = true;
        if (in_array('status', $cols, true) && isset($row->status)) {
            $isActive = $isActive && ($row->status === 'active');
        }
        if (in_array('is_active', $cols, true) && isset($row->is_active)) {
            $isActive = $isActive && ((int) $row->is_active === 1);
        }
        if (in_array('disabled', $cols, true) && isset($row->disabled)) {
            $isActive = $isActive && ((int) $row->disabled === 0);
        }
        if (!$isActive) return null;

        // Normalize expected fields
        if (!isset($row->slug)) {
            if     ($hasSlug) $row->slug = $row->slug;
            elseif ($hasCode) $row->slug = $row->code;
            elseif ($hasName) $row->slug = $row->name;
            else              $row->slug = (string) ($row->id ?? '');
        }
        $row->isolation_mode = $row->isolation_mode ?? 'shared';
        $row->db_host        = $row->db_host        ?? null;
        $row->db_port        = $row->db_port        ?? 3306;
        $row->db_name        = $row->db_name        ?? null;
        $row->db_user        = $row->db_user        ?? null;
        $row->db_pass        = $row->db_pass        ?? null;

        return $row;
    }

    /** Extract first subdomain (tenant.example.com => 'tenant'). */
    protected function extractSubdomain(string $host): ?string
    {
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) return null;
        $parts = explode('.', $host);
        if (count($parts) < 3) return null; // example.com
        $sub = $parts[0];
        if (in_array($sub, ['www','app'], true)) return null;
        $sub = preg_replace('/[^A-Za-z0-9_.-]/', '', $sub);
        return $sub !== '' ? $sub : null;
    }

    /** Switch to tenant connection for per-database/schema isolation; else shared. */
    protected function switchTenantConnection(object $tenant): void
    {
        $mode = (string) ($tenant->isolation_mode ?? 'shared');

        if (in_array($mode, ['database','schema'], true)) {
            Config::set('database.connections.tenant', [
                'driver'    => 'mysql',
                'host'      => $tenant->db_host,
                'port'      => $tenant->db_port ?? 3306,
                'database'  => $tenant->db_name,
                'username'  => $tenant->db_user,
                'password'  => $tenant->db_pass,
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
                'strict'    => true,
            ]);
            DB::purge('tenant');
            DB::reconnect('tenant');
            app()->instance('tenant.conn', 'tenant');
        } else {
            app()->instance('tenant.conn', 'domain_shared'); // row-level
        }
    }
}
