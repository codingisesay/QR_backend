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

    /* -------- 2) Bulk bind (CSV/JSON) --------
       Payload variants:
       - { "devices":[ { "device_uid":"D1", "attrs":{...} }, ... ], "allocate": true, "batch_code":"B24-09", "product":"SKU-001" }
       - { "devices":[ { "device_uid":"D1", "token":"...", "attrs":{...} }, ... ], "product":"SKU-001" }
    */
    public function bulkBind(Request $req, $idOrSku)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $c = $this->sharedConn();
        $product = $this->findProduct($c, $tenant->id, $idOrSku);
        if (!$product) return response()->json(['message'=>'Product not found'], 404);

        $data = $req->validate([
            'allocate'   => ['sometimes','boolean'],  // true = auto-assign next available tokens
            'batch_code' => ['nullable','string','max:64'],
            'devices'    => ['required','array','min:1'],
            'devices.*.device_uid' => ['required','string','max:120'],
            'devices.*.token'      => ['nullable','string','max:128'],
            'devices.*.attrs'      => ['nullable','array'],
        ]);

        if (!Schema::connection($c)->hasTable('qr_codes_s')) return response()->json(['message'=>'QR table missing'], 500);

        $tplMissingAny = [];
        $countBound = 0;

        // Optionally restrict to a batch
        $qrQuery = DB::connection($c)->table('qr_codes_s')
            ->where('tenant_id',$tenant->id)->where('product_id',$product->id)
            ->where('status','issued');
        if (!empty($data['batch_code']) && Schema::connection($c)->hasTable('product_batches_s')) {
            $batchId = DB::connection($c)->table('product_batches_s')
                ->where('tenant_id',$tenant->id)->where('batch_code',$data['batch_code'])
                ->value('id');
            if ($batchId) $qrQuery->where('batch_id',$batchId);
        }

        $allocate = (bool)($data['allocate'] ?? false);

        DB::connection($c)->beginTransaction();
        try {
            foreach ($data['devices'] as $row) {
                $uid   = $row['device_uid'];
                $attrs = $row['attrs'] ?? [];

                // Validate against product template
                $missing = $this->validateAttrsAgainstTemplate($product->template_json ?? null, $attrs);
                if (!empty($missing)) {
                    $tplMissingAny[] = ['device_uid'=>$uid,'missing'=>$missing];
                    continue; // skip this device
                }

                // Upsert device
                $deviceId = DB::connection($c)->table('devices_s')->where([
                    'tenant_id'=>$tenant->id,'product_id'=>$product->id,'device_uid'=>$uid
                ])->value('id');
                if (!$deviceId) {
                    $deviceId = DB::connection($c)->table('devices_s')->insertGetId([
                        'tenant_id'=>$tenant->id,'product_id'=>$product->id,'device_uid'=>$uid,
                        'serial'=>$attrs['serial'] ?? null,
                        'attrs_json'=>empty($attrs) ? null : json_encode($attrs),
                        'status'=>'new','created_at'=>now(),'updated_at'=>now(),
                    ]);
                } else {
                    DB::connection($c)->table('devices_s')->where('id',$deviceId)->update([
                        'serial'=>$attrs['serial'] ?? DB::raw('serial'),
                        'attrs_json'=>empty($attrs) ? DB::raw('attrs_json') : json_encode($attrs),
                        'updated_at'=>now(),
                    ]);
                }

                // Enforce device not already linked
                $exists = DB::connection($c)->table('device_qr_links_s')->where([
                    'tenant_id'=>$tenant->id,'device_id'=>$deviceId
                ])->exists();
                if ($exists) continue;

                // Decide which QR to use
                if (!empty($row['token'])) {
                    $qr = DB::connection($c)->table('qr_codes_s')
                        ->where('tenant_id',$tenant->id)->where('product_id',$product->id)
                        ->where('token',$row['token'])->where('status','issued')->first(['id']);
                    if (!$qr) continue; // skip invalid token
                    $qrId = $qr->id;
                } elseif ($allocate) {
                    $qrRow = (clone $qrQuery)->orderBy('id')->lockForUpdate()->first(['id']); // FIFO
                    if (!$qrRow) break; // no more labels
                    $qrId = $qrRow->id;
                } else {
                    continue; // neither token provided nor allocation requested
                }

                // Bind
                DB::connection($c)->table('device_qr_links_s')->insert([
                    'tenant_id'=>$tenant->id,'device_id'=>$deviceId,'qr_code_id'=>$qrId,
                    'user_id'=>$req->user()?->id,'station_id'=>$req->header('X-Station') ?: null,
                    'bound_at'=>now(),
                ]);
                DB::connection($c)->table('qr_codes_s')->where('id',$qrId)->update(['status'=>'bound']);
                $countBound++;
            }

            DB::connection($c)->commit();
        } catch (\Throwable $e) {
            DB::connection($c)->rollBack();
            return response()->json(['message'=>'Bulk bind failed','error'=>$e->getMessage()], 500);
        }

        return response()->json([
            'bound'=>$countBound,
            'template_missing'=>$tplMissingAny,
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
}
