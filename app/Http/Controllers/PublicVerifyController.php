<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PublicVerifyController extends Controller
{
    protected function sharedConn(): string
    {
        return config('database.connections.domain_shared') ? 'domain_shared' : config('database.default');
    }
    protected function coreConn(): string
    {
        return config('database.connections.core') ? 'core' : config('database.default');
    }

    // BOM qty column detector (your migration uses "quantity")
    protected function bomQtyColumn(string $conn): ?string
    {
        $tbl = 'product_components_s';
        if (!Schema::connection($conn)->hasTable($tbl)) return null;
        foreach (['quantity','component_qty','qty','required_qty','units','count'] as $c) {
            if (Schema::connection($conn)->hasColumn($tbl, $c)) return $c;
        }
        return null;
    }

    // Assembly child-device column detector (yours is "component_device_id")
    protected function asmChildDeviceColumn(string $conn): ?string
    {
        $tbl = 'device_assembly_links_s';
        if (!Schema::connection($conn)->hasTable($tbl)) return null;
        foreach (['component_device_id','child_device_id'] as $c) {
            if (Schema::connection($conn)->hasColumn($tbl, $c)) return $c;
        }
        return null;
    }

    // Assembly qty column (optional; you don’t have one)
    protected function asmQtyColumn(string $conn): ?string
    {
        $tbl = 'device_assembly_links_s';
        if (!Schema::connection($conn)->hasTable($tbl)) return null;
        foreach (['component_qty_used','qty','quantity','units','count'] as $c) {
            if (Schema::connection($conn)->hasColumn($tbl, $c)) return $c;
        }
        return null;
    }

    protected function resolvePublicTenant(Request $req, ?string $tenantKey = null): ?object
    {
        $core = $this->coreConn();
        if (!Schema::connection($core)->hasTable('tenants')) {
            return (object)['id' => 1, 'slug' => 'dev'];
        }

        if ($tenantKey) {
            $q = DB::connection($core)->table('tenants');
            return ctype_digit($tenantKey) ? $q->where('id', (int)$tenantKey)->first()
                                           : $q->where('slug', $tenantKey)->first();
        }

        $host = $req->getHost();
        if ($host && strpos($host, '.') !== false) {
            $maybe = explode('.', $host)[0];
            if ($maybe && $maybe !== 'www' && $maybe !== 'localhost') {
                $hit = DB::connection($core)->table('tenants')->where('slug', $maybe)->first();
                if ($hit) return $hit;
            }
        }

        if ($t = $req->query('t')) {
            $q = DB::connection($core)->table('tenants');
            return ctype_digit($t) ? $q->where('id', (int)$t)->first()
                                   : $q->where('slug', $t)->first();
        }

        if ($hdr = $req->header('X-Tenant')) {
            $q = DB::connection($core)->table('tenants');
            return ctype_digit($hdr) ? $q->where('id', (int)$hdr)->first()
                                     : $q->where('slug', $hdr)->first();
        }

        return (object)['id' => 1, 'slug' => 'dev'];
    }

    protected function findTenantByToken(string $token): ?object
    {
        $shared = $this->sharedConn();
        if (!Schema::connection($shared)->hasTable('qr_codes_s')) return null;

        $row = DB::connection($shared)->table('qr_codes_s')->where('token', $token)->first(['tenant_id']);
        if (!$row || !$row->tenant_id) return null;

        $core = $this->coreConn();
        if (!Schema::connection($core)->hasTable('tenants')) {
            return (object)['id' => $row->tenant_id];
        }
        return DB::connection($core)->table('tenants')->where('id', $row->tenant_id)->first();
    }

    protected function buildVerifyPayload(int $tenantId, string $token): array
    {
        $c   = $this->sharedConn();
        $tp  = Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';
        $qrc = 'qr_codes_s';

        if (!Schema::connection($c)->hasTable($qrc)) {
            return ['found' => false, 'reason' => 'QR table missing'];
        }

        $q = DB::connection($c)->table("$qrc as q")->where('q.tenant_id', $tenantId)->where('q.token', $token);
        $q->leftJoin('device_qr_links_s as l', 'l.qr_code_id', '=', 'q.id');
        $q->leftJoin('devices_s as d', 'd.id', '=', 'l.device_id');
        $q->leftJoin("$tp as p", 'p.id', '=', 'q.product_id');

        $sel = [
            'q.id','q.token',
            Schema::connection($c)->hasColumn($qrc,'status') ? 'q.status' : DB::raw("NULL as status"),
            'q.product_id',
            Schema::connection($c)->hasColumn($qrc,'print_run_id') ? 'q.print_run_id' : DB::raw("NULL as print_run_id"),
            Schema::connection($c)->hasColumn($qrc,'channel_code') ? DB::raw('q.channel_code as channel') :
            (Schema::connection($c)->hasColumn($qrc,'channel') ? DB::raw('q.channel as channel') : DB::raw('NULL as channel')),
            Schema::connection($c)->hasColumn($qrc,'batch_code') ? DB::raw('q.batch_code as batch') :
            (Schema::connection($c)->hasColumn($qrc,'batch') ? DB::raw('q.batch as batch') : DB::raw('NULL as batch')),
            'p.id as __pid','p.sku','p.name',
            Schema::connection($c)->hasColumn($tp,'type') ? 'p.type' : DB::raw("'standard' as type"),
            Schema::connection($c)->hasColumn($tp,'status') ? 'p.status as p_status' : DB::raw('NULL as p_status'),
            'd.id as __did','d.device_uid',
            Schema::connection($c)->hasColumn('devices_s','attrs_json') ? 'd.attrs_json' : DB::raw('NULL as attrs_json'),
            Schema::connection($c)->hasColumn('devices_s','status') ? 'd.status as d_status' : DB::raw('NULL as d_status'),
            Schema::connection($c)->hasColumn('devices_s','created_at') ? 'd.created_at' : DB::raw('NULL as created_at'),
            Schema::connection($c)->hasColumn('devices_s','product_id') ? 'd.product_id as d_pid' : DB::raw('NULL as d_pid'),
        ];

        $qr = $q->first($sel);
        if (!$qr) return ['found' => false, 'reason' => 'Token not found'];

        $product = $qr->__pid ? [
            'id'     => $qr->__pid,
            'sku'    => $qr->sku,
            'name'   => $qr->name,
            'type'   => $qr->type,
            'status' => $qr->p_status,
        ] : null;

        $device = null; $attrs = [];
        if ($qr->__did) {
            if ($qr->attrs_json) {
                $dec = json_decode($qr->attrs_json, true);
                if (is_array($dec)) $attrs = $dec;
            }
            $device = [
                'id'         => $qr->__did,
                'device_uid' => $qr->device_uid,
                'status'     => $qr->d_status,
                'attrs'      => $attrs,
                'bound_at'   => $qr->created_at,
            ];
        }

        // Components + BOM coverage (only if parent device exists)
        $components = [];
        $bomCoverage = null;

        if ($device && Schema::connection($c)->hasTable('device_assembly_links_s')) {
            $childCol   = $this->asmChildDeviceColumn($c); // component_device_id or child_device_id
            $asmQtyCol  = $this->asmQtyColumn($c);         // usually null for you
            $devHasPid  = Schema::connection($c)->hasColumn('devices_s','product_id');
            $asmHasChildPid = Schema::connection($c)->hasColumn('device_assembly_links_s','child_product_id'); // you don't have this

            // children list
            $childrenQ = DB::connection($c)->table('device_assembly_links_s as a');
            if ($childCol) {
                $childrenQ->leftJoin('devices_s as dch', 'dch.id', '=', "a.$childCol");
            } else {
                $childrenQ->leftJoin('devices_s as dch', DB::raw('1'), DB::raw('1')); // harmless no-op
            }

            // Product for child: prefer a.child_product_id if exists; else dch.product_id
            if ($asmHasChildPid) {
                $childrenQ->leftJoin("$tp as pch", 'pch.id', '=', 'a.child_product_id');
                $pSku = 'pch.sku'; $pName = 'pch.name';
            } elseif ($devHasPid) {
                $childrenQ->leftJoin("$tp as pch", 'pch.id', '=', 'dch.product_id');
                $pSku = 'pch.sku'; $pName = 'pch.name';
            } else {
                $pSku = null; $pName = null;
            }

            $childrenQ->where('a.tenant_id', $tenantId)
                      ->where('a.parent_device_id', $device['id']);

            $select = [];
            $select[] = $pSku ? DB::raw("$pSku as sku") : DB::raw('NULL as sku');
            $select[] = $pName ? DB::raw("$pName as name") : DB::raw('NULL as name');
            $select[] = 'dch.device_uid';
            $select[] = $asmQtyCol ? DB::raw("a.$asmQtyCol as qty") : DB::raw('1 as qty');

            $children = $childrenQ->get($select);
            foreach ($children as $ch) {
                $components[] = [
                    'sku'        => $ch->sku,
                    'name'       => $ch->name,
                    'device_uid' => $ch->device_uid,
                    'qty'        => (float)$ch->qty,
                ];
            }

            // BOM coverage
            $qtyCol = $this->bomQtyColumn($c); // "quantity" in your schema
            if ($qtyCol && $product && $product['id']) {
                // required
                $reqRows = DB::connection($c)->table('product_components_s')
                    ->where(['tenant_id'=>$tenantId, 'parent_product_id'=>$product['id']])
                    ->select('child_product_id', DB::raw("SUM($qtyCol) as qty"))
                    ->groupBy('child_product_id')
                    ->get();
                $required = [];
                foreach ($reqRows as $r) $required[(int)$r->child_product_id] = (float)$r->qty;

                // current: group by child product id (via dch.product_id since your assembly table lacks child_product_id)
                $curExpr = $asmQtyCol ? "SUM($asmQtyCol)" : "COUNT(*)";
                $curQ = DB::connection($c)->table('device_assembly_links_s as a')
                    ->leftJoin('devices_s as dch', 'dch.id', '=', $childCol ? "a.$childCol" : 'a.parent_device_id') // safe fallback
                    ->where(['a.tenant_id'=>$tenantId,'a.parent_device_id'=>$device['id']]);

                if ($devHasPid) {
                    $curQ = $curQ->select('dch.product_id as pid', DB::raw("$curExpr as qty"))->groupBy('pid');
                } else {
                    $curQ = $curQ->select(DB::raw('0 as pid'), DB::raw("$curExpr as qty"))->groupBy('pid');
                }

                $curRows = $curQ->get();
                $current = [];
                foreach ($curRows as $r) $current[(int)$r->pid] = (float)$r->qty;

                $ok = true;
                foreach ($required as $pid => $need) {
                    $have = (float)($current[$pid] ?? 0);
                    if (abs($have - $need) > 1e-9) { $ok = false; break; }
                }

                $bomCoverage = ['required'=>$required, 'current'=>$current, 'ok'=>$ok];
            }
        }

        return [
            'found'     => true,
            'status'    => $qr->status,
            'token'     => $qr->token,
            'channel'   => $qr->channel,
            'batch'     => $qr->batch,
            'print_run' => $qr->print_run_id ?? null,
            'product'   => $product ? [
                'sku'    => $product['sku'],
                'name'   => $product['name'],
                'type'   => $product['type'],
                'status' => $product['status'],
            ] : null,
            'device'    => $device ? [
                'device_uid' => $device['device_uid'],
                'status'     => $device['status'],
                'attrs'      => $device['attrs'],
                'bound_at'   => $device['bound_at'],
            ] : null,
            'components'   => $components,
            'bom_coverage' => $bomCoverage,
        ];
    }

    protected function withDefaults(array $p): array
    {
        return array_merge([
            'found'=>false,'reason'=>null,'status'=>null,'token'=>null,
            'channel'=>null,'batch'=>null,'print_run'=>null,
            'product'=>null,'device'=>null,'components'=>[], 'bom_coverage'=>null,
        ], $p);
    }

    public function verify(Request $req, string $token)
    {
        $tenant  = $this->resolvePublicTenant($req, null);
        $payload = $this->buildVerifyPayload($tenant->id, $token);

        if (!$payload['found']) {
            if ($tkTenant = $this->findTenantByToken($token)) {
                $tenant  = $tkTenant;
                $payload = $this->buildVerifyPayload($tenant->id, $token);
            }
        }

        return view('verify', ['data' => $this->withDefaults($payload), 'tenant' => $tenant]);
    }

    public function verifyWithTenant(Request $req, $tenant, string $token)
    {
        $tenantObj = $this->resolvePublicTenant($req, (string)$tenant);
        $payload   = $this->buildVerifyPayload($tenantObj->id, $token);
        return view('verify', ['data' => $this->withDefaults($payload), 'tenant' => $tenantObj]);
    }
}
