<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
// If your Plan model lives elsewhere, adjust this import
use App\Models\Core\Plan;

class PlansSeeder extends Seeder
{
    public function run(): void
    {
        // Define your plans once
        $plans = [
            [
                'name'  => 'Starter',
                'price' => 49.00,                // decimal column (existing)
                'period'=> 'monthly',
                'limits_json' => [
                    'qr_limit' => 10000,
                    'users'    => 5,
                    'products' => 5,
                ],
                // New (if columns exist)
                'price_cents'            => 4900,  // 49.00 * 100
                'included_qr_per_month'  => 10000,
                'overage_price_cents'    => 10,    // 0.10 per extra QR
                'is_active'             => true,
            ],
            [
                'name'  => 'Pro',
                'price' => 199.00,
                'period'=> 'monthly',
                'limits_json' => [
                    'qr_limit' => 100000,
                    'users'    => 25,
                    'products' => 10,
                ],
                'price_cents'            => 19900,
                'included_qr_per_month'  => 100000,
                'overage_price_cents'    => 8,     // 0.08 per extra QR
                'is_active'             => true,
            ],
            [
                'name'  => 'Enterprise',
                'price' => 999.00,
                'period'=> 'monthly',
                'limits_json' => [
                    'qr_limit' => 1000000,
                    'users'    => 100,
                    'products' => 15,
                ],
                'price_cents'            => 99900,
                'included_qr_per_month'  => 1000000,
                'overage_price_cents'    => 5,     // 0.05 per extra QR
                'is_active'             => true,
            ],
        ];

        // Detect optional columns once (so the seeder works with old/new schemas)
        $hasPriceCents   = Schema::hasColumn('plans', 'price_cents');
        $hasIncludedQr   = Schema::hasColumn('plans', 'included_qr_per_month');
        $hasOverageCents = Schema::hasColumn('plans', 'overage_price_cents');

        foreach ($plans as $p) {
            // Base attributes (always present in your current schema)
            $attrs = [
                'price'       => $p['price'],
                'period'      => $p['period'],
                'limits_json' => $p['limits_json'],
            ];

            // Optional attributes (only set if columns exist)
            if ($hasPriceCents)   { $attrs['price_cents']           = $p['price_cents']; }
            if ($hasIncludedQr)   { $attrs['included_qr_per_month'] = $p['included_qr_per_month']; }
            if ($hasOverageCents) { $attrs['overage_price_cents']   = $p['overage_price_cents']; }

            // 'name' is unique in your migration, so use it as the key
            Plan::updateOrCreate(
                ['name' => $p['name']],  // unique key
                $attrs
            );
        }
    }
}
