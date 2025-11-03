<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeviceController extends Controller
{
    /* ---- reuse same helpers as QrController (copy-paste or extract to trait) ---- */
    protected function sharedConn(): string {
        if (config('database.connections.domain_shared')) return 'domain_shared';
        return config('database.default','mysql');
    }
    protected function coreConn(): string {
        if (config('database.connections.core')) return 'core';
        if (config('database.connections.saas_core')) return 'saas_core';
        return config('database.default','mysql');
    }
    protected function tenant(Request $req): ?object {
        if (app()->bound('tenant')) return app('tenant');
        $core = $this->coreConn();
        if (!Schema::connection($core)->hasTable('tenants')) return null;
        $key = $req->header('X-Tenant');
        if ($key) {
            $q = DB::connection($core)->table('tenants');
            $t = ctype_digit($key) ? $q->where('id',(int)$key)->first()
                                   : $q->where('slug',$key)->first();
            if ($t) return $t;
        }
        $u = $req->user();
        if ($u && isset($u->tenant_id)) {
            $t = DB::connection($core)->table('tenants')->where('id',(int)$u->tenant_id)->first();
            if ($t) return $t;
        }
        return null;
    }

    protected function findProduct($conn, $tenantId, $idOrSku, array $cols=['id','sku','name','type','template_json']) {
        $tp = Schema::connection($conn)->hasTable('products_s') ? 'products_s' : 'products';
        $q  = DB::connection($conn)->table($tp)->where('tenant_id',$tenantId);
        return is_numeric($idOrSku)
            ? $q->where('id',(int)$idOrSku)->first($cols)
            : $q->where('sku',$idOrSku)->first($cols);
    }

    protected function validateAttrsAgainstTemplate(?string $templateJson, array $attrs): array {
        $requiredMissing = [];
        if ($templateJson) {
            $tpl = json_decode($templateJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tpl['fields'] ?? null)) {
                foreach ($tpl['fields'] as $f) {
                    if (!empty($f['required'])) {
                        $key = (string)($f['key'] ?? '');
                        if ($key !== '' && (!array_key_exists($key, $attrs) || $attrs[$key] === null || $attrs[$key] === '')) {
                            $requiredMissing[] = $key;
                        }
                    }
                }
            }
        }
        return $requiredMissing; // [] if OK
    }

    /* -------- 1) Bind by token -------- */
    public function bindByToken(Request $req, string $token)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $data = $req->validate([
            'device_uid' => ['required','string','max:120'],
            'product_id' => ['nullable','integer'],  // optional if token already linked to product
            'attrs'      => ['nullable','array'],    // fields per template
        ]);

        $c = $this->sharedConn();
        if (!Schema::connection($c)->hasTable('qr_codes_s')) return response()->json(['message'=>'QR table missing'], 500);

        $qr = DB::connection($c)->table('qr_codes_s')
              ->where('tenant_id',$tenant->id)->where('token',$token)->first(['id','product_id','status']);
        if (!$qr) return response()->json(['message'=>'QR not found'], 404);
        if (($qr->status ?? 'issued') !== 'issued') {
            return response()->json(['message'=>'QR already bound/voided'], 422);
        }

        $productId = (int)($qr->product_id ?: 0);
        if (!$productId) $productId = (int)($data['product_id'] ?? 0);
        if (!$productId) return response()->json(['message'=>'Product unknown for this QR'], 422);

        $product = $this->findProduct($c, $tenant->id, $productId, ['id','sku','name','template_json']);
        if (!$product) return response()->json(['message'=>'Product not found'], 404);

        $attrs = $data['attrs'] ?? [];
        $missing = $this->validateAttrsAgainstTemplate($product->template_json ?? null, $attrs);
        if (!empty($missing)) {
            return response()->json(['message'=>'Missing required attributes','missing'=>$missing], 422);
        }

        // Upsert device (device_uid unique per tenant+product)
        $deviceId = DB::connection($c)->table('devices_s')->where([
            'tenant_id'=>$tenant->id,'product_id'=>$product->id,'device_uid'=>$data['device_uid']
        ])->value('id');

        if (!$deviceId) {
            $deviceId = DB::connection($c)->table('devices_s')->insertGetId([
                'tenant_id'=>$tenant->id,
                'product_id'=>$product->id,
                'device_uid'=>$data['device_uid'],
                'serial'=>$attrs['serial'] ?? null,
                'attrs_json'=>empty($attrs) ? null : json_encode($attrs),
                'status'=>'new',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        } else {
            DB::connection($c)->table('devices_s')->where('id',$deviceId)->update([
                'serial'=>$attrs['serial'] ?? DB::raw('serial'),
                'attrs_json'=>empty($attrs) ? DB::raw('attrs_json') : json_encode($attrs),
                'updated_at'=>now(),
            ]);
        }

        // Enforce 1-1 binding
        $existingForQr = DB::connection($c)->table('device_qr_links_s')->where([
            'tenant_id'=>$tenant->id,'qr_code_id'=>$qr->id
        ])->first();
        if ($existingForQr) return response()->json(['message'=>'QR already bound'], 422);

        $existingForDevice = DB::connection($c)->table('device_qr_links_s')->where([
            'tenant_id'=>$tenant->id,'device_id'=>$deviceId
        ])->first();
        if ($existingForDevice) return response()->json(['message'=>'Device already has a QR'], 422);

        // Link & mark QR as bound
        DB::connection($c)->table('device_qr_links_s')->insert([
            'tenant_id'=>$tenant->id,'device_id'=>$deviceId,'qr_code_id'=>$qr->id,
            'user_id'=>$req->user()?->id, 'station_id'=>$req->header('X-Station') ?: null,
            'bound_at'=>now(),
        ]);
        DB::connection($c)->table('qr_codes_s')->where('id',$qr->id)->update(['status'=>'bound']);

        return response()->json([
            'message'=>'Bound',
            'device_id'=>$deviceId,
            'qr_code_id'=>$qr->id,
            'product'=>['id'=>$product->id,'sku'=>$product->sku,'name'=>$product->name],
        ], 201);
    }

    /* -------- 2) Bulk bind by token -------- */

public function bulkBind(Request $req, $idOrSku)
{
    $tenant = $this->tenant($req);
    if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

    $c  = $this->sharedConn();
    $tp = \Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';

    // Resolve the SKU from path (default/root)
    $q = \DB::connection($c)->table($tp)->where('tenant_id',$tenant->id);
    $root = is_numeric($idOrSku)
        ? $q->where('id',(int)$idOrSku)->first(['id','sku'])
        : $q->where('sku',$idOrSku)->first(['id','sku']);
    if (!$root) return response()->json(['message'=>'Product not found'], 404);
    $rootPid = (int)$root->id;

    // 🔐 Accept new columns from CSV rows
    $data = $req->validate([
        'allocate'       => ['required','boolean'],          // true = auto-allocate; false = token provided per row
        'batch_code'     => ['nullable','string','max:64'],
        'channel_code'   => ['nullable','string','max:40'],
        'devices'        => ['required','array','min:1'],

        // one-file composite support
        'devices.*.sku'               => ['nullable','string','max:64'],
        'devices.*.device_uid'        => ['required','string','max:191'],
        'devices.*.parent_device_uid' => ['nullable','string','max:191'],
        'devices.*.token'             => ['nullable','string','max:191'],
        'devices.*.serial'            => ['nullable','string','max:191'],
        'devices.*.attrs'             => ['nullable','array'],

        // NEW: per-row QR/NFC/PUF fields (all optional)
        'devices.*.nfc_key_ref'          => ['nullable','string','max:64'],
        'devices.*.nfc_uid'              => ['nullable','string','max:32'],
        'devices.*.nfc_ctr_last'         => ['nullable','integer','min:0'],
        'devices.*.puf_id'               => ['nullable','string','max:64'],
        'devices.*.puf_fingerprint_hash' => ['nullable','string','size:64'],
        'devices.*.puf_alg'              => ['nullable','string','max:40'],
        'devices.*.puf_score_threshold'  => ['nullable','numeric','min:0','max:100'],
        'devices.*.expires_at'           => ['nullable','date'],  // QR expiry override
        'devices.*.mfg_date'             => ['nullable','date'],  // kept in attrs unless you add columns
        'devices.*.exp_date'             => ['nullable','date'],  // kept in attrs unless you add columns
        'devices.*.status'               => ['nullable','in:bound,active,sold,returned,void'], // optional device status override
    ]);

    // Optional filters (limit the QR pool)
    $batchId = null;
    if (!empty($data['batch_code']) && \Schema::connection($c)->hasTable('product_batches_s')) {
        $batchId = \DB::connection($c)->table('product_batches_s')
            ->where('tenant_id',$tenant->id)->where('batch_code',$data['batch_code'])
            ->value('id');
    }
    $channelId = null;
    if (!empty($data['channel_code']) && \Schema::connection($c)->hasTable('qr_channels_s')) {
        $channelId = \DB::connection($c)->table('qr_channels_s')
            ->where('tenant_id',$tenant->id)->where('code',$data['channel_code'])
            ->value('id');
    }

    $rows = collect($data['devices']);

    // Detect one-file composite (sku present at row or inside attrs)
    $compositeMode = $rows->contains(function($r){
        return !empty($r['sku']) || !empty(($r['attrs']['sku'] ?? null));
    });

    // Map present SKUs -> product_id
    $pidBySku = [];
    if ($compositeMode) {
        $skus = $rows->map(function($r){
            return trim(($r['sku'] ?? '') ?: ($r['attrs']['sku'] ?? ''));
        })->filter()->unique()->values()->all();

        if ($skus) {
            $found = \DB::connection($c)->table($tp)
                ->where('tenant_id',$tenant->id)->whereIn('sku',$skus)->get(['id','sku']);
            foreach ($found as $f) $pidBySku[$f->sku] = (int)$f->id;

            $missing = array_values(array_diff($skus, array_keys($pidBySku)));
            if ($missing) {
                return response()->json(['message'=>'Unknown SKU(s) in file','skus'=>$missing], 422);
            }
        }
    }

    // Small helpers
    $hexClean = function(?string $s) {
        if (!$s) return null;
        $u = strtoupper(preg_replace('/[^0-9A-Fa-f]/','', $s));
        return $u === '' ? null : $u;
    };
    $isHex = function(?string $s, int $len=null) {
        if (!$s) return false;
        if (!preg_match('/^[0-9A-F]+$/i', $s)) return false;
        return $len ? strlen($s) === $len : true;
    };

    $bound = 0; $errors = [];
    $uidToId = [];                 // device_uid => devices_s.id
    $boundBySku = [];

    \DB::connection($c)->beginTransaction();
    try {
        // Pass 1: upsert devices and bind QR
        foreach ($rows as $idx => $row) {
            // Extract per-row SKU (top-level or attrs.sku). Remove from attrs if present.
            $rowSku = trim(($row['sku'] ?? '') ?: ($row['attrs']['sku'] ?? ''));
            if (isset($row['attrs']['sku'])) unset($row['attrs']['sku']);

            $effectiveSku = $compositeMode ? ($rowSku ?: $root->sku) : $root->sku;
            $pid = $compositeMode ? ($pidBySku[$effectiveSku] ?? $rootPid) : $rootPid;

            $deviceUid = trim((string)($row['device_uid'] ?? ''));
            if ($deviceUid === '') { $errors[] = "Row ".($idx+1).": Missing device_uid"; continue; }

            // Pull out new "known" QR fields before we build attrs
            $nfcKeyRef   = isset($row['nfc_key_ref'])   ? trim((string)$row['nfc_key_ref']) : null;
            $nfcUid      = $hexClean($row['nfc_uid'] ?? null);
            $nfcCtrLast  = isset($row['nfc_ctr_last']) && $row['nfc_ctr_last'] !== '' ? (int)$row['nfc_ctr_last'] : null;

            $pufId       = isset($row['puf_id']) ? trim((string)$row['puf_id']) : null;
            $pufFp       = $hexClean($row['puf_fingerprint_hash'] ?? null);
            $pufAlg      = isset($row['puf_alg']) ? trim((string)$row['puf_alg']) : null;
            $pufThr      = isset($row['puf_score_threshold']) && $row['puf_score_threshold'] !== '' ? (float)$row['puf_score_threshold'] : null;

            $expiresAt   = !empty($row['expires_at']) ? \Carbon\Carbon::parse($row['expires_at']) : null;

            // Collate attrs (exclude all known keys)
            $attrs = [];
            if (!empty($row['attrs']) && is_array($row['attrs'])) {
                $attrs = $row['attrs'];
            } else {
                $known = [
                    'sku','device_uid','parent_device_uid','token','serial','attrs',
                    'nfc_key_ref','nfc_uid','nfc_ctr_last','puf_id','puf_fingerprint_hash','puf_alg','puf_score_threshold',
                    'expires_at','mfg_date','exp_date','status'
                ];
                foreach ($row as $k=>$v) if (!in_array($k,$known,true)) $attrs[$k] = $v;
            }
            // Preserve mfg/exp in attrs unless you have columns on devices_s
            if (!empty($row['mfg_date'])) $attrs['mfg_date'] = $row['mfg_date'];
            if (!empty($row['exp_date'])) $attrs['exp_date'] = $row['exp_date'];

            // 1) Upsert device (default status 'bound' if not provided)
            \DB::connection($c)->table('devices_s')->updateOrInsert(
                ['tenant_id'=>$tenant->id,'product_id'=>$pid,'device_uid'=>$deviceUid],
                [
                    'serial'     => $row['serial'] ?? ($attrs['serial'] ?? null),
                    'attrs_json' => $attrs ? json_encode($attrs) : null,
                    'status'     => $row['status'] ?? 'bound',
                    'updated_at' => now(),
                    'created_at' => now(), // harmless if exists (ignored on update)
                ]
            );
            $deviceId = \DB::connection($c)->table('devices_s')
                ->where('tenant_id',$tenant->id)->where('product_id',$pid)->where('device_uid',$deviceUid)
                ->value('id');
            $uidToId[$deviceUid] = $deviceId;

            // 2) Allocate / validate a token for THIS pid (per-SKU pool)
            $token = $row['token'] ?? null;
            if ($data['allocate']) {
                $qCodes = \DB::connection($c)->table('qr_codes_s')
                    ->where('tenant_id',$tenant->id)->where('product_id',$pid)
                    ->where('status','issued');
                if ($batchId)   $qCodes->where('batch_id',$batchId);
                if ($channelId) $qCodes->where('channel_id',$channelId);

                $qr = $qCodes->orderBy('id')->first(['id','token','verification_mode']);
                if (!$qr) { $errors[] = "Row ".($idx+1).": No available QR for SKU {$effectiveSku}"; continue; }
            } else {
                if (!$token) { $errors[] = "Row ".($idx+1).": Missing token for device {$deviceUid} (SKU {$effectiveSku})"; continue; }
                $qr = \DB::connection($c)->table('qr_codes_s')
                    ->where('tenant_id',$tenant->id)->where('product_id',$pid)
                    ->where('token',$token)->where('status','issued')
                    ->first(['id','token','verification_mode']);
                if (!$qr) { $errors[] = "Row ".($idx+1).": Invalid/unavailable token '{$token}' for SKU {$effectiveSku}"; continue; }
            }

            // 2.1) Per-row QR updates from CSV (only set when provided)
            $upd = ['updated_at'=>now()];

            if ($expiresAt) $upd['expires_at'] = $expiresAt;

            // NFC: basic validations and uniqueness check for UID
            $vm = strtolower($qr->verification_mode ?? '');
            $needsNfc = str_contains($vm, 'nfc');

            if ($nfcUid !== null) {
                // normalize, ensure hex and a reasonable length (4–32 hex chars)
                if (!$isHex($nfcUid) || strlen($nfcUid) < 4 || strlen($nfcUid) > 32) {
                    $errors[] = "Row ".($idx+1).": Invalid nfc_uid '{$row['nfc_uid']}'";
                } else {
                    $dup = \DB::connection($c)->table('qr_codes_s')
                        ->where('tenant_id',$tenant->id)
                        ->where('nfc_uid',$nfcUid)
                        ->where('id','<>',$qr->id)
                        ->exists();
                    if ($dup) {
                        $errors[] = "Row ".($idx+1).": nfc_uid '{$nfcUid}' is already enrolled for another code";
                    } else {
                        $upd['nfc_uid'] = $nfcUid;
                    }
                }
            } elseif ($needsNfc) {
                // if required by code mode and missing, we can still bind the device but warn
                // (comment out if you want to hard-block)
                // $errors[] = "Row ".($idx+1).": nfc_uid required for NFC code";
            }

            if ($nfcKeyRef)     $upd['nfc_key_ref']  = $nfcKeyRef;
            if ($nfcCtrLast!==null) $upd['nfc_ctr_last'] = max(0, (int)$nfcCtrLast);

            // PUF: basic validations
            $needsPuf = str_contains($vm, 'puf');
            if ($pufId)            $upd['puf_id'] = $pufId;
            if ($pufAlg)           $upd['puf_alg'] = $pufAlg;
            if ($pufThr !== null)  $upd['puf_score_threshold'] = $pufThr;

            if ($pufFp !== null) {
                if (!$isHex($pufFp, 64)) {
                    $errors[] = "Row ".($idx+1).": puf_fingerprint_hash must be 64 hex chars";
                } else {
                    $upd['puf_fingerprint_hash'] = strtoupper($pufFp);
                }
            } elseif ($needsPuf) {
                // optional warning if PUF is required by mode
                // $errors[] = "Row ".($idx+1).": puf_fingerprint_hash required for PUF code";
            }

            if (count($upd) > 1) { // something to update beyond updated_at
                \DB::connection($c)->table('qr_codes_s')->where('id',$qr->id)->update($upd);
            }

            // 3) Link QR → device (idempotent) and mark QR bound
            \DB::connection($c)->table('device_qr_links_s')->updateOrInsert(
                ['tenant_id'=>$tenant->id,'qr_code_id'=>$qr->id],
                ['device_id'=>$deviceId,'updated_at'=>now(),'created_at'=>now()]
            );
            \DB::connection($c)->table('qr_codes_s')->where('id',$qr->id)
                ->update(['status'=>'bound','activated_at'=>now()]);

            // Optional mirror in product_codes_s
            if (\Schema::connection($c)->hasTable('product_codes_s')) {
                \DB::connection($c)->table('product_codes_s')
                    ->where('tenant_id',$tenant->id)->where('code',$qr->token)
                    ->update(['status'=>'consumed','updated_at'=>now()]);
            }

            $bound++;
            $boundBySku[$effectiveSku] = ($boundBySku[$effectiveSku] ?? 0) + 1;
        }

        // Pass 2: create assemblies using parent_device_uid
        if (\Schema::connection($c)->hasTable('device_assembly_links_s')) {
            foreach ($rows as $row) {
                $childUid  = (string)($row['device_uid'] ?? '');
                $parentUid = (string)($row['parent_device_uid'] ?? '');
                if ($parentUid === '' || $childUid === '') continue;

                $parentId = $uidToId[$parentUid] ?? \DB::connection($c)->table('devices_s')
                    ->where('tenant_id',$tenant->id)->where('device_uid',$parentUid)
                    ->orderByDesc('updated_at')->value('id');
                $childId  = $uidToId[$childUid] ?? \DB::connection($c)->table('devices_s')
                    ->where('tenant_id',$tenant->id)->where('device_uid',$childUid)
                    ->orderByDesc('updated_at')->value('id');

                if ($parentId && $childId) {
                    \DB::connection($c)->table('device_assembly_links_s')->updateOrInsert(
                        ['tenant_id'=>$tenant->id,'parent_device_id'=>$parentId,'component_device_id'=>$childId],
                        ['updated_at'=>now(),'created_at'=>now()]
                    );
                }
            }
        }

        \DB::connection($c)->commit();
    } catch (\Throwable $e) {
        \DB::connection($c)->rollBack();
        throw $e;
    }

    return response()->json([
        'root_sku'      => $root->sku,
        'mode'          => $compositeMode ? 'composite-file' : 'single-sku',
        'bound_total'   => $bound,
        'bound_by_sku'  => $boundBySku,
        'errors'        => $errors,
    ]);
}


    /* -------- lookup device -------- */
    public function show(Request $req, string $deviceUid)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);
        $c = $this->sharedConn();

        $dev = DB::connection($c)->table('devices_s')->where('tenant_id',$tenant->id)
              ->where('device_uid',$deviceUid)->first(['id','product_id','serial','attrs_json','status','created_at']);
        if (!$dev) return response()->json(['message'=>'Device not found'], 404);

        $link = DB::connection($c)->table('device_qr_links_s')->where('tenant_id',$tenant->id)
              ->where('device_id',$dev->id)->first(['qr_code_id','bound_at']);
        $token = null;
        if ($link) {
            $token = DB::connection($c)->table('qr_codes_s')->where('id',$link->qr_code_id)->value('token');
        }

        return response()->json([
            'device'=> $dev,
            'token' => $token,
            'bound_at' => $link->bound_at ?? null,
        ]);
    }

