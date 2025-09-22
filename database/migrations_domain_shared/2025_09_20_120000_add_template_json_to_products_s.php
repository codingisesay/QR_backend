<?php
// database/migrations/2025_09_20_120000_add_template_json_to_products_s.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $conn = config('database.connections.domain_shared') ? 'domain_shared' : null;

        if (Schema::connection($conn)->hasTable('products_s') &&
            !Schema::connection($conn)->hasColumn('products_s', 'template_json')) {
            Schema::connection($conn)->table('products_s', function (Blueprint $t) {
                // For MySQL 5.7+/MariaDB 10.2+, JSON is fine. If older, use longText.
                $t->json('template_json')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        $conn = config('database.connections.domain_shared') ? 'domain_shared' : null;

        if (Schema::connection($conn)->hasTable('products_s') &&
            Schema::connection($conn)->hasColumn('products_s', 'template_json')) {
            Schema::connection($conn)->table('products_s', function (Blueprint $t) {
                $t->dropColumn('template_json');
            });
        }
    }
};
