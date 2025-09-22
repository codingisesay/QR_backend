<?php
// app/Services/Pricing.php
namespace App\Services;

use App\Models\Tenant;
use App\Models\Plan;

class Pricing
{
    /**
     * Return unit price (in cents) for a given event.
     * e.g. 'qr_generation'
     */
    public function unitPriceFor(string $event, Tenant $tenant): int
    {
        // fallback defaults
        $defaults = [
            'qr_generation' => 10, // ₹0.10 per QR as example
        ];

        $plan = $tenant->plan_id ? Plan::find($tenant->plan_id) : null;

        if ($event === 'qr_generation') {
            // Prefer plan overage price if defined, else default
            if ($plan && isset($plan->overage_price_cents) && $plan->overage_price_cents > 0) {
                return (int) $plan->overage_price_cents;
            }
            return $defaults['qr_generation'];
        }

        return $defaults[$event] ?? 0;
    }
}
