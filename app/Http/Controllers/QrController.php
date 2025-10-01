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
    // K3 is distinct from K2: keep secrets compartmentalized
protected function k3ForTenant(int $tenantId): string {
    return hash('sha256', config('app.key').'|k3|'.$tenantId, true); // raw bytes
}

// Make a readable short code from raw bytes (Crockford Base32, no confusing chars)
protected static function base32Crockford(string $bin, int $len = 13): string {
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    for ($i=0; $i+5 <= strlen($bits) && strlen($out) < $len; $i+=5) {
        $out .= $alphabet[bindec(substr($bits, $i, 5))];
    }
    return $out;
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
    if (!$tenant?->id) {
        return response()->json(['message'=>'Tenant not resolved (missing X-Tenant header or binding)'], 400);
    }

    $data = $req->validate([
        'qty'              => ['required','integer','min:1','max:200000'],
        'channel_code'     => ['required','string','max:40'],
        'batch_code'       => ['nullable','string','max:64'],
        'micro_mode'       => ['nullable','in:hmac16,none'],
        'create_print_run' => ['sometimes','boolean'],
        'print_vendor'     => ['nullable','string','max:120'],
        'reel_start'       => ['nullable','string','max:40'],
        'reel_end'         => ['nullable','string','max:40'],
        'cascade'          => ['sometimes','boolean'], // default true for composite
    ]);

    $c  = $this->sharedConn();
    $tp = \Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';

    // Resolve product
    $q = \DB::connection($c)->table($tp)->where('tenant_id',$tenant->id);
    $product = is_numeric($idOrSku)
        ? $q->where('id',(int)$idOrSku)->first(['id','sku','type'])
        : $q->where('sku',$idOrSku)->first(['id','sku','type']);
    if (!$product) return response()->json(['message'=>'Unknown product'], 422);

    $type        = strtolower($product->type ?? 'standard');
    $isComposite = $type === 'composite';
    $doCascade   = $req->has('cascade') ? $req->boolean('cascade') : $isComposite;

    // Required tables
    foreach (['qr_codes_s','qr_channels_s'] as $t) {
        if (!\Schema::connection($c)->hasTable($t)) {
            return response()->json(['message'=>"Required table '$t' not present"], 500);
        }
    }

    // Per-root qty map; include root
    $qtyPerRoot = [ (int)$product->id => 1 ];

    if ($doCascade) {
        if (!\Schema::connection($c)->hasTable('product_components_s')) {
            return response()->json(['message'=>'Composite product has no components table'], 500);
        }
        $hasBom = \DB::connection($c)->table('product_components_s')
            ->where('tenant_id',$tenant->id)->where('parent_product_id',$product->id)->exists();
        if (!$hasBom) {
            return response()->json(['message'=>'Composite product has no components (BOM empty)'], 422);
        }

        // Traverse BOM multi-level; accumulate per-root quantities
        $visitedEdge = [];
        $stack = [ (int)$product->id ];
        while ($stack) {
            $parentId = array_pop($stack);
            $parentFactor = max(1, (int)ceil($qtyPerRoot[$parentId] ?? 0));

            $rows = \DB::connection($c)->table('product_components_s')
                ->where('tenant_id',$tenant->id)->where('parent_product_id',$parentId)
                ->get(['child_product_id','quantity']);

            foreach ($rows as $r) {
                $childId = (int)$r->child_product_id;
                $edgeKey = $parentId.':'.$childId;
                if (isset($visitedEdge[$edgeKey])) continue;
                $visitedEdge[$edgeKey] = true;

                $qNeeded = max(1, (int)ceil((float)($r->quantity ?? 0)));
                $qtyPerRoot[$childId] = ($qtyPerRoot[$childId] ?? 0) + ($parentFactor * $qNeeded);
                $stack[] = $childId;
            }
        }
    }

    // Scale by requested root qty
    $rootQty = (int)$data['qty'];
    $qtyByProductId = [];
    foreach ($qtyPerRoot as $pid => $perRoot) $qtyByProductId[$pid] = (int)$perRoot * $rootQty;

    // SKUs for affected products
    $allIds = array_keys($qtyByProductId);
    $meta = \DB::connection($c)->table($tp)
        ->where('tenant_id',$tenant->id)->whereIn('id',$allIds)
        ->get(['id','sku','type'])->keyBy('id');

    // Plan/limits
    $limits = $this->planQrLimits($tenant);
    $totalToMint = array_sum($qtyByProductId);

    if (!empty($limits['qr_max_batch'])) {
        $maxBatch = (int)$limits['qr_max_batch'];
        foreach ($qtyByProductId as $pid => $qPlan) {
            if ((int)$qPlan > $maxBatch) {
                return response()->json([
                    'message'=>'Batch size exceeds plan limit for at least one SKU.',
                    'limit'=>$maxBatch,'sku'=>(string)($meta[$pid]->sku ?? $pid),
                    'requested_for_sku'=>(int)$qPlan,
                ], 422);
            }
        }
    }
    if (!empty($limits['qr_month'])) {
        $used = $this->issuedThisMonth($tenant->id, $c);
        $remaining = max(0, (int)$limits['qr_month'] - (int)$used);
        if ($totalToMint > $remaining) {
            return response()->json([
                'message'=>'Monthly QR limit exceeded.',
                'limit'=>(int)$limits['qr_month'],'used_this_month'=>(int)$used,
                'remaining'=>(int)$remaining,'requested_total'=>(int)$totalToMint,
            ], 422);
        }
    }

    // Ensure channel
    \DB::connection($c)->table('qr_channels_s')->updateOrInsert(
        ['tenant_id'=>$tenant->id,'code'=>$data['channel_code']],
        ['name'=>$data['channel_code']]
    );
    $channelId = \DB::connection($c)->table('qr_channels_s')
        ->where('tenant_id',$tenant->id)->where('code',$data['channel_code'])->value('id');

    // ONE shared batch row per (tenant,batch_code) with product_id = root (NOT NULL safe)
    $sharedBatchId = null;
    if (!empty($data['batch_code']) && \Schema::connection($c)->hasTable('product_batches_s')) {
        $existing = \DB::connection($c)->table('product_batches_s')
            ->where('tenant_id',$tenant->id)->where('batch_code',$data['batch_code'])
            ->first(['id']);
        if ($existing) {
            $sharedBatchId = $existing->id;
        } else {
            $ins = ['tenant_id'=>$tenant->id,'product_id'=>$product->id,'batch_code'=>$data['batch_code']];
            if (\Schema::connection($c)->hasColumn('product_batches_s','created_at')) $ins['created_at']=now();
            if (\Schema::connection($c)->hasColumn('product_batches_s','updated_at')) $ins['updated_at']=now();
            $sharedBatchId = \DB::connection($c)->table('product_batches_s')->insertGetId($ins);
        }
    }

    // Create ONE print run anchored to the root
    $createRun = $req->boolean('create_print_run', true) && \Schema::connection($c)->hasTable('print_runs_s');
    $rootRunId = null;
    if ($createRun) {
        $rootRunId = \DB::connection($c)->table('print_runs_s')->insertGetId([
            'tenant_id'=>$tenant->id,'product_id'=>$product->id,'batch_id'=>$sharedBatchId,
            'channel_id'=>$channelId,'vendor_name'=>$data['print_vendor'] ?? null,
            'reel_start'=>$data['reel_start'] ?? null,'reel_end'=>$data['reel_end'] ?? null,
            'qty_planned'=>$rootQty,'created_at'=>now(),
        ]);
    }

    // Mint ALL codes (for ALL SKUs) with print_run_id = root run
    $k2        = $this->k2ForTenant($tenant->id);
    $k3        = $this->k3ForTenant($tenant->id);    // micro key 👈
    $baseUrl   = $this->verifyBase();
    $microMode = $data['micro_mode'] ?? 'hmac16';

    $issuedBySku = [];
    $labelsRoot  = [];
    $tokensByPid = []; // for code graph
    foreach ($qtyByProductId as $pid => $qtyPlan) {
        $qtyPlan = (int)$qtyPlan;
        $sku = (string)($meta[$pid]->sku ?? $pid);
        if ($qtyPlan < 1) { $issuedBySku[$sku] = 0; continue; }

        $rows = [];
        for ($i=0; $i<$qtyPlan; $i++) {
            // unique token
            do {
                $token = $this->base64url(random_bytes(16));
                $exists = \DB::connection($c)->table('qr_codes_s')
                    ->where('tenant_id',$tenant->id)->where('token',$token)->exists();
            } while ($exists);

            $microChk = null; $microCode = null; $wmHash = null;

               if ($microMode === 'hmac16') {
        $microRaw  = hash_hmac('sha256', $token, $k3, true);     // 👈 use K3
        $microChk  = substr($microRaw, 0, 16);                   // fits VARBINARY(16)
        $microCode = self::base32Crockford(substr($microRaw,0,8), 13); // 13-char code

        $wmRaw  = hash_hmac('sha256', $token, $k2, true);        // 👈 use K2
        $wmHash = substr($wmRaw, 0, 16);                         // VARBINARY(16)
    }

    // 3) optional: token_hash for public lookups (add migration below)
    $tokenHash = hash('sha256', $token);

    $rows[] = [
  'tenant_id'      => $tenant->id,
  'token'          => $token,
  'token_ver'      => 1,
  'token_hash'     => hash('sha256', $token),                // 👈 add
  'status'         => 'issued',
  'version'        => 1,
  'product_id'     => (int)$pid,
  'batch_id'       => $sharedBatchId,
  'channel_id'     => $channelId,
  'print_run_id'   => $rootRunId,

  'micro_chk'      => $microChk,                             // 👈 now from K3
  'watermark_hash' => $wmHash,                               // 👈 now set
  'human_code'     => $microCode,                            // 👈 UI/label short code

  'issued_at'      => now(),
  'activated_at'   => null,
  'voided_at'      => null,
  'expires_at'     => null,
    ];
        }
          if ((int)$pid === (int)$product->id) {
$labelsRoot[] = [
    'token'      => $token,
    'url'        => $baseUrl.'/v/'.$token.'?ch='.rawurlencode($data['channel_code']).'&v=1',
    'micro_code' => $microCode,
    'micro_hex'  => $microChk ? strtoupper(bin2hex($microChk)) : null,
];
    }
        \DB::connection($c)->table('qr_codes_s')->insert($rows);

        // Track for code graph
        $tokensByPid[$pid] = array_column($rows, 'token');
        $issuedBySku[$sku] = $qtyPlan;
    }

    // === CODE GRAPH ==================================================================================
    $havePC  = \Schema::connection($c)->hasTable('product_codes_s');
    $havePCE = \Schema::connection($c)->hasTable('product_code_edges_s');

    if ($havePC) {
        // Mirror tokens into product_codes_s (kind: primary for root, component otherwise)
        foreach ($tokensByPid as $pid => $tokens) {
            $kind = ((int)$pid === (int)$product->id) ? 'primary' : 'component';
            $pcRows = [];
            foreach ($tokens as $tok) {
                $pcRows[] = [
                    'tenant_id'=>$tenant->id,
                    'product_id'=>(int)$pid,
                    'code'=>$tok,
                    'kind'=>$kind,
                    'parent_code_id'=>null,
                    'status'=>'active',
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ];
            }
            if ($pcRows) \DB::connection($c)->table('product_codes_s')->insert($pcRows);
        }

        if ($havePCE && \Schema::connection($c)->hasTable('product_components_s')) {
            // Fetch code ids
            $codeIdByToken = [];
            $flat = [];
            foreach ($tokensByPid as $toks) { foreach ($toks as $t) $flat[] = $t; }
            foreach (array_chunk($flat, 1000) as $chunk) {
                $rs = \DB::connection($c)->table('product_codes_s')
                    ->where('tenant_id',$tenant->id)->whereIn('code',$chunk)->get(['id','code']);
                foreach ($rs as $r) $codeIdByToken[$r->code] = (int)$r->id;
            }

            // Per-product queues of code ids
            $queue = [];
            foreach ($tokensByPid as $pid => $list) {
                $qIds = new \SplQueue();
                foreach ($list as $tok) $qIds->enqueue($codeIdByToken[$tok]);
                $queue[$pid] = $qIds;
            }

            // Cache BOM children
            $bomChildren = function(int $pid) use ($tenant,$c) {
                static $cache = [];
                if (!isset($cache[$pid])) {
                    $rows = \DB::connection($c)->table('product_components_s')
                        ->where('tenant_id',$tenant->id)->where('parent_product_id',$pid)
                        ->get(['child_product_id','quantity']);
                    $cache[$pid] = $rows->map(fn($r)=>[(int)$r->child_product_id, max(1,(int)ceil((float)$r->quantity))])->all();
                }
                return $cache[$pid];
            };

            $edges = [];
            $pair = function(int $parentPid) use (&$pair, $bomChildren, &$edges, &$queue, $tenant) {
                $parentQ = $queue[$parentPid] ?? null; if (!$parentQ) return;
                $spec = $bomChildren($parentPid); if (!$spec) return;

                $count = $parentQ->count();
                for ($i=0; $i<$count; $i++) {
                    $parentCodeId = $parentQ->dequeue();
                    foreach ($spec as [$childPid, $qty]) {
                        for ($k=0; $k<$qty; $k++) {
                            $childQ = $queue[$childPid] ?? null;
                            if (!$childQ || $childQ->isEmpty()) throw new \RuntimeException("Not enough child codes for product $childPid");
                            $childCodeId = $childQ->dequeue();

                            $edges[] = [
                                'tenant_id'=>$tenant->id,
                                'parent_code_id'=>$parentCodeId,
                                'child_code_id'=>$childCodeId,
                                'created_at'=>now(),
                                'updated_at'=>now(),
                            ];

                            // recurse down from this child
                            $saved = $queue[$childPid] ?? null;
                            $tmp = new \SplQueue(); $tmp->enqueue($childCodeId);
                            $queue[$childPid] = $tmp;
                            $pair($childPid);
                            $queue[$childPid] = $saved;
                        }
                    }
                }
            };

            $pair((int)$product->id);

            if ($edges) {
                \DB::connection($c)->table('product_code_edges_s')->insert($edges);
                // convenience parent pointer
                foreach ($edges as $e) {
                    \DB::connection($c)->table('product_codes_s')
                        ->where('id',$e['child_code_id'])
                        ->update(['parent_code_id'=>$e['parent_code_id'],'updated_at'=>now()]);
                }
            }
        }
    }
    // === /CODE GRAPH =================================================================================

    $issuedTotal = array_sum($issuedBySku);

    return response()->json([
        'root_sku'            => (string)$product->sku,
        'cascade'             => (bool)$doCascade,
        'plan_limits_applied' => $limits,
        'print_run_id'        => $rootRunId,
        'batch_id'            => $sharedBatchId,
        'channel_id'          => $channelId,
        'issued'              => (int)$issuedTotal,
        'issued_by_sku'       => $issuedBySku,
        'labels'              => $labelsRoot, // root only
    ], 201);
}


    /* ---------- export zip ---------- */

    public function exportZip(Request $req, int $printRunId)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $c = $this->sharedConn();

        // Resolve the root product for this run (the product you minted on)

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

