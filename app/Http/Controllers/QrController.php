<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QrController extends Controller
{
    /* ---------- connections & helpers ---------- */

    protected function sharedConn(): string {
        if (config('database.connections.domain_shared')) return 'domain_shared';
        return config('database.default', 'mysql');
    }
    protected function coreConn(): string {
        if (config('database.connections.core')) return 'core';
        if (config('database.connections.saas_core')) return 'saas_core';
        return config('database.default', 'mysql');
    }
    protected function base64url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
    protected function k2ForTenant(int $tenantId): string {
        return hash('sha256', config('app.key').'|k2|'.$tenantId, true);
    }
    protected function verifyBase(): string {
        return config('app.verify_base', 'https://verify.your-domain.com');
    }

    /** Resolve tenant safely: container → X-Tenant header (slug/id) → user->tenant_id. */
    protected function tenant(Request $req): ?object
    {
        if (app()->bound('tenant')) return app('tenant');

        $core = $this->coreConn();
        if (!Schema::connection($core)->hasTable('tenants')) return null;

        $key = $req->header('X-Tenant');
        if ($key !== null && $key !== '') {
            $q = DB::connection($core)->table('tenants');
            $tenant = ctype_digit($key)
                ? $q->where('id', (int)$key)->first()
                : $q->where('slug', $key)->first();
            if ($tenant) return $tenant;
        }

        $user = $req->user();
        if ($user && isset($user->tenant_id)) {
            $tenant = DB::connection($core)->table('tenants')->where('id', (int)$user->tenant_id)->first();
            if ($tenant) return $tenant;
        }
        return null;
    }

    /* ---------- plan limits ---------- */

    protected function planQrLimits(object $tenant): array
    {
        $core = $this->coreConn();
        $planId = null;

        if (Schema::connection($core)->hasTable('subscriptions')) {
            $subQ = DB::connection($core)->table('subscriptions')->where('tenant_id', $tenant->id);
            if (Schema::connection($core)->hasColumn('subscriptions','status')) $subQ->whereIn('status',['active','trialing']);
            elseif (Schema::connection($core)->hasColumn('subscriptions','is_active')) $subQ->where('is_active',1);
            if (Schema::connection($core)->hasColumn('subscriptions','period_end')) $subQ->where('period_end','>=',Carbon::now());
            $sub = $subQ->orderByDesc('id')->first();
            if ($sub) $planId = (int) ($sub->plan_id ?? 0);
        }
        if (!$planId && isset($tenant->plan_id)) $planId = (int)$tenant->plan_id ?: null;

        if (!$planId || !Schema::connection($core)->hasTable('plans')) {
            return ['plan_id'=>null,'qr_month'=>null,'qr_max_batch'=>null];
        }

        $plan = DB::connection($core)->table('plans')->where('id',$planId)->first();
        if (!$plan) return ['plan_id'=>null,'qr_month'=>null,'qr_max_batch'=>null];

        $qrMonth = null; $qrMaxBatch = null; $json = null;
        if (Schema::connection($core)->hasColumn('plans','included_qr_per_month')) {
            $qrMonth = is_numeric($plan->included_qr_per_month ?? null) ? (int)$plan->included_qr_per_month : null;
        }
        if ($plan->limits_json ?? null) {
            $json = json_decode($plan->limits_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) $json = null;
        }
        if (!$qrMonth && is_array($json) && is_numeric($json['qr_limit'] ?? null)) $qrMonth = (int)$json['qr_limit'];
        if (is_array($json) && is_numeric($json['qr_max_batch'] ?? null)) $qrMaxBatch = (int)$json['qr_max_batch'];

        return ['plan_id'=>$planId,'qr_month'=>$qrMonth,'qr_max_batch'=>$qrMaxBatch];
    }

    protected function issuedThisMonth(int $tenantId, string $conn): int
    {
        if (!Schema::connection($conn)->hasTable('qr_codes_s')) return 0;
        $start = Carbon::now()->startOfMonth();
        $end   = Carbon::now()->endOfMonth();
        $col = Schema::connection($conn)->hasColumn('qr_codes_s','issued_at') ? 'issued_at' : 'created_at';
        return (int) DB::connection($conn)->table('qr_codes_s')
            ->where('tenant_id',$tenantId)->whereBetween($col,[$start,$end])->count();
    }

    /* ---------- mint ---------- */

      public function mintForProduct(Request $req, $idOrSku)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved (missing X-Tenant header or binding)'], 400);

        $data = $req->validate([
            'qty'              => ['required','integer','min:1','max:200000'],
            'channel_code'     => ['required','string','max:40'],
            'batch_code'       => ['nullable','string','max:64'],
            'micro_mode'       => ['nullable','in:hmac16,none'],
            'create_print_run' => ['sometimes','boolean'],
            'print_vendor'     => ['nullable','string','max:120'],
            'reel_start'       => ['nullable','string','max:40'],
            'reel_end'         => ['nullable','string','max:40'],
        ]);

        $c  = $this->sharedConn();
        $tp = Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';

        $q = DB::connection($c)->table($tp)->where('tenant_id',$tenant->id);
        $product = is_numeric($idOrSku)
            ? $q->where('id',(int)$idOrSku)->first(['id','sku','type'])
            : $q->where('sku',$idOrSku)->first(['id','sku','type']);
        if (!$product) return response()->json(['message'=>'Unknown product'], 422);

        if (($product->type ?? 'standard') === 'composite' && Schema::connection($c)->hasTable('product_components_s')) {
            $hasBom = DB::connection($c)->table('product_components_s')
                ->where('tenant_id',$tenant->id)->where('parent_product_id',$product->id)->exists();
            if (!$hasBom) return response()->json(['message'=>'Composite product has no components (BOM empty)'], 422);
        }

        if (!Schema::connection($c)->hasTable('qr_codes_s') || !Schema::connection($c)->hasTable('qr_channels_s')) {
            return response()->json(['message'=>'QR tables not present'], 500);
        }

        $limits = $this->planQrLimits($tenant);
        if ($limits['qr_max_batch'] && (int)$data['qty'] > $limits['qr_max_batch']) {
            return response()->json(['message'=>'Batch size exceeds plan limit.','limit'=>$limits['qr_max_batch'],'requested'=>(int)$data['qty']], 422);
        }
        if ($limits['qr_month']) {
            $used = $this->issuedThisMonth($tenant->id, $c);
            $remaining = max(0, $limits['qr_month'] - $used);
            if ((int)$data['qty'] > $remaining) {
                return response()->json([
                    'message'=>'Monthly QR limit exceeded.',
                    'limit'=>$limits['qr_month'],'used_this_month'=>$used,
                    'remaining'=>$remaining,'requested'=>(int)$data['qty'],
                ], 422);
            }
        }

        DB::connection($c)->table('qr_channels_s')->updateOrInsert(
            ['tenant_id'=>$tenant->id,'code'=>$data['channel_code']],
            ['name'=>$data['channel_code']]
        );
        $channelId = DB::connection($c)->table('qr_channels_s')
            ->where('tenant_id',$tenant->id)->where('code',$data['channel_code'])->value('id');

        $batchId = null;
        if (!empty($data['batch_code']) && Schema::connection($c)->hasTable('product_batches_s')) {
            DB::connection($c)->table('product_batches_s')->updateOrInsert(
                ['tenant_id'=>$tenant->id,'batch_code'=>$data['batch_code']],
                ['product_id'=>$product->id]
            );
            $batchId = DB::connection($c)->table('product_batches_s')
                ->where('tenant_id',$tenant->id)->where('batch_code',$data['batch_code'])->value('id');
        }

        $printRunId = null;
        if ($req->boolean('create_print_run', true) && Schema::connection($c)->hasTable('print_runs_s')) {
            $printRunId = DB::connection($c)->table('print_runs_s')->insertGetId([
                'tenant_id'=>$tenant->id,'product_id'=>$product->id,'batch_id'=>$batchId,
                'channel_id'=>$channelId,'vendor_name'=>$data['print_vendor'] ?? null,
                'reel_start'=>$data['reel_start'] ?? null,'reel_end'=>$data['reel_end'] ?? null,
                'qty_planned'=>(int)$data['qty'],'created_at'=>now(),
            ]);
        }

        $k2 = $this->k2ForTenant($tenant->id);
        $rows = [];
        for ($i=0; $i<(int)$data['qty']; $i++) {
            do {
                $token = $this->base64url(random_bytes(16));
                $exists = DB::connection($c)->table('qr_codes_s')
                    ->where('tenant_id',$tenant->id)->where('token',$token)->exists();
            } while ($exists);

            $micro = null;
            if (($data['micro_mode'] ?? 'hmac16') === 'hmac16') {
                $micro = substr(hash_hmac('sha256',$token,$k2,true),0,16);
            }

            $rows[] = [
                'tenant_id'=>$tenant->id,'token'=>$token,'token_ver'=>1,'status'=>'issued','version'=>1,
                'product_id'=>$product->id,'batch_id'=>$batchId,'channel_id'=>$channelId,'print_run_id'=>$printRunId,
                'micro_chk'=>$micro,'watermark_hash'=>null,'issued_at'=>now(),'activated_at'=>null,'voided_at'=>null,'expires_at'=>null,
            ];
        }
        DB::connection($c)->table('qr_codes_s')->insert($rows);

        $base = $this->verifyBase();
        $labels = array_map(function($r) use ($base,$data) {
            $url = $base.'/v/'.$r['token'].'?ch='.rawurlencode($data['channel_code']).'&v=1';
            return ['token'=>$r['token'],'url'=>$url,'micro_hex'=>$r['micro_chk']?strtoupper(bin2hex($r['micro_chk'])):null];
        }, $rows);

        return response()->json([
            'print_run_id'=>$printRunId,'issued'=>count($rows),
            'labels'=>$labels,'plan_limits_applied'=>$limits,
        ], 201);
    }

    /* ---------- export zip ---------- */

    public function exportZip(Request $req, int $printRunId)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $c = $this->sharedConn();
        if (!Schema::connection($c)->hasTable('qr_codes_s')) return response()->json(['message'=>'QR table not present'], 500);

        $codes = DB::connection($c)->table('qr_codes_s')
            ->where('tenant_id',$tenant->id)->where('print_run_id',$printRunId)
            ->orderBy('id')->get(['token','channel_id']);
        if ($codes->isEmpty()) return response()->json(['message'=>'No codes found for this print run'], 404);

        $channelCode = 'WEB';
        if (Schema::connection($c)->hasTable('qr_channels_s')) {
            $chId = (int)$codes->first()->channel_id;
            $channelCode = DB::connection($c)->table('qr_channels_s')
                ->where('tenant_id',$tenant->id)->where('id',$chId)->value('code') ?? 'WEB';
        }

        if (!class_exists(\SimpleSoftwareIO\QrCode\Facades\QrCode::class)) {
            return response()->json(['message'=>'Install: composer require simplesoftwareio/simple-qrcode:^4.2'], 500);
        }

        $base = $this->verifyBase();

        $response = new StreamedResponse(function () use ($codes, $base, $channelCode) {
            $zip = new \ZipArchive();
            $tmp = tempnam(sys_get_temp_dir(), 'qrzip');
            $zip->open($tmp, \ZipArchive::OVERWRITE);

            foreach ($codes as $i => $row) {
                $url = $base.'/v/'.$row->token.'?ch='.rawurlencode($channelCode).'&v=1';
                $png = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('png')->size(600)->margin(1)->generate($url);
                $zip->addFromString(($i+1).'_'.$row->token.'.png', $png);
            }

            $zip->close();
            readfile($tmp);
            @unlink($tmp);
        });

        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename="qr-print-run-'.$printRunId.'.zip"');
        return $response;
    }

    
    /**
     * Return immediate BOM children for a product with their quantity.
     * Supports flexible column names on product_components_s.
     */
    protected function bomChildrenFor(string $conn, int $tenantId, int $parentProductId): array
    {
        if (!\Schema::connection($conn)->hasTable('product_components_s')) return [];

        $cols = \Schema::connection($conn)->getColumnListing('product_components_s');

        $parentCol = in_array('parent_product_id',$cols,true) ? 'parent_product_id'
                  : (in_array('component_parent_id',$cols,true) ? 'component_parent_id'
                  : (in_array('parent_id',$cols,true) ? 'parent_id' : null));
        if (!$parentCol) return [];

        $childCol  = in_array('child_product_id',$cols,true) ? 'child_product_id'
                  : (in_array('component_product_id',$cols,true) ? 'component_product_id'
                  : (in_array('child_id',$cols,true) ? 'child_id' : null));
        if (!$childCol) return [];

        $qtyCol    = in_array('quantity',$cols,true) ? 'quantity'
                  : (in_array('qty',$cols,true) ? 'qty'
                  : (in_array('component_qty',$cols,true) ? 'component_qty' : null));
        if (!$qtyCol) $qtyCol = 'quantity'; // default fallback

        $rows = \DB::connection($conn)->table('product_components_s as pc')
            ->join('products_s as c','c.id','=','pc.'.$childCol)
            ->selectRaw("c.id, COALESCE(c.sku,'') as sku, COALESCE(c.name,'') as name, COALESCE(c.type,'standard') as type, pc.$qtyCol as quantity")
            ->where('pc.tenant_id',$tenantId)
            ->where('pc.'.$parentCol, $parentProductId)
            ->get();

        return array_map(function($r){
            return [
                'id' => (int)$r->id,
                'sku' => $r->sku,
                'name' => $r->name,
                'type' => strtolower($r->type ?: 'standard'),
                'quantity' => max(1, (int)$r->quantity),
            ];
        }, $rows->all());
    }

    /**
     * Flatten BOM for one root into a linear list of product IDs (including root).
     * Quantity is expanded: if a child has qty 3, it appears 3 times in the list.
     */
    protected function flattenBomIds(string $conn, int $tenantId, int $rootProductId, array &$seen = []): array
    {
        if (isset($seen[$rootProductId])) return []; // guard cycles
        $seen[$rootProductId] = true;

        $list = [$rootProductId];
        $children = $this->bomChildrenFor($conn, $tenantId, $rootProductId);
        foreach ($children as $c) {
            for ($i=0; $i<max(1,$c['quantity']); $i++) {
                $list = array_merge($list, $this->flattenBomIds($conn, $tenantId, (int)$c['id'], $seen));
            }
        }
        return $list;
    }
    
