<?php
// app/Models/Billing/UsageEvent.php
namespace App\Models\Billing;

use App\Models\Tenant;

class UsageEvent extends BaseBillingModel
{
    protected $table = 'usage_events';

    protected $fillable = [
        'tenant_id','event_type','quantity','unit_price_cents',
        'occurred_at','invoice_id','idempotency_key','meta',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price_cents' => 'integer',
        'occurred_at' => 'datetime',
        'meta' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