// public function listRunCodes(\Illuminate\Http\Request $req, int $runId)
// {
//     $tenant = app()->bound('tenant') ? app('tenant') : (object)['id' => (int)($req->header('X-Tenant') ?: 1)];
//     $c  = config('database.connections.domain_shared') ? 'domain_shared' : config('database.default');
//     $tp = \Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';
//     $qrc= 'qr_codes_s';

//     $hasAsmLinks     = \Schema::connection($c)->hasTable('device_assembly_links_s');
//     $hasBomTable     = \Schema::connection($c)->hasTable('product_components_s');
//     $devHasProductId = \Schema::connection($c)->hasTable('devices_s')
//                         && \Schema::connection($c)->hasColumn('devices_s','product_id');

//     $hasQChannelCode = \Schema::connection($c)->hasColumn($qrc, 'channel_code');
//     $hasQChannel     = \Schema::connection($c)->hasColumn($qrc, 'channel');
//     $hasQBatchCode   = \Schema::connection($c)->hasColumn($qrc, 'batch_code');
//     $hasQBatch       = \Schema::connection($c)->hasColumn($qrc, 'batch');
//     $hasRunFk        = \Schema::connection($c)->hasColumn($qrc, 'print_run_id');
//     $hasPrTable      = \Schema::connection($c)->hasTable('print_runs_s');
//     $prHasBatchCode  = $hasPrTable && \Schema::connection($c)->hasColumn('print_runs_s', 'batch_code');
//     $prHasBatch      = $hasPrTable && \Schema::connection($c)->hasColumn('print_runs_s', 'batch');

