<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeviceAssemblyController extends Controller
{
    /** Pick the multitenant (shared) connection */
    protected function shared(): string
    {
        return config('database.connections.domain_shared') ? 'domain_shared' : config('database.default');
    }

    /** Pick the core connection (where tenants table usually lives) */
    protected function core(): string
    {
        return config('database.connections.core') ? 'core' : config('database.default');
    }

    /** Resolve tenant id from bound tenant, header, or default=1 */
    protected function tenantId(Request $req): int
    {
        if (app()->bound('tenant') && app('tenant')?->id) return (int) app('tenant')->id;
        if ($h = $req->header('X-Tenant')) return ctype_digit($h) ? (int)$h : 1;
        if ($q = $req->query('t')) return ctype_digit($q) ? (int)$q : 1;
        return 1;
    }

    /** Detect columns (schema-safe helpers) */
    protected function detectChildDeviceCol(string $conn): ?string
    {
        $tbl = 'device_assembly_links_s';
        if (!Schema::connection($conn)->hasTable($tbl)) return null;
        foreach (['component_device_id','child_device_id'] as $c) {
            if (Schema::connection($conn)->hasColumn($tbl, $c)) return $c;
        }
        return null;
    }
    protected function detectAsmQtyCol(string $conn): ?string
    {
        $tbl = 'device_assembly_links_s';
        if (!Schema::connection($conn)->hasTable($tbl)) return null;
        foreach (['component_qty_used','qty','quantity','units','count'] as $c) {
            if (Schema::connection($conn)->hasColumn($tbl, $c)) return $c;
        }
        return null;
    }
    protected function detectBomQtyCol(string $conn): ?string
    {
        $tbl = 'product_components_s';
        if (!Schema::connection($conn)->hasTable($tbl)) return null;
        foreach (['quantity','component_qty','qty','required_qty','units','count'] as $c) {
            if (Schema::connection($conn)->hasColumn($tbl, $c)) return $c;
        }
        return null;
    }

    /**
     * GET /devices/{deviceUid}/assembly[?with_qr=1]
     *
     * Returns:
     * {
     *   parent: { device_uid, product_id, sku, name },
     *   children: [{ sku, name, device_uid, qty, qr_token?, qr_channel? }],
     *   coverage: { required:[{child_product_id, sku, name, qty}], current:[{child_product_id, sku, name, qty}], ok }
     * }
     */
    public function getAssembly(Request $req, string $deviceUid)
    {
        $tenantId = $this->tenantId($req);
        $c   = $this->shared();
        $tp  = Schema::connection($c)->hasTable('products_s') ? 'products_s' : 'products';
        $withQr = (bool) $req->boolean('with_qr', false);

        // Ensure required tables exist
        foreach (['devices_s', $tp] as $t) {
            if (!Schema::connection($c)->hasTable($t)) {
                return response()->json(['message' => "Missing table: $t"], 422);
            }
        }

        // 1) Load parent device and its product
        $devCols = ['id','tenant_id','device_uid'];
        if (Schema::connection($c)->hasColumn('devices_s','product_id')) $devCols[] = 'product_id';

        $parent = DB::connection($c)->table('devices_s')
            ->where('tenant_id', $tenantId)
            ->where('device_uid', $deviceUid)
            ->first($devCols);

        if (!$parent) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $parentProduct = null;
        if (!empty($parent->product_id)) {
            $parentProduct = DB::connection($c)->table($tp)->where('id', $parent->product_id)->first(['id','sku','name']);
        }

        // 2) Pull children
        if (!Schema::connection($c)->hasTable('device_assembly_links_s')) {
            // No assembly table — return parent only
            return response()->json([
                'parent'   => [
                    'device_uid'  => $parent->device_uid,
                    'product_id'  => $parentProduct->id  ?? null,
                    'sku'         => $parentProduct->sku ?? null,
                    'name'        => $parentProduct->name?? null,
                ],
                'children' => [],
                'coverage' => null,
            ]);
        }

        $childDevCol = $this->detectChildDeviceCol($c) ?: 'component_device_id'; // default to your schema name
        $asmQtyCol   = $this->detectAsmQtyCol($c);
        $devHasPid   = Schema::connection($c)->hasColumn('devices_s','product_id');
        $asmHasChildPid = Schema::connection($c)->hasColumn('device_assembly_links_s','child_product_id');

        $childrenQ = DB::connection($c)->table('device_assembly_links_s as a')
            ->leftJoin('devices_s as dch', 'dch.id', '=', "a.$childDevCol")
            ->where('a.tenant_id', $tenantId)
            ->where('a.parent_device_id', $parent->id);

        // Attach child product
        if ($asmHasChildPid) {
            $childrenQ->leftJoin("$tp as pch", 'pch.id', '=', 'a.child_product_id');
            $skuExpr  = 'pch.sku';  $nameExpr = 'pch.name'; $pidExpr = 'pch.id';
        } elseif ($devHasPid) {
            $childrenQ->leftJoin("$tp as pch", 'pch.id', '=', 'dch.product_id');
            $skuExpr  = 'pch.sku';  $nameExpr = 'pch.name'; $pidExpr = 'pch.id';
        } else {
            $skuExpr  = "NULL";     $nameExpr = "NULL";     $pidExpr = "NULL";
        }

        // Attach QR token/channel (optional)
        if ($withQr) {
            $childrenQ->leftJoin('device_qr_links_s as l', 'l.device_id', '=', "dch.id")
                      ->leftJoin('qr_codes_s as q', 'q.id', '=', 'l.qr_code_id');
            $chanExpr = Schema::connection($c)->hasColumn('qr_codes_s','channel_code')
                ? 'q.channel_code'
                : (Schema::connection($c)->hasColumn('qr_codes_s','channel') ? 'q.channel' : 'NULL');
        }

        $select = [
            DB::raw("$pidExpr as child_product_id"),
            DB::raw("$skuExpr as sku"),
            DB::raw("$nameExpr as name"),
            'dch.device_uid',
            $asmQtyCol ? DB::raw("a.$asmQtyCol as qty") : DB::raw('1 as qty'),
        ];
        if ($withQr) {
            $select[] = DB::raw('q.token as qr_token');
            $select[] = DB::raw("$chanExpr as qr_channel");
        }

        $links = $childrenQ->get($select)->map(function ($r) {
            return [
                'child_product_id' => $r->child_product_id,
                'sku'              => $r->sku,
                'name'             => $r->name,
                'device_uid'       => $r->device_uid,
                'qty'              => (float) $r->qty,
                'qr_token'         => $r->qr_token ?? null,
                'qr_channel'       => $r->qr_channel ?? null,
            ];
        })->all();

        // 3) BOM coverage (required vs current)
        $coverage = null;
        $qtyCol = $this->detectBomQtyCol($c); // "quantity" in your migration
        if ($qtyCol && $parentProduct?->id) {
            // required
            $reqRows = DB::connection($c)->table('product_components_s as pc')
                ->leftJoin("$tp as cp", 'cp.id', '=', 'pc.child_product_id')
                ->where('pc.tenant_id', $tenantId)
                ->where('pc.parent_product_id', $parentProduct->id)
                ->groupBy('pc.child_product_id', 'cp.sku', 'cp.name')
                ->get([
                    'pc.child_product_id',
                    'cp.sku',
                    'cp.name',
                    DB::raw("SUM(pc.$qtyCol) as qty")
                ]);

            // current (what's assembled to this device)
            // if assembly has child_product_id use it; else derive via dch.product_id
            $curQ = DB::connection($c)->table('device_assembly_links_s as a')
                ->leftJoin('devices_s as dch', 'dch.id', '=', "a.$childDevCol")
                ->where('a.tenant_id', $tenantId)
                ->where('a.parent_device_id', $parent->id);

            $curSelectPid = $asmHasChildPid ? 'a.child_product_id' : ($devHasPid ? 'dch.product_id' : 'NULL');
            $curQ->leftJoin("$tp as cp", 'cp.id', '=', DB::raw($curSelectPid)); // safe if NULL
            $curExpr = $asmQtyCol ? "SUM(a.$asmQtyCol)" : "COUNT(*)";

            $curRows = $curQ->groupBy(DB::raw($curSelectPid), 'cp.sku', 'cp.name')->get([
                DB::raw("$curSelectPid as child_product_id"),
                'cp.sku',
                'cp.name',
                DB::raw("$curExpr as qty"),
            ]);

            $reqMap = [];
            foreach ($reqRows as $r) {
                if (!$r->child_product_id) continue;
                $reqMap[(int)$r->child_product_id] = ['sku'=>$r->sku, 'name'=>$r->name, 'qty'=>(float)$r->qty];
            }

            $curMap = [];
            foreach ($curRows as $r) {
                if (!$r->child_product_id) continue;
                $curMap[(int)$r->child_product_id] = ['sku'=>$r->sku, 'name'=>$r->name, 'qty'=>(float)$r->qty];
            }

            $ok = true;
            foreach ($reqMap as $pid => $req) {
                $have = $curMap[$pid]['qty'] ?? 0;
                if (abs($have - $req['qty']) > 1e-9) { $ok = false; break; }
            }

            $coverage = [
                'required' => array_map(fn($pid, $v) => ['child_product_id'=>$pid,'sku'=>$v['sku'],'name'=>$v['name'],'qty'=>$v['qty']], array_keys($reqMap), $reqMap),
                'current'  => array_map(fn($pid, $v) => ['child_product_id'=>$pid,'sku'=>$v['sku'],'name'=>$v['name'],'qty'=>$v['qty']], array_keys($curMap), $curMap),
                'ok'       => $ok,
            ];
        }

        return response()->json([
            'parent'   => [
                'device_uid'  => $parent->device_uid,
                'product_id'  => $parentProduct->id  ?? null,
                'sku'         => $parentProduct->sku ?? null,
                'name'        => $parentProduct->name?? null,
            ],
            'children' => $links,
            'coverage' => $coverage,
        ]);
    }
}
