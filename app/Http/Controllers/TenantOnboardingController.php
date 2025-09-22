<?php
// app/Http/Controllers/TenantOnboardingController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Models\User;
use App\Models\Billing\BillingProfile;
use App\Models\Billing\Wallet;
use App\Services\WalletService;
use App\Notifications\TenantPendingPayment;
use App\Notifications\TenantActivated;
use App\Models\Core\{Tenant, Role, Permission, OrgMember, UserRole, Plan};

class TenantOnboardingController extends Controller
{
    /**
     * POST /tenants/init
     * Body: { name, slug, mode: 'wallet'|'invoice', plan_id?, topup_cents?, provider?: 'stripe'|'razorpay' }
     */
    public function init(Request $req)
    {
        $data = $req->validate([
            'name'        => 'required|string|max:120',
            'slug'        => 'required|string|max:60|alpha_dash|unique:tenants,slug',
            'mode'        => 'required|in:wallet,invoice',
            'plan_id'     => 'nullable|integer|exists:plans,id',
            'topup_cents' => 'nullable|integer|min:0',
            'provider'    => 'nullable|in:stripe,razorpay',
        ]);

        $user     = $req->user();
        $provider = $data['provider'] ?? 'stripe';

        // 1) Create tenant (pending) + roles + owner membership + billing profile + wallet
        $tenant = DB::transaction(function () use ($data, $user) {
            $tenant = Tenant::create([
                'name'           => $data['name'],
                'slug'           => $data['slug'],
                'status'         => 'pending_payment',
                'plan_id'        => $data['plan_id'] ?? null,
                'isolation_mode' => 'shared', // keep default; do NOT tie to billing mode
            ]);

            // Ensure roles exist for this tenant
            $owner  = Role::firstOrCreate(['tenant_id' => $tenant->id, 'key' => 'owner'],  ['name' => 'Owner']);
            $admin  = Role::firstOrCreate(['tenant_id' => $tenant->id, 'key' => 'admin'],  ['name' => 'Admin']);
            $viewer = Role::firstOrCreate(['tenant_id' => $tenant->id, 'key' => 'viewer'], ['name' => 'Viewer']);

            // permissions → your existing Permission keys
        $neededKeys = [
            'dashboard.read',
            'product.read','product.write','user.read',
            'user.write',
            'role.read','role.write',
            'comm.provider.read','comm.provider.write','comm.send',
            'tenant.write','subscription.write',
        ];
        $permIds = Permission::whereIn('key', $neededKeys)->pluck('id','key')->all();

        $syncFor = function(array $keys) use ($permIds, $tenant) {
            $sync = [];
            foreach ($keys as $k) if (isset($permIds[$k])) $sync[$permIds[$k]] = ['tenant_id'=>$tenant->id];
            return $sync;
        };

        // map
        $owner->permissions()->sync($syncFor(array_keys($permIds)));
        $admin->permissions()->sync($syncFor([
            'dashboard.read','product.read','product.write','user.write','role.read','role.write','comm.provider.read','comm.send',
        ]));
        $viewer->permissions()->sync($syncFor(['dashboard.read','product.read']));


            // Add the creator as an org member (active) and give them OWNER role
            OrgMember::updateOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['status' => 'active', 'joined_at' => now()]
            );
            UserRole::updateOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $owner->id],
                []
            );

            // Billing profile + wallet
            BillingProfile::create([
                'tenant_id' => $tenant->id,
                'mode'      => $data['mode'],             // wallet | invoice
                'plan_id'   => $data['plan_id'] ?? null,
                'currency'  => 'INR',
            ]);

            Wallet::firstOrCreate(
                ['tenant_id' => $tenant->id],
                ['balance_cents' => 0, 'currency' => 'INR']
            );

