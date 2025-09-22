<?php
// app/Models/Billing/Wallet.php
namespace App\Models\Billing;

use App\Models\Tenant;

class Wallet extends BaseBillingModel
{
    protected $table = 'wallets';

    protected $fillable = ['tenant_id','balance_cents','currency'];

    protected $casts = ['balance_cents' => 'integer'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    // Convenience accessors for UI (read-only)
    public function getBalanceAttribute(): float
    {
        return $this->balance_cents / 100;
    }
}