public function bindBulk(Request $req, $idOrSku)
{
    $tenant = $this->tenant($req);
    if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

    $payload = $req->validate([
        'allocate'       => ['required','boolean'], // true = auto-allocate; false = token provided per row
        'batch_code'     => ['nullable','string','max:64'],
        'channel_code'   => ['nullable','string','max:40'],
        'devices'        => ['required','array','min:1'],

        // one-file composite support
        'devices.*.sku'               => ['nullable','string','max:64'],
        'devices.*.device_uid'        => ['required','string','max:191'],
        'devices.*.parent_device_uid' => ['nullable','string','max:191'],
        'devices.*.token'             => ['nullable','string','max:191'],
        'devices.*.serial'            => ['nullable','string','max:191'],

        // allow arbitrary attributes in { attrs: { ... } }
        'devices.*.attrs'             => ['nullable','array'],
    ]);

    $c  = $this->sharedConn();
    $tp = \Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';

    // Resolve the SKU from path (root/default)
    $q = \DB::connection($c)->table($tp)->where('tenant_id',$tenant->id);
    $root = is_numeric($idOrSku)
        ? $q->where('id',(int)$idOrSku)->first(['id','sku'])
        : $q->where('sku',$idOrSku)->first(['id','sku']);
    if (!$root) return response()->json(['message'=>'Unknown product'], 422);
    $rootPid = (int)$root->id;

    // Optional filters
    $batchId = null;
    if (!empty($payload['batch_code']) && \Schema::connection($c)->hasTable('product_batches_s')) {
        $batchId = \DB::connection($c)->table('product_batches_s')
            ->where('tenant_id',$tenant->id)->where('batch_code',$payload['batch_code'])
            ->value('id');
    }
    $channelId = null;
    if (!empty($payload['channel_code']) && \Schema::connection($c)->hasTable('qr_channels_s')) {
        $channelId = \DB::connection($c)->table('qr_channels_s')
            ->where('tenant_id',$tenant->id)->where('code',$payload['channel_code'])
            ->value('id');
    }

    $rows = collect($payload['devices']);

    // ✅ COMPOSITE MODE DETECTION (top-level sku OR attrs.sku in any row)
    $compositeMode = $rows->contains(function($r){
        return !empty($r['sku']) || !empty(($r['attrs']['sku'] ?? null));
    });

    // Map only SKUs present in file → product_id
    $pidBySku = [];
    if ($compositeMode) {
        $skus = $rows->map(function($r){
            return trim(($r['sku'] ?? '') ?: ($r['attrs']['sku'] ?? ''));
        })->filter()->unique()->values()->all();

        if ($skus) {
            $found = \DB::connection($c)->table($tp)
                ->where('tenant_id',$tenant->id)->whereIn('sku',$skus)->get(['id','sku']);
            foreach ($found as $f) $pidBySku[$f->sku] = (int)$f->id;

            // Validate all SKUs exist
            $missing = array_values(array_diff($skus, array_keys($pidBySku)));
            if ($missing) {
                return response()->json(['message'=>'Unknown SKU(s) in file','skus'=>$missing], 422);
            }

            // Optional: ensure SKUs are within root’s BOM closure
            if (\Schema::connection($c)->hasTable('product_components_s')) {
                $okIds = [$rootPid=>true];
                $stack = [$rootPid];
                while ($stack) {
                    $pid = array_pop($stack);
                    $kids = \DB::connection($c)->table('product_components_s')
                        ->where('tenant_id',$tenant->id)->where('parent_product_id',$pid)
                        ->pluck('child_product_id')->all();
                    foreach ($kids as $cid) {
                        if (!isset($okIds[$cid])) { $okIds[$cid]=true; $stack[]=(int)$cid; }
                    }
                }
                $bad = [];
                foreach ($pidBySku as $sku => $pid) if (!isset($okIds[$pid])) $bad[]=$sku;
                if ($bad) return response()->json(['message'=>'SKU(s) not in root BOM','skus'=>$bad], 422);
            }
        }
    }

    $bound = 0; $errors = [];
    $uidToId = []; // device_uid => devices_s.id
    $boundBySku = [];

    // Pass 1: upsert devices and bind QR
    foreach ($rows as $row) {
        // Extract per-row SKU (top-level or attrs.sku), and avoid storing it twice
        $rowSku = trim(($row['sku'] ?? '') ?: ($row['attrs']['sku'] ?? ''));
        if (isset($row['attrs']['sku'])) unset($row['attrs']['sku']);

        $effectiveSku = $compositeMode ? ($rowSku ?: $root->sku) : $root->sku;
        $pid = $compositeMode ? ($pidBySku[$effectiveSku] ?? $rootPid) : $rootPid;

        $deviceUid = trim((string)($row['device_uid'] ?? ''));
        if ($deviceUid === '') { $errors[] = "Missing device_uid"; continue; }

        // 1) Upsert device with attributes
        $attrs = [];
        if (!empty($row['attrs']) && is_array($row['attrs'])) {
            $attrs = $row['attrs'];
        } else {
            // backward-compat: treat unknown keys as attrs
            $known = ['sku','device_uid','parent_device_uid','token','serial','attrs'];
            foreach ($row as $k=>$v) if (!in_array($k,$known,true)) $attrs[$k] = $v;
        }

        \DB::connection($c)->table('devices_s')->updateOrInsert(
            ['tenant_id'=>$tenant->id,'product_id'=>$pid,'device_uid'=>$deviceUid],
            [
                'serial'     => $row['serial'] ?? ($attrs['serial'] ?? null),
                'attrs_json' => $attrs ? json_encode($attrs) : null,
                'status'     => 'bound',
                'updated_at' => now(),
            ]
        );
        $deviceId = \DB::connection($c)->table('devices_s')
            ->where('tenant_id',$tenant->id)->where('product_id',$pid)->where('device_uid',$deviceUid)
            ->value('id');
        $uidToId[$deviceUid] = $deviceId;

        // 2) Allocate / validate a token for THIS pid (SKU)
        $token = $row['token'] ?? null;
        if ($payload['allocate']) {
            $qCodes = \DB::connection($c)->table('qr_codes_s')
                ->where('tenant_id',$tenant->id)->where('product_id',$pid)
                ->where('status','issued');
            if ($batchId)   $qCodes->where('batch_id',$batchId);
            if ($channelId) $qCodes->where('channel_id',$channelId);

            $qr = $qCodes->orderBy('id')->first(['id','token']);
            if (!$qr) { $errors[] = "No available QR for SKU {$effectiveSku}"; continue; }
        } else {
            if (!$token) { $errors[] = "Missing token for device {$deviceUid} (SKU {$effectiveSku})"; continue; }
            $qr = \DB::connection($c)->table('qr_codes_s')
                ->where('tenant_id',$tenant->id)->where('product_id',$pid)
                ->where('token',$token)->where('status','issued')
                ->first(['id','token']);
            if (!$qr) { $errors[] = "Invalid/unavailable token '{$token}' for SKU {$effectiveSku}"; continue; }
        }

        // 3) Link QR → device and mark states
        \DB::connection($c)->table('device_qr_links_s')->updateOrInsert(
            ['tenant_id'=>$tenant->id,'qr_code_id'=>$qr->id],
            ['device_id'=>$deviceId,'updated_at'=>now()]
        );
        \DB::connection($c)->table('qr_codes_s')->where('id',$qr->id)
            ->update(['status'=>'bound','activated_at'=>now()]);
        if (\Schema::connection($c)->hasTable('product_codes_s')) {
            \DB::connection($c)->table('product_codes_s')
                ->where('tenant_id',$tenant->id)->where('code',$qr->token)
                ->update(['status'=>'consumed','updated_at'=>now()]);
        }

        $bound++;
        $boundBySku[$effectiveSku] = ($boundBySku[$effectiveSku] ?? 0) + 1;
    }

    // Pass 2: create device assemblies
    if (\Schema::connection($c)->hasTable('device_assembly_links_s')) {
        foreach ($rows as $row) {
            $childUid  = (string)($row['device_uid'] ?? '');
            $parentUid = (string)($row['parent_device_uid'] ?? '');
            if ($parentUid === '' || $childUid === '') continue;

            $parentId = $uidToId[$parentUid] ?? \DB::connection($c)->table('devices_s')
                ->where('tenant_id',$tenant->id)->where('device_uid',$parentUid)
                ->orderByDesc('updated_at')->value('id');
            $childId  = $uidToId[$childUid] ?? \DB::connection($c)->table('devices_s')
                ->where('tenant_id',$tenant->id)->where('device_uid',$childUid)
                ->orderByDesc('updated_at')->value('id');

            if ($parentId && $childId) {
                \DB::connection($c)->table('device_assembly_links_s')->updateOrInsert(
                    ['tenant_id'=>$tenant->id,'parent_device_id'=>$parentId,'component_device_id'=>$childId],
                    ['updated_at'=>now()]
                );
            }
        }
    }

    return response()->json([
        'root_sku'      => $root->sku,
        'mode'          => $compositeMode ? 'composite-file' : 'single-sku',
        'bound_total'   => $bound,
        'bound_by_sku'  => $boundBySku,
        'errors'        => $errors,
    ]);
}


}