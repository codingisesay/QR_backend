<?php

// app/Models/Billing/Invoice.php

namespace App\Models\Billing;

use App\Models\Tenant;

class Invoice extends BaseBillingModel
{
    protected $table = 'invoices';

    protected $fillable = [
        'tenant_id','currency','status','period_start','period_end',
        'subtotal_cents','tax_cents','total_cents','bill_to_snapshot',
        'provider','provider_invoice_id','provider_status','issued_at','paid_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'subtotal_cents' => 'integer',
        'tax_cents'      => 'integer',
        'total_cents'    => 'integer',
        'bill_to_snapshot' => 'array',
        'issued_at' => 'datetime',
        'paid_at'   => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function getSubtotalAttribute(): float { return $this->subtotal_cents / 100; }
    public function getTaxAttribute(): float      { return $this->tax_cents / 100; }
    public function getTotalAttribute(): float    { return $this->total_cents / 100; }
}
