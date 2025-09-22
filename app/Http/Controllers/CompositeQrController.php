<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompositeQrController extends Controller
{
    /* =============================== PUBLIC API =============================== */

    /** Dev health check: tables/columns/BOM sanity */
    public function selfTest(Request $req)
    {
        $out = [];
        try {
            $conn     = $this->shared();
            $tenantId = $this->tenantId($req);
            $rootSku  = trim((string)$req->query('root_sku', 'BK-01'));

            $needTables = [
                'products_s',
                'product_components_s',
                'qr_codes_s',
                'devices_s',
                'device_qr_links_s',
                'device_assembly_links_s',
                // optional for UI:
                'print_runs_s',
                'product_batches_s',
                'qr_channels_s',
            ];
            $missing = [];
            foreach ($needTables as $t) {
                if (!Schema::connection($conn)->hasTable($t)) $missing[] = $t;
            }
            $out['tables'] = ['connection'=>$conn, 'missing'=>$missing, 'ok'=>count($missing)===0];
            if ($missing && count(array_diff($missing, ['print_runs_s','product_batches_s','qr_channels_s'])) > 0) {
                return response()->json(['ok'=>false,'message'=>'Missing required tables','details'=>$out], 422);
            }

            $col = fn($t)=> Schema::connection($conn)->getColumnListing($t);
            $bad = [];

            foreach (['id','tenant_id','sku','name','type','status'] as $w) {
                if (!in_array($w, $col('products_s'), true)) $bad[] = ['table'=>'products_s','missing'=>[$w]];
            }

            $pc = $col('product_components_s');
            foreach (['id','tenant_id'] as $w) if (!in_array($w, $pc, true)) $bad[] = ['table'=>'product_components_s','missing'=>[$w]];
            if (! (in_array('parent_product_id',$pc,true) || in_array('root_product_id',$pc,true)) )
                $bad[]=['table'=>'product_components_s','missing'=>['parent_product_id|root_product_id']];
            if (! (in_array('child_product_id',$pc,true) || in_array('component_product_id',$pc,true) || in_array('child_id',$pc,true)) )
                $bad[]=['table'=>'product_components_s','missing'=>['child_product_id|component_product_id|child_id']];
            if (! (in_array('quantity',$pc,true) || in_array('qty',$pc,true) || in_array('component_qty',$pc,true)) )
                $bad[]=['table'=>'product_components_s','missing'=>['quantity|qty|component_qty']];

            $qc = $col('qr_codes_s');
            foreach (['id','tenant_id','product_id','token'] as $w)
                if (!in_array($w,$qc,true)) $bad[]=['table'=>'qr_codes_s','missing'=>[$w]];
            // Your schema requires these; controller will set them if present:
            // token_ver, version (NOT NULL in your migration)
            // status, print_run_id, channel_id/batch_id, channel_code/batch_code are optional.

            foreach (['id','tenant_id','product_id','device_uid'] as $w)
                if (!in_array($w, $col('devices_s'), true)) $bad[] = ['table'=>'devices_s','missing'=>[$w]];

            foreach (['id','tenant_id','qr_code_id','device_id'] as $w)
                if (!in_array($w, $col('device_qr_links_s'), true)) $bad[] = ['table'=>'device_qr_links_s','missing'=>[$w]];

            $al = $col('device_assembly_links_s');
            if (!in_array('parent_device_id',$al,true))
                $bad[]=['table'=>'device_assembly_links_s','missing'=>['parent_device_id']];
            if (! (in_array('component_device_id',$al,true) || in_array('child_device_id',$al,true)) )
                $bad[]=['table'=>'device_assembly_links_s','missing'=>['component_device_id|child_device_id']];

            $out['columns'] = ['problems'=>$bad, 'ok'=>count($bad)===0];
            if ($bad) return response()->json(['ok'=>false,'message'=>'Missing columns detected','details'=>$out], 422);

            $root = DB::connection($conn)->table('products_s')->where('tenant_id',$tenantId)->where('sku',$rootSku)->first();
            if (!$root) return response()->json(['ok'=>false,'message'=>"Root product {$rootSku} not found for tenant {$tenantId}",'details'=>$out],404);

            $out['root'] = ['sku'=>$root->sku,'type'=>$root->type ?? null];

            if (strtolower($root->type ?? '') === 'composite') {
                $pid = (int)$root->id;
                $cnt = DB::connection($conn)->table('product_components_s')->where('tenant_id',$tenantId)->where(function($q)use($conn,$pid){
                    $cols=Schema::connection($conn)->getColumnListing('product_components_s');
                    $pc = in_array('parent_product_id',$cols,true)?'parent_product_id':'root_product_id';
                    $q->where($pc,$pid);
                })->count();
                if ($cnt===0) return response()->json(['ok'=>false,'message'=>"No BOM rows found for {$rootSku}",'details'=>$out],422);
            }

            return response()->json(['ok'=>true,'message'=>'Self-test passed','root_sku'=>$rootSku,'tenant_id'=>$tenantId,'details'=>$out],200);

        } catch (Throwable $e) {
            Log::error('SelfTest error', ['msg'=>$e->getMessage(), 'file'=>$e->getFile().':'.$e->getLine()]);
            return response()->json(['ok'=>false,'message'=>'Self-test failed','error'=>$e->getMessage()],500);
        }
    }

    /** One-click: mint + bind + assemble for root and all parts, and create batch/run for UI */
    public function mintAssemble(Request $req)
    {
        try {
            $conn     = $this->shared();
            $tenantId = $this->tenantId($req);

            $rootSku  = trim((string)$req->input('root_sku'));
            $qtyRoots = max(1, (int)$req->input('roots_qty', 1));
            $channel  = trim((string)$req->input('channel_code', 'WEB'));
            $batch    = trim((string)$req->input('batch_code', '')) ?: null;
            $vendor   = trim((string)$req->input('print_vendor', '')) ?: null;

            if ($rootSku === '') return response()->json(['ok'=>false,'message'=>'root_sku is required'],422);

            foreach (['products_s','qr_codes_s','devices_s','device_qr_links_s','product_components_s','device_assembly_links_s'] as $t) {
                if (!Schema::connection($conn)->hasTable($t)) {
                    return response()->json(['ok'=>false,'message'=>"Missing table: {$t}"],422);
                }
            }

            $root = $this->getProductBySku($conn, $tenantId, $rootSku);
            if (!$root) return response()->json(['ok'=>false,'message'=>"Root product {$rootSku} not found"],404);

            // Prepare channel & batch ids for joins used in your Batches → Runs UI
            [$channelId, $batchId] = $this->ensureChannelAndBatch($conn, $tenantId, (int)$root->id, $channel, $batch);

            // Create a print run linked to product+batch+channel with planned qty
            $printRunId = $this->createPrintRun($conn, $tenantId, $channel, $batch, $vendor, (int)$root->id, $qtyRoots, $channelId, $batchId);

            $stats = ['minted'=>[]];
            $roots = [];

            DB::connection($conn)->beginTransaction();
            try {
                // Token pool per product_id
                $pool = [];
                $pool[$root->id] = $this->pullAvailableTokens($conn, $tenantId, $root->id, $qtyRoots);
                $need = $qtyRoots - count($pool[$root->id]);
                if ($need > 0) {
                    $this->mintQrForProduct($conn, $tenantId, (int)$root->id, $need, $printRunId ?: null, $channel, $batch, $channelId, $batchId);
                    $fresh = $this->pullAvailableTokens($conn, $tenantId, $root->id, $need);
                    $pool[$root->id] = array_merge($pool[$root->id], $fresh);
                    $stats['minted'][$root->sku] = ($stats['minted'][$root->sku] ?? 0) + $need;
                }

                for ($n=1; $n <= $qtyRoots; $n++) {
                    $seq     = str_pad((string)$n, 4, '0', STR_PAD_LEFT);
                    $baseUid = $root->sku.'-'.$seq;
                    $rootUid = $this->uniqueDeviceUid($conn, $tenantId, $baseUid);
                    $rootDev = $this->createDevice($conn, $tenantId, (int)$root->id, $rootUid);

                    $rootQr = array_shift($pool[$root->id]);
                    if (!$rootQr) {
                        $this->mintQrForProduct($conn, $tenantId, (int)$root->id, 1, $printRunId ?: null, $channel, $batch, $channelId, $batchId);
                        $rootQr = $this->pullAvailableTokens($conn, $tenantId, $root->id, 1)[0] ?? null;
                    }
                    if ($rootQr) $this->bindTokenToDevice($conn, $tenantId, (int)$rootQr->id, (int)$rootDev->id);

                    $roots[] = ['device_uid'=>$rootDev->device_uid, 'qr_token'=>$rootQr->token ?? null];

                    // Recursively build all parts under this root
                    $this->buildTreeForOne($conn, $tenantId, $root, $rootDev, $pool, $channel, $batch, $printRunId, $stats, $channelId, $batchId);
                }

                DB::connection($conn)->commit();
                return response()->json([
                    'ok'           => true,
                    'print_run_id' => $printRunId ?: null,
                    'root'         => ['product_id'=>$root->id,'sku'=>$root->sku,'name'=>$root->name],
                    'roots'        => $roots,
                    'minted'       => $stats['minted'],
                ]);

            } catch (Throwable $txe) {
                DB::connection($conn)->rollBack();
                Log::error('Composite mint/assemble TX failed', [
                    'tenant'=>$tenantId,'root_sku'=>$rootSku,
                    'message'=>$txe->getMessage(),'file'=>$txe->getFile().':'.$txe->getLine()
                ]);
                return response()->json(['ok'=>false,'message'=>'Failed to mint/assemble','detail'=>$txe->getMessage()],500);
            }

        } catch (Throwable $e) {
            Log::error('Composite mint/assemble failed (outer)', [
                'message'=>$e->getMessage(),'file'=>$e->getFile().':'.$e->getLine()
            ]);
            return response()->json(['ok'=>false,'message'=>'Unexpected server error','detail'=>$e->getMessage()],500);
        }
    }

    /* ================================ HELPERS ================================ */

    protected function shared(): string
    {
        return 'domain_shared';
    }

    protected function tenantId(Request $req): int
    {
        $v = (int)$req->input('tenant_id', 0);
        return $v > 0 ? $v : (int)(config('app.dev_tenant_id', 2));
    }

    protected function colExists(string $conn, string $table, string $col): bool
    {
        return in_array($col, Schema::connection($conn)->getColumnListing($table), true);
    }

    protected function getProductBySku(string $conn, int $tenantId, string $sku): ?\stdClass
    {
        return DB::connection($conn)->table('products_s')->where('tenant_id',$tenantId)->where('sku',$sku)->first();
    }

    /** BOM children (adapts to column names) */
    protected function getBomChildren(string $conn, int $tenantId, int $parentProductId): array
    {
        $cols = Schema::connection($conn)->getColumnListing('product_components_s');

        $parentCol = in_array('parent_product_id',$cols,true) ? 'parent_product_id'
                   : (in_array('root_product_id',$cols,true) ? 'root_product_id' : 'parent_product_id');

        $childCol  = in_array('child_product_id',$cols,true) ? 'child_product_id'
                   : (in_array('component_product_id',$cols,true) ? 'component_product_id'
                   : (in_array('child_id',$cols,true) ? 'child_id' : null));
        if (!$childCol) {
            throw new \RuntimeException("product_components_s missing child product id column (child_product_id|component_product_id|child_id).");
        }

        $qtyCol    = in_array('quantity',$cols,true) ? 'quantity'
                   : (in_array('qty',$cols,true) ? 'qty'
                   : (in_array('component_qty',$cols,true) ? 'component_qty' : null));
        if (!$qtyCol) {
            throw new \RuntimeException("product_components_s missing quantity column (quantity|qty|component_qty).");
        }

        $rows = DB::connection($conn)->table('product_components_s as pc')
            ->join('products_s as c','c.id','=','pc.'.$childCol)
            ->selectRaw("c.id, c.sku, c.name, COALESCE(c.type,'standard') as type, pc.$qtyCol as quantity")
            ->where('pc.tenant_id',$tenantId)
            ->where('pc.'.$parentCol, $parentProductId)
            ->get();

        return $rows->map(fn($r)=>[
            'id'=>(int)$r->id,
            'sku'=>$r->sku,
            'name'=>$r->name,
            'type'=>strtolower($r->type ?? 'standard'),
            'quantity'=>max(1,(int)$r->quantity),
        ])->all();
    }

    /** Insert assembly link (supports component_device_id | child_device_id) */
    protected function insertAssemblyLink(string $conn, int $tenantId, int $parentDeviceId, int $childDeviceId, ?int $parentProductId=null, ?int $childProductId=null): void
    {
        $cols = Schema::connection($conn)->getColumnListing('device_assembly_links_s');
        $row  = ['tenant_id'=>$tenantId];

        if (!in_array('parent_device_id',$cols,true)) {
            throw new \RuntimeException("device_assembly_links_s missing parent_device_id");
        }
        $row['parent_device_id'] = $parentDeviceId;

        if (in_array('component_device_id',$cols,true)) {
            $row['component_device_id'] = $childDeviceId;
        } elseif (in_array('child_device_id',$cols,true)) {
            $row['child_device_id'] = $childDeviceId;
        } else {
            throw new \RuntimeException("device_assembly_links_s missing child device column (component_device_id|child_device_id).");
        }

        if ($parentProductId && in_array('parent_product_id',$cols,true)) $row['parent_product_id'] = $parentProductId;
        if ($childProductId  && in_array('child_product_id',$cols,true))  $row['child_product_id']  = $childProductId;

        $now = now();
        if (in_array('created_at',$cols,true)) $row['created_at'] = $now;
        if (in_array('updated_at',$cols,true)) $row['updated_at'] = $now;

        DB::connection($conn)->table('device_assembly_links_s')->insert($row);
    }

    /** Ensure unique device_uid by appending -N if needed */
    protected function uniqueDeviceUid(string $conn, int $tenantId, string $baseUid): string
    {
        $uid = $baseUid; $i = 1;
        while (DB::connection($conn)->table('devices_s')->where('tenant_id',$tenantId)->where('device_uid',$uid)->exists()) {
            $uid = $baseUid.'-'.(++$i);
        }
        return $uid;
    }

    /** Create device (timestamps only if present) */
    protected function createDevice(string $conn, int $tenantId, int $productId, string $deviceUid): \stdClass
    {
        $cols = Schema::connection($conn)->getColumnListing('devices_s');
        $row  = ['tenant_id'=>$tenantId,'product_id'=>$productId,'device_uid'=>$deviceUid];
        $now  = now();
        if (in_array('created_at',$cols,true)) $row['created_at'] = $now;
        if (in_array('updated_at',$cols,true)) $row['updated_at'] = $now;

        $id = DB::connection($conn)->table('devices_s')->insertGetId($row);
        return (object)['id'=>$id,'device_uid'=>$deviceUid];
    }

    /** Bind QR to device (and mark QR as bound if status exists) */
    protected function bindTokenToDevice(string $conn, int $tenantId, int $qrCodeId, int $deviceId): void
    {
        $cols = Schema::connection($conn)->getColumnListing('device_qr_links_s');
        $row  = ['tenant_id'=>$tenantId,'qr_code_id'=>$qrCodeId,'device_id'=>$deviceId];
        $now  = now();
        if (in_array('created_at',$cols,true)) $row['created_at'] = $now;
        if (in_array('updated_at',$cols,true)) $row['updated_at'] = $now;

        DB::connection($conn)->table('device_qr_links_s')->insert($row);

        if ($this->colExists($conn,'qr_codes_s','status')) {
            DB::connection($conn)->table('qr_codes_s')->where('id',$qrCodeId)->update(['status'=>'bound']);
        }
    }

    /** MINT TOKENS — sets token_ver & version if your schema has them; links channel/batch/run if columns exist */
    protected function mintQrForProduct(
        string $conn, int $tenantId, int $productId, int $count,
        ?int $printRunId, string $channel, ?string $batch,
        ?int $channelId = null, ?int $batchId = null
    ): void
    {
        if ($count <= 0) return;

        $cols = Schema::connection($conn)->getColumnListing('qr_codes_s');
        $rows = [];
        $now  = now();

        for ($i=0; $i<$count; $i++) {
            $token = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

            $r = [
                'tenant_id'  => $tenantId,
                'product_id' => $productId,
                'token'      => $token,
            ];

            // REQUIRED in your schema (NOT NULL)
            if (in_array('token_ver', $cols, true)) $r['token_ver'] = 1;
            if (in_array('version',   $cols, true)) $r['version']   = 1;

            // Status
            if (in_array('status', $cols, true)) $r['status'] = 'issued';

            // Numeric foreign keys (preferred for UI joins)
            if ($printRunId && in_array('print_run_id',$cols,true)) $r['print_run_id'] = $printRunId;
            if ($channelId  && in_array('channel_id',$cols,true))   $r['channel_id']   = $channelId;
            if ($batchId    && in_array('batch_id',$cols,true))     $r['batch_id']     = $batchId;

            // String fallbacks (if present in your schema)
            if (in_array('channel_code',$cols,true)) $r['channel_code'] = $channel;
            elseif (in_array('channel',$cols,true))  $r['channel']      = $channel;

            if ($batch) {
                if (in_array('batch_code',$cols,true)) $r['batch_code'] = $batch;
                elseif (in_array('batch',$cols,true))  $r['batch']      = $batch;
            }

            if (in_array('created_at',$cols,true)) $r['created_at'] = $now;
            if (in_array('updated_at',$cols,true)) $r['updated_at'] = $now;

            $rows[] = $r;
        }

        DB::connection($conn)->table('qr_codes_s')->insert($rows);
    }

    /** Pull unbound tokens for a product */
    protected function pullAvailableTokens(string $conn, int $tenantId, int $productId, int $limit): array
    {
        $q = DB::connection($conn)->table('qr_codes_s as q')
            ->leftJoin('device_qr_links_s as l','l.qr_code_id','=','q.id')
            ->where('q.tenant_id',$tenantId)
            ->where('q.product_id',$productId)
            ->whereNull('l.id')
            ->orderBy('q.id','asc')
            ->limit($limit)
            ->select('q.id','q.token');

        if ($this->colExists($conn,'qr_codes_s','status')) {
            $q->whereIn('q.status',['issued','available','new']);
        }
        return $q->get()->all();
    }

    /** Ensure channel + batch rows exist; return their IDs (or null if tables absent) */
    protected function ensureChannelAndBatch(string $conn, int $tenantId, int $rootProductId, string $channelCode, ?string $batchCode): array
    {
        // CHANNEL
        $channelId = null;
        if (Schema::connection($conn)->hasTable('qr_channels_s')) {
            // create if missing
            DB::connection($conn)->table('qr_channels_s')->updateOrInsert(
                ['tenant_id'=>$tenantId, 'code'=>$channelCode],
                ['name'=>$channelCode]
            );
            $channelId = (int) DB::connection($conn)->table('qr_channels_s')
                ->where('tenant_id',$tenantId)->where('code',$channelCode)->value('id');
        }

        // BATCH (for the ROOT product)
        $batchId = null;
        if ($batchCode && Schema::connection($conn)->hasTable('product_batches_s')) {
            $bCols = Schema::connection($conn)->getColumnListing('product_batches_s');
            $payload = ['tenant_id'=>$tenantId, 'batch_code'=>$batchCode];
            if (in_array('product_id',$bCols,true)) $payload['product_id'] = $rootProductId;

            DB::connection($conn)->table('product_batches_s')->updateOrInsert(
                ['tenant_id'=>$tenantId, 'batch_code'=>$batchCode],
                $payload
            );
            $batchId = (int) DB::connection($conn)->table('product_batches_s')
                ->where('tenant_id',$tenantId)->where('batch_code',$batchCode)->value('id');
        }

        return [$channelId ?: null, $batchId ?: null];
    }

    /** labels per root (root + all descendants) × roots_qty */
    protected function plannedQtyForRoot(string $conn, int $tenantId, int $rootProductId, int $qtyRoots): int
    {
        $seen = [];
        $perRoot = $this->countOne($conn, $tenantId, $rootProductId, $seen);
        return $perRoot * max(1, $qtyRoots);
    }

    protected function countOne(string $conn, int $tenantId, int $productId, array &$seen): int
    {
        if (isset($seen[$productId])) return 0; // guard re-visits
        $seen[$productId] = true;

        $children = $this->getBomChildren($conn, $tenantId, $productId);
        $sum = 1; // this product itself
        foreach ($children as $c) {
            $qty = max(1, (int)$c['quantity']);
            for ($i=0; $i<$qty; $i++) {
                $sum += $this->countOne($conn, $tenantId, (int)$c['id'], $seen);
            }
        }
        return $sum;
    }

    /** Create print run linked to product/batch/channel with planned qty; adapts to columns present */
    protected function createPrintRun(
        string $conn,
        int $tenantId,
        string $channel,
        ?string $batch,
        ?string $vendor,
        int $rootProductId,
        int $qtyRoots,
        ?int $channelId = null,
        ?int $batchId = null
    ): int
    {
        if (!Schema::connection($conn)->hasTable('print_runs_s')) return 0;

        $cols = Schema::connection($conn)->getColumnListing('print_runs_s');

        $row = ['tenant_id'=>$tenantId];

        // Numeric FKs (preferred for UI)
        if (in_array('product_id',$cols,true)) $row['product_id'] = $rootProductId;
        if ($channelId && in_array('channel_id',$cols,true)) $row['channel_id'] = $channelId;
        if ($batchId   && in_array('batch_id',$cols,true))   $row['batch_id']   = $batchId;

        // String fallbacks if present
        if (in_array('channel_code',$cols,true)) $row['channel_code'] = $channel;
        elseif (in_array('channel',$cols,true))  $row['channel']      = $channel;

        if ($batch) {
            if (in_array('batch_code',$cols,true)) $row['batch_code'] = $batch;
            elseif (in_array('batch',$cols,true))  $row['batch']      = $batch;
        }

        if ($vendor) {
            if (in_array('vendor_name',$cols,true)) $row['vendor_name'] = $vendor;
            elseif (in_array('vendor',$cols,true))  $row['vendor']      = $vendor;
        }

        // Planned quantity (root + all parts) × roots_qty
        $qtyPlanned = $this->plannedQtyForRoot($conn, $tenantId, $rootProductId, $qtyRoots);
        if (in_array('qty_planned',$cols,true)) $row['qty_planned'] = $qtyPlanned;

        $now = now();
        if (in_array('created_at',$cols,true)) $row['created_at'] = $now;
        if (in_array('updated_at',$cols,true)) $row['updated_at'] = $now;

        return (int) DB::connection($conn)->table('print_runs_s')->insertGetId($row);
    }

    /* =========================== RECURSIVE ASSEMBLY ========================== */

    protected function buildTreeForOne(
        string $conn,
        int $tenantId,
        \stdClass $parentProduct,
        \stdClass $parentDevice,
        array &$pool,
        string $channel,
        ?string $batch,
        ?int $printRunId,
        array &$stats,
        ?int $channelId = null,
        ?int $batchId = null
    ): void
    {
        $children = $this->getBomChildren($conn, $tenantId, (int)$parentProduct->id);
        if (!$children) return;

        foreach ($children as $c) {
            $qty = max(1, (int)$c['quantity']);

            if (!array_key_exists($c['id'], $pool)) $pool[$c['id']] = [];

            for ($i=1; $i <= $qty; $i++) {
                $seq      = str_pad((string)$i, 3, '0', STR_PAD_LEFT);
                $baseUid  = $parentDevice->device_uid . ':' . $c['sku'] . '-' . $seq;
                $childUid = $this->uniqueDeviceUid($conn, $tenantId, $baseUid);
                $childDev = $this->createDevice($conn, $tenantId, (int)$c['id'], $childUid);

                if (count($pool[$c['id']]) === 0) {
                    $this->mintQrForProduct($conn, $tenantId, (int)$c['id'], 1, $printRunId ?: null, $channel, $batch, $channelId, $batchId);
                    $pool[$c['id']] = array_merge($pool[$c['id']], $this->pullAvailableTokens($conn, $tenantId, (int)$c['id'], 1));
                    $stats['minted'][$c['sku']] = ($stats['minted'][$c['sku']] ?? 0) + 1;
                }

                $qr = array_shift($pool[$c['id']]) ?? null;
                if ($qr) $this->bindTokenToDevice($conn, $tenantId, (int)$qr->id, (int)$childDev->id);

                $this->insertAssemblyLink($conn, $tenantId, (int)$parentDevice->id, (int)$childDev->id, (int)$parentProduct->id, (int)$c['id']);

                if (strtolower($c['type']) === 'composite') {
                    $childProductObj = (object)['id'=>(int)$c['id'], 'sku'=>$c['sku'], 'name'=>$c['name'], 'type'=>$c['type']];
                    $this->buildTreeForOne($conn, $tenantId, $childProductObj, $childDev, $pool, $channel, $batch, $printRunId, $stats, $channelId, $batchId);
                }
            }
        }
    }
}
