<?php
// app/Services/WalletService.php
namespace App\Services;

use App\Models\Billing\Wallet;
use App\Models\Billing\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WalletService
{
    public function credit(int $tenantId, int $amountCents, string $reason, ?array $meta = null, ?string $idempotencyKey = null): Wallet
    {
        return DB::transaction(function () use ($tenantId, $amountCents, $reason, $meta, $idempotencyKey) {
            $wallet = Wallet::where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey && WalletTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
                return $wallet; // already processed
            }

            $wallet->balance_cents += $amountCents;
            $wallet->save();

            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'amount_cents' => $amountCents,
                'balance_after_cents' => $wallet->balance_cents,
                'reason' => $reason,
                'meta' => $meta,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $wallet;
        });
    }

    public function debit(int $tenantId, int $amountCents, string $reason, ?array $meta = null, ?string $idempotencyKey = null): Wallet
    {
        return DB::transaction(function () use ($tenantId, $amountCents, $reason, $meta, $idempotencyKey) {
            $wallet = Wallet::where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey && WalletTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
                return $wallet; // already processed
            }

            if ($wallet->balance_cents < $amountCents) {
                // 402 Payment Required
                throw new HttpException(402, 'Insufficient wallet balance');
            }

            $wallet->balance_cents -= $amountCents;
            $wallet->save();

            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'amount_cents' => $amountCents,
                'balance_after_cents' => $wallet->balance_cents,
                'reason' => $reason,
                'meta' => $meta,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $wallet;
        });
    }
}
