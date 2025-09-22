<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Core\Tenant;

class TenantHealth extends Command
{
    protected $signature = 'tenant:health {slug}';
    protected $description = 'Connectivity and schema health check for a tenant';

    public function handle()
    {
        $t = Tenant::where('slug', $this->argument('slug'))->firstOrFail();
        $r = ['tenant' => $t->slug, 'mode' => $t->isolation_mode];

        try {
            if (in_array($t->isolation_mode, ['schema','database'], true)) {
                // Activate tenant connection dynamically
                Config::set('database.connections.tenant', [
                    'driver'   => 'mysql',
                    'host'     => $t->db_host,
                    'port'     => $t->db_port ?: 3306,
                    'database' => $t->db_name,
                    'username' => $t->db_user,
                    'password' => $t->db_pass ? decrypt($t->db_pass) : null,
                    'charset'  => 'utf8mb4',
                    'collation'=> 'utf8mb4_0900_ai_ci',
                    'strict'   => true,
                ]);
                DB::purge('tenant'); DB::reconnect('tenant');

                $r['tenant_conn'] = 'ok';
                // Sample existence checks (adjust to your tables)
                $r['t_products']  = Schema::connection('tenant')->hasTable('t_products');
            } else {
                // Shared row-level mode
                DB::connection('domain_shared')->select('select 1');
                $r['shared_conn'] = 'ok';
                $r['products_s']  = Schema::connection('domain_shared')->hasTable('products_s');
            }
        } catch (\Throwable $e) {
            $r['error'] = $e->getMessage();
        }

        $this->line(json_encode($r, JSON_PRETTY_PRINT));
        return isset($r['error']) ? self::FAILURE : self::SUCCESS;
    }
}
