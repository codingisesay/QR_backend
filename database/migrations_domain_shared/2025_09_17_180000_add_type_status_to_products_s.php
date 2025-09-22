<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Use the same connection you use for shared tables
        $c = config()->has('database.connections.domain_shared')
            ? 'domain_shared'
            : config('database.default', 'mysql');

        // Table names (your schema uses *_s shared suffix)
        $products   = Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';
        $components = Schema::connection($c)->hasTable('product_components_s') ? 'product_components_s' : 'product_components';

        // 1) Add columns if missing
        Schema::connection($c)->table($products, function (Blueprint $t) use ($c, $products) {
            if (!Schema::connection($c)->hasColumn($products, 'type')) {
                $t->string('type', 16)->default('standard')->after('name');
            }
            if (!Schema::connection($c)->hasColumn($products, 'status')) {
                $t->string('status', 16)->default('active')->after('type');
            }
            if (!Schema::connection($c)->hasColumn($products, 'meta')) {
                $t->json('meta')->nullable()->after('status');
            }
        });

        // 2) Back-fill type from components: composite if it has children
        if (Schema::connection($c)->hasTable($components)) {
            // MySQL/MariaDB flavor
            DB::connection($c)->unprepared("
                UPDATE {$products} p
                LEFT JOIN (
                    SELECT parent_product_id, COUNT(*) cnt
                    FROM {$components}
                    GROUP BY parent_product_id
                ) cc ON cc.parent_product_id = p.id
                SET p.type = CASE WHEN COALESCE(cc.cnt,0) > 0 THEN 'composite' ELSE 'standard' END
            ");
        }
    }

    public function down(): void
    {
        $c = config()->has('database.connections.domain_shared')
            ? 'domain_shared'
            : config('database.default', 'mysql');

        $products = Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';

        Schema::connection($c)->table($products, function (Blueprint $t) use ($c, $products) {
            if (Schema::connection($c)->hasColumn($products, 'meta'))   $t->dropColumn('meta');
            if (Schema::connection($c)->hasColumn($products, 'status')) $t->dropColumn('status');
            if (Schema::connection($c)->hasColumn($products, 'type'))   $t->dropColumn('type');
        });
    }
};