            return $tenant;
        });

        // 2) Email owner – pending payment
        try {
            $user->notify(new TenantPendingPayment($tenant));
        } catch (\Throwable $e) {
            Log::warning('TenantPendingPayment notify failed: '.$e->getMessage());
        }

        // 3) Create checkout with selected provider
        if ($provider === 'stripe') {
            return $this->createStripeCheckout($tenant, $data, $user);
        }
        return $this->createRazorpayOrder($tenant, $data, $user);
    }

    /** GET /tenants/{id}/status */
    public function status($id)
    {
        $t = Tenant::findOrFail($id);
        return response()->json(['id' => $t->id, 'slug' => $t->slug, 'status' => $t->status]);
    }

    // ----------------- STRIPE -----------------

    protected function createStripeCheckout(Tenant $tenant, array $data, User $user)
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

        $currency = 'inr';
        $front = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');
        $success = $front.'/tenant/thank-you?tenant='.$tenant->slug.'&tid='.$tenant->id.'&provider=stripe';
        $cancel  = $front.'/tenant/create?cancel=1';

        if ($data['mode'] === 'wallet') {
            $amount = max((int)($data['topup_cents'] ?? 0), 5000); // min ₹50
            $session = \Stripe\Checkout\Session::create([
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => ['name' => 'Wallet top-up for '.$tenant->name],
                        'unit_amount' => $amount,
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => $success.'&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $cancel,
                'metadata'    => [
                    'tenant_id' => (string)$tenant->id,
                    'user_id'   => (string)$user->id,
                    'type'      => 'wallet_topup_initial',
                ],
            ]);
            return response()->json([
                'provider' => 'stripe',
                'tenant'   => ['id'=>$tenant->id, 'slug'=>$tenant->slug, 'status'=>$tenant->status],
                'mode'     => 'wallet',
                'checkout' => ['session_id'=>$session->id, 'public_key'=>config('services.stripe.key')],
            ]);
        }

        $plan   = $tenant->plan_id ? Plan::find($tenant->plan_id) : null;
        $amount = $plan ? (int)$plan->price_cents : 49900;

        $session = \Stripe\Checkout\Session::create([
            'mode' => 'payment', // or 'subscription' if you have a Stripe Price ID
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => ['name' => 'First month - '.$tenant->name],
                    'unit_amount' => $amount,
                ],
                'quantity' => 1,
            ]],
            'success_url' => $success.'&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $cancel,
            'metadata'    => [
                'tenant_id' => (string)$tenant->id,
                'user_id'   => (string)$user->id,
                'type'      => 'first_month_invoice',
            ],
        ]);
        return response()->json([
            'provider' => 'stripe',
            'tenant'   => ['id'=>$tenant->id, 'slug'=>$tenant->slug, 'status'=>$tenant->status],
            'mode'     => 'invoice',
            'checkout' => ['session_id'=>$session->id, 'public_key'=>config('services.stripe.key')],
        ]);
    }

    /** POST /webhooks/stripe */
    public function stripeWebhook(Request $req)
    {
        $payload = $req->getContent();
        $sig     = $req->header('Stripe-Signature');
        $secret  = config('services.stripe.webhook_secret');

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig, $secret);
        } catch (\Throwable $e) {
            Log::warning('Stripe webhook verify failed: '.$e->getMessage());
            return response()->json(['error' => 'invalid'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            /** @var \Stripe\Checkout\Session $session */
            $session  = $event->data->object;
            $tenantId = (int) ($session->metadata->tenant_id ?? 0);
            $userId   = (int) ($session->metadata->user_id ?? 0);
            $type     = $session->metadata->type ?? null;

            DB::transaction(function () use ($tenantId, $userId, $type, $session) {
                $tenant = Tenant::lockForUpdate()->find($tenantId);
                if (!$tenant) return;

                if ($type === 'wallet_topup_initial') {
                    try {
                        app(WalletService::class)->credit(
                            $tenant->id,
                            (int) ($session->amount_total ?? 0),
                            'topup',
                            ['provider' => 'stripe', 'session' => $session->id],
                            $session->id // idempotency
                        );
                    } catch (\Throwable $e) {
                        Log::error('Initial topup credit failed: '.$e->getMessage());
                    }
                }

                if ($tenant->status !== 'active') {
                    $tenant->status = 'active';
                    $tenant->save();

                    if ($user = User::find($userId)) {
                        try {
                            $user->notify(new TenantActivated($tenant));
                        } catch (\Throwable $e) {
                            Log::warning('TenantActivated notify failed: '.$e->getMessage());
                        }
                    }
                }
            });
        }

        return response()->json(['ok' => true]);
    }

    // ----------------- Razorpay stubs (optional) -----------------

    protected function createRazorpayOrder(Tenant $tenant, array $data, User $user)
    {
        // Implement using razorpay/razorpay SDK and return:
        return response()->json([
            'provider' => 'razorpay',
            'tenant'   => ['id'=>$tenant->id, 'slug'=>$tenant->slug, 'status'=>$tenant->status],
            'mode'     => $data['mode'],
            'checkout' => ['order_id' => 'order_xxx', 'key_id' => config('services.razorpay.key_id')],
        ]);
    }

    public function razorpayWebhook(Request $req)
    {
        // Verify signature, credit wallet if needed, set active, notify owner
        return response()->json(['ok' => true]);
    }
}