//        $runRootProductId = null;
//     if ($hasPrTable) {
//         $runRootProductId = \DB::connection($c)->table('print_runs_s')
//             ->where('tenant_id', $tenant->id)
//             ->where('id', (int)$runId)
//             ->value('product_id');
//     }

//     // BOM qty column (your migration uses "quantity")
//     $qtyCol = null;
//     if ($hasBomTable) {
//         foreach (['quantity','component_qty','qty','required_qty','units','count'] as $cand) {
//             if (\Schema::connection($c)->hasColumn('product_components_s', $cand)) { $qtyCol = $cand; break; }
//         }
//     }

//     // Assembly child-device column (your migration uses "component_device_id")
//     $childDevCol = null;
//     if ($hasAsmLinks) {
//         foreach (['component_device_id','child_device_id'] as $cand) {
//             if (\Schema::connection($c)->hasColumn('device_assembly_links_s', $cand)) { $childDevCol = $cand; break; }
//         }
//     }

//     // Optional assembly qty column (you don't have one; we’ll COUNT links)
//     $asmQtyCol = null;
//     if ($hasAsmLinks) {
//         foreach (['component_qty_used','qty','quantity','units','count'] as $cand) {
//             if (\Schema::connection($c)->hasColumn('device_assembly_links_s', $cand)) { $asmQtyCol = $cand; break; }
//         }
//     }

