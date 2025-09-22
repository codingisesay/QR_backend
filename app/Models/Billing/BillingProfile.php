<?php
// app/Models/Billing/BillingProfile.php

namespace App\Models\Billing;

use App\Models\Tenant;

class BillingProfile extends BaseBillingModel
{
    protected $table = 'billing_profiles';

    protected $fillable = [
        'tenant_id','mode','plan_id','currency','invoice_day_of_month','credit_limit_cents',
        'bill_to_name','bill_to_email','bill_to_phone','gstin',
        'addr_line1','addr_line2','city','state','zip','country',
        'provider','provider_customer_id',
    ];

    protected $casts = [
        'credit_limit_cents' => 'integer',
        'invoice_day_of_month' => 'integer',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan()
    {
        return $this->belongsTo(\App\Models\Plan::class);
    }

    public function getIsWalletModeAttribute(): bool
    {
        return $this->mode === 'wallet';
    }

    public function getIsInvoiceModeAttribute(): bool
    {
        return $this->mode === 'invoice';
    }
}
