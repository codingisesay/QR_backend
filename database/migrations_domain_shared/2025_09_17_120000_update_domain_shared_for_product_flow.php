<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $c = 'domain_shared';

        // 1) Catalog BOM edges (A has B)
        if (!Schema::connection($c)->hasTable('product_components_s')) {
            Schema::connection($c)->create('product_components_s', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('parent_product_id')->index();
                $t->unsignedBigInteger('child_product_id')->index();
                $t->decimal('quantity', 12, 4)->default(1);
                $t->integer('sort_order')->default(0);
                $t->json('meta')->nullable();
                $t->timestamps();

                $t->unique(['tenant_id','parent_product_id','child_product_id'], 'uq_pc_s_parent_child');
                // FKs optional (enable if you manage order of migrations):
                // $t->foreign('parent_product_id')->references('id')->on('products_s')->restrictOnDelete();
                // $t->foreign('child_product_id')->references('id')->on('products_s')->restrictOnDelete();
            });
        }

        // 2) Product codes (each physical unit / QR)
        if (!Schema::connection($c)->hasTable('product_codes_s')) {
            Schema::connection($c)->create('product_codes_s', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('product_id')->index();
                $t->string('code', 96)->unique(); // ULID/NanoID/QR token
                $t->enum('kind', ['primary','component','batch'])->default('primary');
                $t->unsignedBigInteger('parent_code_id')->nullable()->index(); // convenience pointer (direct parent)
                $t->enum('status', ['active','revoked','consumed'])->default('active');
                $t->timestamp('minted_at')->useCurrent();
                $t->timestamp('printed_at')->nullable();
                $t->unsignedInteger('reprint_count')->default(0);
                $t->json('meta')->nullable();
                $t->timestamps();
                // $t->foreign('product_id')->references('id')->on('products_s')->restrictOnDelete();
                // $t->foreign('parent_code_id')->references('id')->on('product_codes_s')->nullOnDelete();
            });
        }

        // 3) Direct edges (parent_code -> child_code)
        if (!Schema::connection($c)->hasTable('product_code_edges_s')) {
            Schema::connection($c)->create('product_code_edges_s', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('parent_code_id')->index();
                $t->unsignedBigInteger('child_code_id')->index();
                $t->timestamps();

                $t->unique(['tenant_id','parent_code_id','child_code_id'], 'uq_pce_s_parent_child');
                // Enforce TREE (one parent per child). Remove if you want DAG:
                $t->unique(['tenant_id','child_code_id'], 'uq_pce_s_child_once');

                // $t->foreign('parent_code_id')->references('id')->on('product_codes_s')->cascadeOnDelete();
                // $t->foreign('child_code_id')->references('id')->on('product_codes_s')->cascadeOnDelete();
            });
        }

        // 4) Closure table (all ancestors/descendants with depth)
        if (!Schema::connection($c)->hasTable('product_code_links_s')) {
            Schema::connection($c)->create('product_code_links_s', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('ancestor_code_id')->index();
                $t->unsignedBigInteger('descendant_code_id')->index();
                $t->unsignedInteger('depth'); // 0=self, 1=parent, 2=grandparent,...
                $t->timestamps();

                $t->unique(['tenant_id','ancestor_code_id','descendant_code_id'], 'uq_pcl_s_pair');
                $t->index(['tenant_id','descendant_code_id','depth'], 'ix_pcl_s_desc_depth');
                $t->index(['tenant_id','ancestor_code_id','depth'], 'ix_pcl_s_anc_depth');

                // $t->foreign('ancestor_code_id')->references('id')->on('product_codes_s')->cascadeOnDelete();
                // $t->foreign('descendant_code_id')->references('id')->on('product_codes_s')->cascadeOnDelete();
            });
        }

        // 5) Optional: read-friendly views (no data duplication)
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE VIEW all_descendants_s AS
SELECT
  l.tenant_id,
  l.ancestor_code_id   AS code_id,
  l.descendant_code_id AS related_code_id,
  l.depth
FROM product_code_links_s l
WHERE l.depth >= 1
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE VIEW all_ancestors_s AS
SELECT
  l.tenant_id,
  l.descendant_code_id AS code_id,
  l.ancestor_code_id   AS related_code_id,
  l.depth
FROM product_code_links_s l
WHERE l.depth >= 1
SQL);
    }

    public function down(): void
    {
        $c = 'domain_shared';
        // Drop views first (if your DB requires)
        DB::unprepared('DROP VIEW IF EXISTS all_descendants_s;');
        DB::unprepared('DROP VIEW IF EXISTS all_ancestors_s;');

        foreach ([
            'product_code_links_s',
            'product_code_edges_s',
            'product_codes_s',
            'product_components_s',
        ] as $tbl) {
            Schema::connection($c)->dropIfExists($tbl);
        }
    }
};