//     $q = \DB::connection($c)->table("$qrc as q")
//         ->leftJoin('device_qr_links_s as l', 'l.qr_code_id', '=', 'q.id')
//         ->leftJoin('devices_s as d', 'd.id', '=', 'l.device_id')
//         ->leftJoin("$tp as p", 'p.id', '=', 'q.product_id')
//         ->where('q.tenant_id', $tenant->id)
//         ->where('q.print_run_id', $runId);

//     if ($hasRunFk && $hasPrTable && ($prHasBatchCode || $prHasBatch)) {
//         $q->leftJoin('print_runs_s as pr', 'pr.id', '=', 'q.print_run_id');
//     }

//     if ($hasAsmLinks) {
//         $q->leftJoin('device_assembly_links_s as ap', 'ap.parent_device_id', '=', 'd.id'); // parent -> children
//     }
//     if ($hasAsmLinks && $childDevCol) {
//         $q->leftJoin('device_assembly_links_s as ac', "ac.$childDevCol", '=', 'd.id');     // child -> parent
//         $q->leftJoin('devices_s as dpar', 'dpar.id', '=', 'ac.parent_device_id');
//         if ($devHasProductId) {
//             $q->leftJoin("$tp as ppar", 'ppar.id', '=', 'dpar.product_id');
//         }
//     }

