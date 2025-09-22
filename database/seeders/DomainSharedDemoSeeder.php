<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

use App\Models\Core\Tenant;
use App\Models\DomainShared\Product;

class DomainSharedDemoSeeder extends Seeder
{
    public function run(): void
    {
        $slug = env('SEED_TENANT_SLUG', 'acme-inc');
        $tenant = Tenant::where('slug', $slug)->first();

        if (!$tenant) return;
        if ($tenant->isolation_mode !== 'shared') return; // only for shared DB demo

        $rows = [
            ['sku' => 'ACM-100', 'name' => 'Acme Widget', 'description' => 'Standard widget'],
            ['sku' => 'ACM-200', 'name' => 'Acme Widget Pro', 'description' => 'Pro widget'],
            ['sku' => 'ACM-300', 'name' => 'Acme Widget XL', 'description' => 'XL widget'],
        ];

        foreach ($rows as $r) {
            Product::updateOrCreate(
                ['tenant_id' => $tenant->id, 'sku' => $r['sku']],
                ['name' => $r['name'], 'description' => $r['description'] ?? null]
            );
        }
    }
}