/* ---------- lists for UI ---------- */

    // GET /api/print-runs/{printRunId}/codes?limit=100
    public function listForPrintRun(Request $req, int $printRunId)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $limit = (int) $req->query('limit', 100);
        $limit = max(1, min(1000, $limit));

        $c  = $this->sharedConn();

        $rows = DB::connection($c)->table('qr_codes_s as q')
            ->leftJoin('qr_channels_s as ch', function($j) use ($tenant){ $j->on('ch.id','=','q.channel_id')->where('ch.tenant_id','=',$tenant->id); })
            ->where('q.tenant_id',$tenant->id)
            ->where('q.print_run_id',$printRunId)
            ->orderBy('q.id')
            ->limit($limit)
            ->get(['q.id','q.token','q.product_id','q.issued_at','ch.code as channel_code']);

        if ($rows->isEmpty()) return response()->json(['items'=>[]]);

        $base = $this->verifyBase();
        $items = $rows->map(function($r) use ($base){
            $ch = $r->channel_code ?: 'WEB';
            return [
                'id'           => (int)$r->id,
                'token'        => $r->token,
                'url'          => $base.'/v/'.$r->token.'?ch='.rawurlencode($ch).'&v=1',
                'product_id'   => (int)($r->product_id ?? 0),
                'issued_at'    => (string)$r->issued_at,
                'channel_code' => $ch,
            ];
        });

        return response()->json(['print_run_id'=>$printRunId, 'items'=>$items]);
    }

    // GET /api/products/{idOrSku}/codes?limit=50
    public function listForProduct(Request $req, $idOrSku)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $limit = (int) $req->query('limit', 50);
        $limit = max(1, min(500, $limit));

        $c  = $this->sharedConn();
        $tp = Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';

        $q = DB::connection($c)->table($tp)->where('tenant_id',$tenant->id);
        $product = is_numeric($idOrSku)
            ? $q->where('id',(int)$idOrSku)->first(['id','sku'])
            : $q->where('sku',$idOrSku)->first(['id','sku']);
        if (!$product) return response()->json(['items'=>[]]);

        $rows = DB::connection($c)->table('qr_codes_s as q')
            ->leftJoin('qr_channels_s as ch', function($j) use ($tenant){ $j->on('ch.id','=','q.channel_id')->where('ch.tenant_id','=',$tenant->id); })
            ->where('q.tenant_id',$tenant->id)
            ->where('q.product_id',$product->id)
            ->orderByDesc('q.id')
            ->limit($limit)
            ->get(['q.id','q.token','q.print_run_id','q.issued_at','ch.code as channel_code']);

        $base = $this->verifyBase();
        $items = $rows->map(function($r) use ($base){
            $ch = $r->channel_code ?: 'WEB';
            return [
                'id'           => (int)$r->id,
                'token'        => $r->token,
                'url'          => $base.'/v/'.$r->token.'?ch='.rawurlencode($ch).'&v=1',
                'print_run_id' => (int)($r->print_run_id ?? 0),
                'issued_at'    => (string)$r->issued_at,
                'channel_code' => $ch,
            ];
        });

        return response()->json(['product'=>$product, 'items'=>$items]);
    }

    // GET /api/products/{idOrSku}/batches
    public function batchesForProduct(Request $req, $idOrSku)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $c  = $this->sharedConn();
        $tp = Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';

        $q = DB::connection($c)->table($tp)->where('tenant_id',$tenant->id);
        $product = is_numeric($idOrSku)
            ? $q->where('id',(int)$idOrSku)->first(['id','sku','name'])
            : $q->where('sku',$idOrSku)->first(['id','sku','name']);
        if (!$product) return response()->json(['items'=>[]]);

        if (!Schema::connection($c)->hasTable('product_batches_s')) {
            return response()->json(['product'=>$product, 'items'=>[]]);
        }

        $rows = DB::connection($c)->table('product_batches_s as b')
            ->leftJoin('print_runs_s as pr', function($j) use ($tenant) {
                $j->on('pr.batch_id','=','b.id')->where('pr.tenant_id','=',$tenant->id);
            })
            ->leftJoin('qr_codes_s as q', function($j) use ($tenant) {
                $j->on('q.print_run_id','=','pr.id')->where('q.tenant_id','=',$tenant->id);
            })
            ->where('b.tenant_id',$tenant->id)
            ->where('b.product_id',$product->id)
            ->groupBy('b.id','b.batch_code')
            ->orderByDesc(DB::raw('MAX(pr.created_at)'))
            ->get([
                'b.id as batch_id',
                'b.batch_code',
                DB::raw('COUNT(DISTINCT pr.id) as runs_count'),
                DB::raw('COALESCE(SUM(pr.qty_planned),0) as planned_qty'),
                DB::raw('COUNT(q.id) as issued_codes'),
                DB::raw('MAX(pr.created_at) as last_run_at'),
            ]);

        return response()->json([
            'product' => $product,
            'items'   => $rows,
        ]);
    }

    // GET /api/batches/{batchId}/runs
    public function runsForBatch(Request $req, int $batchId)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $c = $this->sharedConn();
        if (!Schema::connection($c)->hasTable('print_runs_s')) {
            return response()->json(['items'=>[]]);
        }

        $runs = DB::connection($c)->table('print_runs_s as pr')
            ->leftJoin('qr_codes_s as q', function($j) use ($tenant) {
                $j->on('q.print_run_id','=','pr.id')->where('q.tenant_id','=',$tenant->id);
            })
            ->leftJoin('qr_channels_s as ch', function($j) use ($tenant) {
                $j->on('ch.id','=','pr.channel_id')->where('ch.tenant_id','=',$tenant->id);
            })
            ->where('pr.tenant_id',$tenant->id)
            ->where('pr.batch_id',$batchId)
            ->groupBy('pr.id','pr.qty_planned','pr.vendor_name','pr.created_at','ch.code')
            ->orderBy('pr.id')
            ->get([
                'pr.id as print_run_id',
                'pr.qty_planned',
                'pr.vendor_name',
                'pr.created_at',
                'ch.code as channel_code',
                DB::raw('COUNT(q.id) as issued_codes'),
            ]);

        return response()->json([
            'batch_id' => $batchId,
            'items'    => $runs,
        ]);
    }

    /* ---------- optional plan stats ---------- */
    public function planStats(Request $req)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $c = $this->sharedConn();
        $limits = $this->planQrLimits($tenant);
        $used = $this->issuedThisMonth($tenant->id, $c);

        return response()->json([
            'plan_id'=>$limits['plan_id'],
            'qr_month_limit'=>$limits['qr_month'],
            'qr_max_batch'=>$limits['qr_max_batch'],
            'used_this_month'=>$used,
            'remaining'=> is_null($limits['qr_month']) ? null : max(0, $limits['qr_month'] - $used),
        ]);
    }

    public function labelStats(Request $req, $idOrSku)
{
    $tenant = $this->tenant($req);
    if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

    $c  = $this->sharedConn();
    $tp = \Illuminate\Support\Facades\Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';

    $q = \DB::connection($c)->table($tp)->where('tenant_id',$tenant->id);
    $product = is_numeric($idOrSku)
        ? $q->where('id',(int)$idOrSku)->first(['id','sku'])
        : $q->where('sku',$idOrSku)->first(['id','sku']);
    if (!$product) return response()->json(['available'=>0,'bound'=>0,'voided'=>0,'total'=>0]);

    if (!\Illuminate\Support\Facades\Schema::connection($c)->hasTable('qr_codes_s')) {
        return response()->json(['available'=>0,'bound'=>0,'voided'=>0,'total'=>0]);
    }

    $base = \DB::connection($c)->table('qr_codes_s')
        ->where('tenant_id',$tenant->id)
        ->where('product_id',$product->id);

    $available = (clone $base)->where('status','issued')->count(); // not yet bound
    $bound     = (clone $base)->where('status','bound')->count();
    $voided    = (clone $base)->where('status','voided')->count();
    $total     = (clone $base)->count();

    return response()->json([
        'sku'       => $product->sku,
        'available' => (int)$available,
        'bound'     => (int)$bound,
        'voided'    => (int)$voided,
        'total'     => (int)$total,
    ]);
}