//     if ($qtyCol) {
//         $bomTotals = \DB::connection($c)->table('product_components_s')
//             ->select('parent_product_id', \DB::raw("SUM($qtyCol) as req_total"))
//             ->groupBy('parent_product_id');
//         $q->leftJoinSub($bomTotals, 'bom', 'bom.parent_product_id', '=', 'p.id');
//     }

//     // channel & batch
//     $channelExpr = $hasQChannelCode ? 'q.channel_code as channel'
//                  : ($hasQChannel   ? 'q.channel as channel' : 'NULL as channel');

//     $batchExpr   = $hasQBatchCode   ? 'q.batch_code as batch'
//                  : ($hasQBatch      ? 'q.batch as batch'
//                  : (($hasRunFk && $hasPrTable && $prHasBatchCode) ? 'pr.batch_code as batch'
//                  : (($hasRunFk && $hasPrTable && $prHasBatch)     ? 'pr.batch as batch' : 'NULL as batch')));

//     // comp_count (SUM over qty if present, else COUNT links)
//     $compCountExpr = !$hasAsmLinks
//         ? '0 as comp_count'
//         : ($asmQtyCol ? "COALESCE(SUM(ap.$asmQtyCol),0) as comp_count" : "COUNT(ap.id) as comp_count");

//     $select = [
//         'q.id','q.token',
//         \Schema::connection($c)->hasColumn($qrc,'status') ? 'q.status' : \DB::raw('NULL as status'),
//         'q.product_id',
//         \Schema::connection($c)->hasColumn($qrc,'print_run_id') ? 'q.print_run_id' : \DB::raw('NULL as print_run_id'),
//         \DB::raw($channelExpr),
//         \DB::raw($batchExpr),

