<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeviceAssemblyController extends Controller
{
    /* ============================================================
     | Helpers (tenant + connection + table/column detection)
     |============================================================ */

    /** Choose the shared/tenant DB connection (matches your other controllers). */
    protected function sharedConn(): string
    {
        // If you've configured 'domain_shared', use it; else fall back to default
        return config('database.connections.domain_shared') ? 'domain_shared'
                                                           : config('database.default');
    }

    /** Backwards-compat alias (prevents "undefined method shared()" crashes). */
    protected function shared(): string
    {
        return $this->sharedConn();
    }

    /** Resolve tenant id (works with ResolveTenant middleware or X-Tenant header). */
    protected function tenantId(Request $req): int
    {
        // Prefer middleware-injected tenant (app('tenant'))
        if (app()->bound('tenant') && app('tenant')?->id) {
            return (int) app('tenant')->id;
        }
        // Fallback to header or query param
        if ($h = $req->header('X-Tenant')) return ctype_digit($h) ? (int) $h : 0;
        if ($q = $req->query('t'))        return ctype_digit($q) ? (int) $q : 0;
        return 0; // force 400 if not resolved
    }

    /** Prefer products_s if present. */
    protected function productTable(string $conn): string
    {
        return Schema::connection($conn)->hasTable('products_s') ? 'products_s' : 'products';
    }

    /** Detect child column name in device_assembly_links_s (component_device_id vs child_device_id). */
    protected function detectChildDeviceCol(string $conn): string
    {
        $tbl = 'device_assembly_links_s';
        if (!Schema::connection($conn)->hasTable($tbl)) return 'component_device_id';
        foreach (['component_device_id','child_device_id'] as $c) {
            if (Schema::connection($conn)->hasColumn($tbl, $c)) return $c;
        }
        return 'component_device_id';
    }

    /** Optionally detect BOM qty column (if you later show BOM coverage). */
    protected function detectBomQtyCol(string $conn): ?string
    {
        $tbl = 'product_components_s';
        if (!Schema::connection($conn)->hasTable($tbl)) return null;
        foreach (['quantity','qty','units','count'] as $c) {
            if (Schema::connection($conn)->hasColumn($tbl, $c)) return $c;
        }
        return null;
    }

    /* ============================================================
     | Endpoints
     |============================================================ */

    /**
     * POST /api/devices/assemble
     * Body: { "parent": "BIKE-0001", "children": ["ENGINE-0001","TIREF-0001","TIRER-0001"] }
     * Idempotently links child devices under the parent device.
     */
    public function assemble(Request $req)
    {
        $tenantId = $this->tenantId($req);
        if ($tenantId <= 0) return response()->json(['message' => 'Tenant not resolved'], 400);

        $c = $this->sharedConn();

        if (!Schema::connection($c)->hasTable('devices_s') ||
            !Schema::connection($c)->hasTable('device_assembly_links_s')) {
            return response()->json(['message' => 'Device tables not present'], 500);
        }

        $data = $req->validate([
            'parent'     => ['required', 'string', 'max:191'],
            'children'   => ['required', 'array', 'min:1'],
            'children.*' => ['string', 'max:191'],
        ]);

        $parentUid = $data['parent'];
        $parentId = DB::connection($c)->table('devices_s')
            ->where('tenant_id', $tenantId)
            ->where('device_uid', $parentUid)
            ->value('id');

        if (!$parentId) {
            return response()->json(['message' => 'Parent device not found'], 404);
        }

        $childCol = $this->detectChildDeviceCol($c);
        $linked = [];
        $missing = [];

        foreach ($data['children'] as $childUid) {
            $childId = DB::connection($c)->table('devices_s')
                ->where('tenant_id', $tenantId)
                ->where('device_uid', $childUid)
                ->value('id');

            if (!$childId) { $missing[] = $childUid; continue; }

            DB::connection($c)->table('device_assembly_links_s')->updateOrInsert(
                ['tenant_id' => $tenantId, 'parent_device_id' => $parentId, $childCol => $childId],
                ['updated_at' => now()]
            );

            $linked[] = $childUid;
        }

        return response()->json([
            'parent'  => $parentUid,
            'linked'  => $linked,
            'missing' => $missing,
        ]);
    }

    /**
     * POST /api/devices/{parentUid}/assembly
     * Body: { "children": [...] }
     * Wrapper so your UI can call path-style endpoint.
     */
    public function assembleByParentUid(Request $req, string $parentUid)
    {
        // Reuse the same validation/logic by mapping to assemble()
        $req->merge(['parent' => $parentUid]);
        return $this->assemble($req);
    }

    

    public function getAssembly(Request $req, string $deviceUid)
{
    $tenantId = $this->tenantId($req);
    if ($tenantId <= 0) {
        return response()->json(['message' => 'Tenant not resolved'], 400);
    }

    $c  = $this->sharedConn();
    $tp = $this->productTable($c);
    $withQr = $req->boolean('with_qr', true);

    if (!Schema::connection($c)->hasTable('devices_s')) {
        return response()->json(['message' => 'Missing table: devices_s'], 500);
    }

    $dev = DB::connection($c)->table('devices_s')
        ->where('tenant_id', $tenantId)
        ->where('device_uid', $deviceUid)
        ->first(['id', 'product_id', 'device_uid']);

    if (!$dev) {
        return response()->json(['message' => 'Device not found'], 404);
    }

    // Detect child column (component_device_id vs child_device_id)
    $childCol = $this->detectChildDeviceCol($c);

    // Parent (if any)
    $parentUid = null;
    if (Schema::connection($c)->hasTable('device_assembly_links_s')) {
        $parentUid = DB::connection($c)->table('device_assembly_links_s as l')
            ->join('devices_s as p', 'p.id', '=', 'l.parent_device_id')
            ->where('l.tenant_id', $tenantId)
            ->where("l.$childCol", $dev->id)
            ->value('p.device_uid');
    }

    // ---- N-level descendants (BFS) ----
    $children = [];
    if (Schema::connection($c)->hasTable('device_assembly_links_s')) {
        $frontier = [$dev->id];
        $visited  = [];
        $descIds  = [];

        while ($frontier) {
            $links = DB::connection($c)->table('device_assembly_links_s')
                ->where('tenant_id', $tenantId)
                ->whereIn('parent_device_id', $frontier)
                ->pluck($childCol)
                ->all();

            $next = [];
            foreach ($links as $cid) {
                $cid = (int)$cid;
                if (isset($visited[$cid])) continue;
                $visited[$cid] = true;
                $descIds[] = $cid;
                $next[] = $cid;
            }
            $frontier = $next;
        }

        if ($descIds) {
            $rows = DB::connection($c)->table('devices_s as d')
                ->leftJoin("$tp as p", 'p.id', '=', 'd.product_id')
                ->where('d.tenant_id', $tenantId)
                ->whereIn('d.id', $descIds)
                ->get(['d.id','d.device_uid','p.sku','p.name']);

            $children = $rows->map(function ($r) use ($tenantId, $c, $withQr) {
                $row = [
                    'device_uid' => $r->device_uid,
                    'sku'        => $r->sku,
                    'name'       => $r->name,
                ];

                if ($withQr &&
                    Schema::connection($c)->hasTable('device_qr_links_s') &&
                    Schema::connection($c)->hasTable('qr_codes_s')) {

                    $qr = DB::connection($c)->table('device_qr_links_s as l')
                        ->join('qr_codes_s as q', 'q.id', '=', 'l.qr_code_id')
                        ->leftJoin('qr_channels_s as ch', 'ch.id', '=', 'q.channel_id')
                        ->join('devices_s as d', 'd.id', '=', 'l.device_id')
                        ->where('l.tenant_id', $tenantId)
                        ->where('d.device_uid', $r->device_uid)
                        ->orderByDesc('q.id')
                        ->first(['q.token', 'ch.code as channel_code']);

                    if ($qr) {
                        $row['qr_token']   = $qr->token;
                        $row['qr_channel'] = $qr->channel_code ?: null;
                    }
                }

                return $row;
            })->values()->all();
        }
    }

    return response()->json([
        'device_uid' => $dev->device_uid,
        'parent'     => $parentUid,
        // NOTE: children = all descendants (flattened) so the UI shows every part under the root
        'children'   => $children,
    ]);
}


    /**
     * DELETE /api/devices/{deviceUid}/assembly/{childUid}
     * Unlinks the child from the parent device.
     */
    public function detach(Request $req, string $deviceUid, string $childUid)
    {
        $tenantId = $this->tenantId($req);
        if ($tenantId <= 0) return response()->json(['message' => 'Tenant not resolved'], 400);

        $c = $this->sharedConn();

        if (!Schema::connection($c)->hasTable('devices_s') ||
            !Schema::connection($c)->hasTable('device_assembly_links_s')) {
            return response()->json(['message' => 'Device tables not present'], 500);
        }

        $parentId = DB::connection($c)->table('devices_s')
            ->where('tenant_id', $tenantId)
            ->where('device_uid', $deviceUid)
            ->value('id');

        $childId = DB::connection($c)->table('devices_s')
            ->where('tenant_id', $tenantId)
            ->where('device_uid', $childUid)
            ->value('id');

        if (!$parentId || !$childId) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $childCol = $this->detectChildDeviceCol($c);
        $removed = DB::connection($c)->table('device_assembly_links_s')
            ->where('tenant_id', $tenantId)
            ->where('parent_device_id', $parentId)
            ->where($childCol, $childId)
            ->delete();

        return response()->json(['removed' => (bool) $removed]);
    }
}