public function peek(Request $req, string $token) {
    $tenant = $this->tenant($req);
    $c = $this->sharedConn();

    $row = \DB::connection($c)->table('qr_codes_s')
        ->where('tenant_id', $tenant->id)
        ->where('token', $token)
        ->first();

    if (!$row) return response()->json(['message'=>'Token not found'], 404);

    $product = \DB::connection($c)->table('products_s')->where('id', $row->product_id)->first();
    $deviceLink = \DB::connection($c)->table('device_qr_links_s')->where('qr_code_id', $row->id)->first();
    $device = $deviceLink
        ? \DB::connection($c)->table('devices_s')->where('id', $deviceLink->device_id)->first()
        : null;

    return response()->json([
        'status'   => $row->status, // issued|bound|voided
        'product'  => $product ? ['sku'=>$product->sku, 'name'=>$product->name, 'type'=>$product->type] : null,
        'channel'  => $row->channel_code,
        'batch'    => $row->batch_code,
        'print_run_id' => $row->print_run_id ?? null,
        'device'   => $device ? [
            'device_uid' => $device->device_uid,
            'attrs' => $device->attrs_json ? json_decode($device->attrs_json, true) : new \stdClass(),
        ] : null,
    ]);
}

public function listRunCodes(\Illuminate\Http\Request $req, int $runId)
{
    $tenant = app()->bound('tenant') ? app('tenant') : (object)['id' => (int)($req->header('X-Tenant') ?: 1)];
    $c  = config('database.connections.domain_shared') ? 'domain_shared' : config('database.default');
    $tp = \Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';
    $qrc= 'qr_codes_s';

    $hasAsmLinks     = \Schema::connection($c)->hasTable('device_assembly_links_s');
    $hasBomTable     = \Schema::connection($c)->hasTable('product_components_s');
    $devHasProductId = \Schema::connection($c)->hasTable('devices_s')
                        && \Schema::connection($c)->hasColumn('devices_s','product_id');

    $hasQChannelCode = \Schema::connection($c)->hasColumn($qrc, 'channel_code');
    $hasQChannel     = \Schema::connection($c)->hasColumn($qrc, 'channel');
    $hasQBatchCode   = \Schema::connection($c)->hasColumn($qrc, 'batch_code');
    $hasQBatch       = \Schema::connection($c)->hasColumn($qrc, 'batch');
    $hasRunFk        = \Schema::connection($c)->hasColumn($qrc, 'print_run_id');
    $hasPrTable      = \Schema::connection($c)->hasTable('print_runs_s');
    $prHasBatchCode  = $hasPrTable && \Schema::connection($c)->hasColumn('print_runs_s', 'batch_code');
    $prHasBatch      = $hasPrTable && \Schema::connection($c)->hasColumn('print_runs_s', 'batch');

    // BOM qty column (your migration uses "quantity")
    $qtyCol = null;
    if ($hasBomTable) {
        foreach (['quantity','component_qty','qty','required_qty','units','count'] as $cand) {
            if (\Schema::connection($c)->hasColumn('product_components_s', $cand)) { $qtyCol = $cand; break; }
        }
    }

    // Assembly child-device column (your migration uses "component_device_id")
    $childDevCol = null;
    if ($hasAsmLinks) {
        foreach (['component_device_id','child_device_id'] as $cand) {
            if (\Schema::connection($c)->hasColumn('device_assembly_links_s', $cand)) { $childDevCol = $cand; break; }
        }
    }

    // Optional assembly qty column (you don't have one; we’ll COUNT links)
    $asmQtyCol = null;
    if ($hasAsmLinks) {
        foreach (['component_qty_used','qty','quantity','units','count'] as $cand) {
            if (\Schema::connection($c)->hasColumn('device_assembly_links_s', $cand)) { $asmQtyCol = $cand; break; }
        }
    }

    $q = \DB::connection($c)->table("$qrc as q")
        ->leftJoin('device_qr_links_s as l', 'l.qr_code_id', '=', 'q.id')
        ->leftJoin('devices_s as d', 'd.id', '=', 'l.device_id')
        ->leftJoin("$tp as p", 'p.id', '=', 'q.product_id')
        ->where('q.tenant_id', $tenant->id)
        ->where('q.print_run_id', $runId);

    if ($hasRunFk && $hasPrTable && ($prHasBatchCode || $prHasBatch)) {
        $q->leftJoin('print_runs_s as pr', 'pr.id', '=', 'q.print_run_id');
    }

    if ($hasAsmLinks) {
        $q->leftJoin('device_assembly_links_s as ap', 'ap.parent_device_id', '=', 'd.id'); // parent -> children
    }
    if ($hasAsmLinks && $childDevCol) {
        $q->leftJoin('device_assembly_links_s as ac', "ac.$childDevCol", '=', 'd.id');     // child -> parent
        $q->leftJoin('devices_s as dpar', 'dpar.id', '=', 'ac.parent_device_id');
        if ($devHasProductId) {
            $q->leftJoin("$tp as ppar", 'ppar.id', '=', 'dpar.product_id');
        }
    }

    if ($qtyCol) {
        $bomTotals = \DB::connection($c)->table('product_components_s')
            ->select('parent_product_id', \DB::raw("SUM($qtyCol) as req_total"))
            ->groupBy('parent_product_id');
        $q->leftJoinSub($bomTotals, 'bom', 'bom.parent_product_id', '=', 'p.id');
    }

    // channel & batch
    $channelExpr = $hasQChannelCode ? 'q.channel_code as channel'
                 : ($hasQChannel   ? 'q.channel as channel' : 'NULL as channel');

    $batchExpr   = $hasQBatchCode   ? 'q.batch_code as batch'
                 : ($hasQBatch      ? 'q.batch as batch'
                 : (($hasRunFk && $hasPrTable && $prHasBatchCode) ? 'pr.batch_code as batch'
                 : (($hasRunFk && $hasPrTable && $prHasBatch)     ? 'pr.batch as batch' : 'NULL as batch')));

    // comp_count (SUM over qty if present, else COUNT links)
    $compCountExpr = !$hasAsmLinks
        ? '0 as comp_count'
        : ($asmQtyCol ? "COALESCE(SUM(ap.$asmQtyCol),0) as comp_count" : "COUNT(ap.id) as comp_count");

    $select = [
        'q.id','q.token',
        \Schema::connection($c)->hasColumn($qrc,'status') ? 'q.status' : \DB::raw('NULL as status'),
        'q.product_id',
        \Schema::connection($c)->hasColumn($qrc,'print_run_id') ? 'q.print_run_id' : \DB::raw('NULL as print_run_id'),
        \DB::raw($channelExpr),
        \DB::raw($batchExpr),

        // ✅ ONLY_FULL_GROUP_BY-safe boolean:
        \DB::raw('CASE WHEN COUNT(DISTINCT l.id) > 0 THEN 1 ELSE 0 END as is_bound'),

        'p.sku','p.name',
        \Schema::connection($c)->hasColumn($tp,'type') ? 'p.type' : \DB::raw("'standard' as type"),
        'd.device_uid',
        'dpar.device_uid as parent_device_uid',
    ];
    $select[] = ($hasAsmLinks && $childDevCol && $devHasProductId) ? 'ppar.sku as parent_sku' : \DB::raw('NULL as parent_sku');
    $select[] = \DB::raw($compCountExpr);
    $select[] = \DB::raw($qtyCol ? 'COALESCE(bom.req_total,0) as comp_required' : '0 as comp_required');

    $groupBy = [
        'q.id','q.token','q.status','q.product_id','q.print_run_id',
        'p.sku','p.name','p.type','d.device_uid','parent_device_uid',
    ];
    if ($hasAsmLinks && $childDevCol && $devHasProductId) {
        $groupBy[] = 'ppar.sku';
    }

    $rows = $q->groupBy($groupBy)->orderBy('q.id')->get($select);

    $seq = 0;
    foreach ($rows as $r) {
        $seq++;
        $r->seq_in_run = $seq;
        $r->human_code = $this->humanCode($r->token);
        $r->role = (isset($r->type) && $r->type === 'composite') ? 'parent' : 'part';
        $r->comp_ok = null;
        if ($r->role === 'parent') {
            $req = (float)($r->comp_required ?? 0);
            $got = (float)($r->comp_count ?? 0);
            $r->comp_ok = ($req > 0) ? (abs($got - $req) < 1e-9) : null; // null if no BOM
        }
        $r->url = null;
    }

    return response()->json(['items' => $rows]);
}


protected function humanCode(string $token): string
{
    $crc = sprintf('%u', crc32($token));
    $base36 = strtoupper(base_convert($crc, 10, 36));
    return str_pad($base36, 6, '0', STR_PAD_LEFT);
}


}