//         // ✅ ONLY_FULL_GROUP_BY-safe boolean:
//         \DB::raw('CASE WHEN COUNT(DISTINCT l.id) > 0 THEN 1 ELSE 0 END as is_bound'),

//         'p.sku','p.name',
//         \Schema::connection($c)->hasColumn($tp,'type') ? 'p.type' : \DB::raw("'standard' as type"),
//         'd.device_uid',
//         'dpar.device_uid as parent_device_uid',
//     ];
//     $select[] = ($hasAsmLinks && $childDevCol && $devHasProductId) ? 'ppar.sku as parent_sku' : \DB::raw('NULL as parent_sku');
//     $select[] = \DB::raw($compCountExpr);
//     $select[] = \DB::raw($qtyCol ? 'COALESCE(bom.req_total,0) as comp_required' : '0 as comp_required');

//     $groupBy = [
//         'q.id','q.token','q.status','q.product_id','q.print_run_id',
//         'p.sku','p.name','p.type','d.device_uid','parent_device_uid',
//     ];
//     if ($hasAsmLinks && $childDevCol && $devHasProductId) {
//         $groupBy[] = 'ppar.sku';
//     }

//     $rows = $q->groupBy($groupBy)->orderBy('q.id')->get($select);

//        // Fallback if the run row wasn't found (legacy/missing row)
//     if (!$runRootProductId) {
//         $first = $rows[0] ?? null;
//         if ($first && isset($first->product_id)) {
//             $runRootProductId = (int)$first->product_id;
//         }
//     }

//     if (!$runRootProductId) {
//     $first = $rows[0] ?? null;
//     if ($first && isset($first->product_id)) {
//         $runRootProductId = (int)$first->product_id;
//     }
// }

//     $seq = 0;
//     foreach ($rows as $r) {
//         $seq++;
//         $r->seq_in_run = $seq;
//         // $r->human_code = $this->humanCode($r->token);
//         $r->micro_code = isset($r->micro_chk) && $r->micro_chk !== null
//     ? self::base32Crockford(substr($r->micro_chk,0,8), 13)
//     : null;

// $r->human_code = $r->micro_code ?: $this->humanCode($r->token); // fallback only
//         // $r->role = (isset($r->type) && $r->type === 'composite') ? 'parent' : 'part';
//         // $r->role = ((int)$r->product_id === (int)$r->root_product_id) ? 'parent' : 'part';
//         $r->role = ((int)$r->product_id === (int)$runRootProductId) ? 'parent' : 'part';        
//         $r->comp_ok = null;
//         if ($r->role === 'parent') {
//             $req = (float)($r->comp_required ?? 0);
//             $got = (float)($r->comp_count ?? 0);
//             $r->comp_ok = ($req > 0) ? (abs($got - $req) < 1e-9) : null; // null if no BOM
//         }
//         $r->url = null;
//     }

