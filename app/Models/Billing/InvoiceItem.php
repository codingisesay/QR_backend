<?php

// app/Models/Billing/InvoiceItem.php

namespace App\Models\Billing;

class InvoiceItem extends BaseBillingModel
{
    protected $table = 'invoice_items';

    protected $fillable = [
        'invoice_id','type','description','quantity',
        'unit_price_cents','amount_cents','meta',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price_cents' => 'integer',
        'amount_cents' => 'integer',
        'meta' => 'array',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
