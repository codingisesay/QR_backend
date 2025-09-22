<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Core\Tenant;

class TenantProvision extends Command
{
    protected $signature = 'tenant:provision {slug} {--name=} {--mode=schema} {--dbhost=127.0.0.1} {--dbport=3306}';
    protected $description = 'Create tenant row, optionally create DB and (optionally) run tenant migrations';

    public function handle()
    {
        $slug = Str::slug($this->argument('slug'));
        $mode = $this->option('mode');

        $tenant = Tenant::create([
            'slug' => $slug,
            'name' => $this->option('name') ?: ucfirst($slug),
            'status' => 'active',
            'plan_id' => null,
            'isolation_mode' => $mode,
            'db_host' => $this->option('dbhost'),
            'db_port' => $this->option('dbport'),
        ]);

        // If per-tenant DB/schema, create DB and user
        if (in_array($mode, ['schema','database'], true)) {
            $dbName = 't_' . $tenant->id;
            $dbUser = 't' . $tenant->id . substr(md5((string) microtime(true)), 0, 4);
            $dbPass = Str::random(24);

            try {
                DB::statement("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
                DB::statement("CREATE USER IF NOT EXISTS `{$dbUser}`@'%' IDENTIFIED BY '{$dbPass}'");
                DB::statement("GRANT ALL ON `{$dbName}`.* TO `{$dbUser}`@'%'");
                DB::statement("FLUSH PRIVILEGES");
            } catch (\Throwable $e) {
                $this->warn('DB/user create failed: ' . $e->getMessage());
            }

            $tenant->db_name = $dbName;
            $tenant->db_user = $dbUser;
            $tenant->db_pass = encrypt($dbPass);
            $tenant->save();

            // OPTIONAL: if you’ve created the TenantMigrate command below, uncomment this:
            // $this->call('tenant:migrate', ['slug' => $slug]);
        }

        $this->info("Provisioned tenant {$tenant->slug} (id={$tenant->id})");
        return self::SUCCESS;
    }
}