//     return response()->json(['items' => $rows]);
// }

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

    // Build core expressions (no aliases) for safe aggregation
    $channelSel = $hasQChannelCode ? 'q.channel_code'
                : ($hasQChannel   ? 'q.channel' : 'NULL');
    $batchSel   = $hasQBatchCode   ? 'q.batch_code'
                : ($hasQBatch      ? 'q.batch'
                : (($hasRunFk && $hasPrTable && $prHasBatchCode) ? 'pr.batch_code'
                : (($hasRunFk && $hasPrTable && $prHasBatch)     ? 'pr.batch' : 'NULL')));

    $runRootProductId = null;
    if ($hasPrTable) {
        $runRootProductId = \DB::connection($c)->table('print_runs_s')
            ->where('tenant_id', $tenant->id)
            ->where('id', (int)$runId)
            ->value('product_id');
    }

    // BOM qty column
    $qtyCol = null;
    if ($hasBomTable) {
        foreach (['quantity','component_qty','qty','required_qty','units','count'] as $cand) {
            if (\Schema::connection($c)->hasColumn('product_components_s', $cand)) { $qtyCol = $cand; break; }
        }
    }

    // Assembly child-device column
    $childDevCol = null;
    if ($hasAsmLinks) {
        foreach (['component_device_id','child_device_id'] as $cand) {
            if (\Schema::connection($c)->hasColumn('device_assembly_links_s', $cand)) { $childDevCol = $cand; break; }
        }
    }

    // Optional assembly qty column
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
        if ($devHasProductId) $q->leftJoin("$tp as ppar", 'ppar.id', '=', 'dpar.product_id');
    }
    if ($qtyCol) {
        $bomTotals = \DB::connection($c)->table('product_components_s')
            ->select('parent_product_id', \DB::raw("SUM($qtyCol) as req_total"))
            ->groupBy('parent_product_id');
        $q->leftJoinSub($bomTotals, 'bom', 'bom.parent_product_id', '=', 'p.id');
    }

    // comp_count
    $compCountExpr = !$hasAsmLinks
        ? '0 as comp_count'
        : ($asmQtyCol ? "COALESCE(SUM(ap.$asmQtyCol),0) as comp_count" : "COUNT(ap.id) as comp_count");

    $select = [
        'q.id','q.token',
        \Schema::connection($c)->hasColumn($qrc,'status') ? 'q.status' : \DB::raw('NULL as status'),
        'q.product_id',
        \Schema::connection($c)->hasColumn($qrc,'print_run_id') ? 'q.print_run_id' : \DB::raw('NULL as print_run_id'),

        // ✅ aggregate channel/batch so ONLY_FULL_GROUP_BY is happy and no phantom columns
        \DB::raw("MIN($channelSel) as channel"),
        \DB::raw("MIN($batchSel)   as batch"),

        // bound flag
        \DB::raw('CASE WHEN COUNT(DISTINCT l.id) > 0 THEN 1 ELSE 0 END as is_bound'),

        // ✅ send text, not binary
        \Schema::connection($c)->hasColumn($qrc,'human_code') ? 'q.human_code' : \DB::raw('NULL as human_code'),
        \Schema::connection($c)->hasColumn($qrc,'micro_chk')
            ? \DB::raw('UPPER(HEX(q.micro_chk)) as micro_hex')
            : \DB::raw('NULL as micro_hex'),

        'p.sku','p.name',
        \Schema::connection($c)->hasColumn($tp,'type') ? 'p.type' : \DB::raw("'standard' as type"),
        'd.device_uid',
        'dpar.device_uid as parent_device_uid',
    ];
    $select[] = ($hasAsmLinks && $childDevCol && $devHasProductId) ? 'ppar.sku as parent_sku' : \DB::raw('NULL as parent_sku');
    $select[] = \DB::raw($compCountExpr);
    $select[] = \DB::raw($qtyCol ? 'COALESCE(bom.req_total,0) as comp_required' : '0 as comp_required');

    // GROUP BY: only real columns we select from base tables (aggregates need not be grouped)
    $groupBy = [
        'q.id','q.token','q.status','q.product_id','q.print_run_id',
        'p.sku','p.name','p.type','d.device_uid','parent_device_uid',
        'q.human_code', // fine to include (functionally depends on q.id anyway)
    ];
    if ($hasAsmLinks && $childDevCol && $devHasProductId) $groupBy[] = 'ppar.sku';

    $rows = $q->groupBy($groupBy)->orderBy('q.id')->get($select);

    // Fallback root product id
    if (!$runRootProductId) {
        $first = $rows[0] ?? null;
        if ($first && isset($first->product_id)) $runRootProductId = (int)$first->product_id;
    }

    $seq = 0;
    foreach ($rows as $r) {
        $seq++;
        $r->seq_in_run = $seq;

        // Prefer stored human_code; else derive from micro_hex (first 8 bytes)
        $hc = $r->human_code;
        if (!$hc && $r->micro_hex) {
            $first8 = substr($r->micro_hex, 0, 16);      // 8 bytes = 16 hex chars
            $hc     = self::base32Crockford(hex2bin($first8), 13);
        }
        $r->human_code = $hc ? strtoupper($hc) : null;
        $r->micro_code = $r->human_code; // alias for UI

        $r->role = ((int)$r->product_id === (int)$runRootProductId) ? 'parent' : 'part';

        $r->comp_ok = null;
        if ($r->role === 'parent') {
            $req = (float)($r->comp_required ?? 0);
            $got = (float)($r->comp_count ?? 0);
            $r->comp_ok = ($req > 0) ? (abs($got - $req) < 1e-9) : null;
        }

        $r->url = null; // UI builds it
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