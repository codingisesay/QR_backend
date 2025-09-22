<?php
namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use App\Models\Core\Tenant;

class TenantConnector
{
    public function activate(Tenant $tenant): string
    {
        // Treat 'schema' and 'database' the same: both use the tenant connection
        if (in_array($tenant->isolation_mode, ['schema','database'], true)) {
            Config::set('database.connections.tenant', [
                'driver'    => 'mysql',
                'host'      => $tenant->db_host,
                'port'      => $tenant->db_port ?: 3306,
                'database'  => $tenant->db_name,
                'username'  => $tenant->db_user,
                'password'  => $tenant->db_pass ? decrypt($tenant->db_pass) : null,
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_0900_ai_ci',
                'strict'    => true,
                'prefix'    => '',
                'engine'    => null,
            ]);
            DB::purge('tenant');
            DB::reconnect('tenant');
            app()->instance('tenant.conn', 'tenant');
        } else {
            // shared row-level mode
            app()->instance('tenant.conn', 'domain_shared');
        }

        app()->instance('tenant.id',   $tenant->id);
        app()->instance('tenant.slug', $tenant->slug);
        app()->instance('tenant.mode', $tenant->isolation_mode);

        return app('tenant.conn');
    }
}
