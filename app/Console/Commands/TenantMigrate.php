<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use App\Models\Core\Tenant;

class TenantMigrate extends Command
{
    protected $signature = 'tenant:migrate {slug} {--path=database/migrations_tenant}';
    protected $description = 'Run per-tenant migrations for one tenant';

    public function handle()
    {
        $tenant = Tenant::where('slug', $this->argument('slug'))->firstOrFail();

        if (!in_array($tenant->isolation_mode, ['schema','database'], true)) {
            $this->warn('Tenant is in shared mode; no per-tenant DB to migrate.');
            return self::SUCCESS;
        }

        // Activate connection
        Config::set('database.connections.tenant', [
            'driver'   => 'mysql',
            'host'     => $tenant->db_host,
            'port'     => $tenant->db_port ?: 3306,
            'database' => $tenant->db_name,
            'username' => $tenant->db_user,
            'password' => $tenant->db_pass ? decrypt($tenant->db_pass) : null,
            'charset'  => 'utf8mb4',
            'collation'=> 'utf8mb4_0900_ai_ci',
            'strict'   => true,
        ]);
        DB::purge('tenant'); DB::reconnect('tenant');

        $path = $this->option('path');
        $this->info("Migrating tenant [{$tenant->slug}] using path: {$path}");

        // Run migration for the tenant connection
        $code = Artisan::call('migrate', [
            '--path' => $path,
            '--database' => 'tenant',
            '--force' => true,
        ]);

        $this->line(Artisan::output());
        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }
}
