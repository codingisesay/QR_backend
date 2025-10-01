<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PublicVerifyController extends Controller
{
    /* =========================
     * Small helpers
     * ========================= */

    protected function sharedConn(): string
    {
        // Shared-domain DB (your 'domain_shared' connection), else fallback to default
        return config('database.connections.domain_shared') ? 'domain_shared' : config('database.default');
    }

    protected function coreConn(): string
    {
        // Core app DB (your 'mysql' in database.php), else fallback to default
        return config('database.connections.mysql') ? 'mysql' : config('database.default');
    }

    protected function mask(string $s, int $head = 3, int $tail = 3): ?string
    {
        if ($s === '') return null;
        return strlen($s) <= $head + $tail ? $s : substr($s, 0, $head) . '•••' . substr($s, -$tail);
    }

    // Normalize user-provided micro/human code (Crockford Base32; tolerate I/L/O)
    protected function normalizeMicro(?string $s): string
    {
        if ($s === null) return '';
        $u = strtoupper(preg_replace('/[^0-9A-Z]/', '', $s));
        return strtr($u, ['I' => '1', 'L' => '1', 'O' => '0']);
    }

    /* =========================
     * Tenant resolution (schema-aware)
     * ========================= */

    protected function tenantSelectableColumns(string $conn): array
    {
        $cols = ['id'];
        foreach (['name','display_name','company_name','org_name','title','slug'] as $c) {
            if (Schema::connection($conn)->hasColumn('tenants', $c)) $cols[] = $c;
        }
        return $cols;
    }

    protected function getTenantById(int $id): ?object
    {
        $core = $this->coreConn();
        if (!Schema::connection($core)->hasTable('tenants')) {
            return (object)['id' => $id];
        }
        $cols = $this->tenantSelectableColumns($core);
        return DB::connection($core)->table('tenants')->select($cols)->where('id', $id)->first();
    }

    protected function getTenantByKey(string $key): ?object
    {
        $core = $this->coreConn();
        if (!Schema::connection($core)->hasTable('tenants')) {
            return (object)['id' => 1];
        }
        $cols = $this->tenantSelectableColumns($core);
        $q = DB::connection($core)->table('tenants')->select($cols);
        if (ctype_digit($key)) return $q->where('id', (int)$key)->first();

        if (Schema::connection($core)->hasColumn('tenants', 'slug')) {
            return $q->where('slug', $key)->first();
        }
        if (Schema::connection($core)->hasColumn('tenants', 'name')) {
            return $q->where('name', $key)->first();
        }
        if (Schema::connection($core)->hasColumn('tenants', 'title')) {
            return $q->where('title', $key)->first();
        }
        return null;
    }

    protected function resolvePublicTenant(Request $req, ?string $tenantKey = null): ?object
    {
        $core = $this->coreConn();
        if (!Schema::connection($core)->hasTable('tenants')) {
            return (object)['id' => 1, 'slug' => 'dev'];
        }

        if ($tenantKey) return $this->getTenantByKey($tenantKey);

        // subdomain
        $host = $req->getHost();
        if ($host && strpos($host, '.') !== false) {
            $maybe = explode('.', $host)[0];
            if ($maybe && $maybe !== 'www' && $maybe !== 'localhost') {
                if ($hit = $this->getTenantByKey($maybe)) return $hit;
            }
        }

        // ?t=
        if ($t = $req->query('t')) {
            if ($hit = $this->getTenantByKey($t)) return $hit;
        }

        // X-Tenant header
        if ($hdr = $req->header('X-Tenant')) {
            if ($hit = $this->getTenantByKey($hdr)) return $hit;
        }

        return (object)['id' => 1, 'slug' => 'dev'];
    }

    protected function findTenantByToken(string $token): ?object
    {
        $shared = $this->sharedConn();
        if (!Schema::connection($shared)->hasTable('qr_codes_s')) return null;

        $row = DB::connection($shared)->table('qr_codes_s')
            ->where('token', $token)
            ->first(['tenant_id']);

        if (!$row || !$row->tenant_id) return null;

        return $this->getTenantById((int) $row->tenant_id);
    }

    protected function resolveTenantForVerify(Request $req, string $token): object
    {
        // Prefer simple deterministic source: token → tenant
        if ($byToken = $this->findTenantByToken($token)) return $byToken;

        // Already bound via middleware/subdomain?
        if (app()->bound('tenant') && app('tenant')) return app('tenant');

        // Public resolvers
        if ($t = $this->resolvePublicTenant($req)) return $t;

        // Fallback
        return (object)['id' => (int)($req->header('X-Tenant') ?: 1)];
    }

    /* =========================
     * Verify endpoints
     * ========================= */

    public function verify(Request $req, string $token)
    {
        // 1) Resolve tenant from token (simple and correct)
        $tenantObj = $this->resolveTenantForVerify($req, $token);
        $tenantId  = (int)($tenantObj->id ?? 0);

        // 2) Build payload for this tenant
        $payload = $this->buildVerifyPayload($tenantId, $token);

        // 3) If not found, retry by token (paranoia; normally unnecessary)
        if (empty($payload['found'])) {
            if ($byToken = $this->findTenantByToken($token)) {
                $tenantObj = $byToken;
                $tenantId  = (int)$tenantObj->id;
                $payload   = $this->buildVerifyPayload($tenantId, $token);
            }
        }

        // 4) Mask token for public page
        $payload['token_masked'] = $this->mask($token);

        // 5) Micro check (optional ?hc= or header)
        $storedHC   = strtoupper((string)($payload['human_code'] ?? ''));
        $providedHC = $this->normalizeMicro($req->query('hc', $req->query('m', $req->header('X-Micro-Code'))));
        $matched    = ($providedHC !== '' && $storedHC !== '' && hash_equals($storedHC, $providedHC));

        $payload['micro'] = [
            'checked'         => $providedHC !== '',
            'matched'         => $matched,
            'expected_masked' => $this->mask($storedHC),
            'provided_masked' => $this->mask($providedHC),
        ];

        return view('verify', [
            'data'   => $this->withDefaults($payload),
            'tenant' => $tenantObj,
        ]);
    }

    // Optional multi-tenant path: /tenant/{slug}/v/{token}
    public function verifyWithTenant(Request $req, $tenant, string $token)
    {
        $tenantObj = $this->resolvePublicTenant($req, (string)$tenant) ?? (object)['id'=>1];
        $payload   = $this->buildVerifyPayload((int)$tenantObj->id, $token);

        $payload['token_masked'] = $this->mask($token);
        $storedHC   = strtoupper((string)($payload['human_code'] ?? ''));
        $providedHC = $this->normalizeMicro($req->query('hc', $req->query('m', $req->header('X-Micro-Code'))));
        $matched    = ($providedHC !== '' && $storedHC !== '' && hash_equals($storedHC, $providedHC));

        $payload['micro'] = [
            'checked'         => $providedHC !== '',
            'matched'         => $matched,
            'expected_masked' => $this->mask($storedHC),
            'provided_masked' => $this->mask($providedHC),
        ];

        return view('verify', [
            'data'   => $this->withDefaults($payload),
            'tenant' => $tenantObj,
        ]);
    }

    /* =========================
     * Payload builder
     * ========================= */

    protected function buildVerifyPayload(int $tenantId, string $token): array
    {
        $c   = $this->sharedConn();
        $tp  = Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';
        $qrc = 'qr_codes_s';

        if (!Schema::connection($c)->hasTable($qrc)) {
            return ['found' => false, 'reason' => 'QR table missing'];
        }

        $q = DB::connection($c)->table("$qrc as q")
            ->where('q.tenant_id', $tenantId)
            ->where('q.token', $token);

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

            // ✅ include HC + text-safe micro
            Schema::connection($c)->hasColumn($qrc,'human_code') ? 'q.human_code' : DB::raw('NULL as human_code'),
            Schema::connection($c)->hasColumn($qrc,'micro_chk')  ? DB::raw('UPPER(HEX(q.micro_chk)) as micro_hex') : DB::raw('NULL as micro_hex'),

            'p.id as __pid','p.sku','p.name',
            Schema::connection($c)->hasColumn($tp,'type')   ? 'p.type' : DB::raw("'standard' as type"),
            Schema::connection($c)->hasColumn($tp,'status') ? 'p.status as p_status' : DB::raw('NULL as p_status'),
            'd.id as __did','d.device_uid',
            Schema::connection($c)->hasColumn('devices_s','attrs_json') ? 'd.attrs_json' : DB::raw('NULL as attrs_json'),
            Schema::connection($c)->hasColumn('devices_s','status')     ? 'd.status as d_status' : DB::raw('NULL as d_status'),
            Schema::connection($c)->hasColumn('devices_s','created_at') ? 'd.created_at' : DB::raw('NULL as created_at'),
            Schema::connection($c)->hasColumn('devices_s','product_id') ? 'd.product_id as d_pid' : DB::raw('NULL as d_pid'),
        ];

        $qr = $q->first($sel);
        if (!$qr) return ['found' => false, 'reason' => 'Token not found'];

        // Ensure a proper 12-char HC (prefer stored; else derive from micro_hex)
        $humanCode = $qr->human_code;
        if (!$humanCode && $qr->micro_hex) {
            $hex = strtoupper(preg_replace('/[^0-9A-F]/', '', $qr->micro_hex));
            if (strlen($hex) >= 16) {
                $bytes = hex2bin(substr($hex, 0, 16)); // first 8 bytes
                if ($bytes !== false) {
                    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
                    $bits = '';
                    foreach (str_split($bytes) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
                    $out = '';
                    for ($i = 0; $i + 5 <= strlen($bits) && strlen($out) < 12; $i += 5) {
                        $out .= $alphabet[bindec(substr($bits, $i, 5))];
                    }
                    $humanCode = $out ?: null; // 8 bytes -> 12 chars
                }
            }
        }

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

        // Optional: components + BOM coverage (kept as in your original)
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

                // current: group by child product id (via dch.product_id since assembly table lacks child_product_id)
                $curExpr = $asmQtyCol ? "SUM($asmQtyCol)" : "COUNT(*)";
                $curQ = DB::connection($c)->table('device_assembly_links_s as a')
                    ->leftJoin('devices_s as dch', 'dch.id', '=', $childCol ? "a.$childCol" : 'a.parent_device_id')
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
            'found'       => true,
            'status'      => $qr->status,
            'token'       => $qr->token,
            'channel'     => $qr->channel,
            'batch'       => $qr->batch,
            'print_run'   => $qr->print_run_id ?? null,

            // expose HC + micro_hex for the verify page; HC is the 12-char code (stored or derived)
            'human_code'  => $humanCode,
            'micro_hex'   => $qr->micro_hex,

            'product'     => $product ? [
                'sku'    => $product['sku'],
                'name'   => $product['name'],
                'type'   => $product['type'],
                'status' => $product['status'],
            ] : null,
            'device'      => $device ? [
                'device_uid' => $device['device_uid'],
                'status'     => $device['status'],
                'attrs'      => $device['attrs'],
                'bound_at'   => $device['bound_at'],
            ] : null,
            'components'   => $components,
            'bom_coverage' => $bomCoverage,
        ];
    }

    /* =========================
     * Minor helpers used above
     * ========================= */

    protected function asmChildDeviceColumn(string $conn): ?string
    {
        if (!Schema::connection($conn)->hasTable('device_assembly_links_s')) return null;
        foreach (['component_device_id','child_device_id'] as $c) {
            if (Schema::connection($conn)->hasColumn('device_assembly_links_s', $c)) return $c;
        }
        return null;
    }

    protected function asmQtyColumn(string $conn): ?string
    {
        if (!Schema::connection($conn)->hasTable('device_assembly_links_s')) return null;
        foreach (['component_qty_used','qty','quantity','units','count'] as $c) {
            if (Schema::connection($conn)->hasColumn('device_assembly_links_s', $c)) return $c;
        }
        return null;
    }

    protected function bomQtyColumn(string $conn): ?string
    {
        if (!Schema::connection($conn)->hasTable('product_components_s')) return null;
        foreach (['quantity','component_qty','qty','required_qty','units','count'] as $c) {
            if (Schema::connection($conn)->hasColumn('product_components_s', $c)) return $c;
        }
        return null;
    }

    protected function withDefaults(array $payload): array
    {
        // Keep as a passthrough or add default keys if you like.
        return $payload;
    }
}
