<?php
// app/Models/Billing/WalletTransaction.php

namespace App\Models\Billing;

class WalletTransaction extends BaseBillingModel
{
    protected $table = 'wallet_transactions';

    protected $fillable = [
        'wallet_id','type','amount_cents','balance_after_cents',
        'reason','meta','idempotency_key',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'balance_after_cents' => 'integer',
        'meta' => 'array',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
