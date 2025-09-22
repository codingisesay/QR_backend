<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /* ---------------- Tenant & connection helpers ---------------- */

    /** Current tenant object bound by ResolveTenant. */
    protected function tenant(): ?object
    {
        if (app()->bound('tenant')) return app('tenant');
        if (app()->bound('tenant.id')) {
            return (object)[
                'id'   => (int) app('tenant.id'),
                'slug' => app()->bound('tenant.slug') ? app('tenant.slug') : null,
            ];
        }
        return null;
    }

    /** Connection for domain-scoped data (usually 'domain_shared' in your setup). */
    protected function conn(): string
    {
        return app()->bound('tenant.conn')
            ? (string) app('tenant.conn')
            : (config('database.connections.domain_shared') ? 'domain_shared' : config('database.default', 'mysql'));
    }

    /**
     * Connection for CORE data (plans / subscriptions live here, no `_s` tables).
     * Ensure you have one of these in config/database.php:
     *  - 'core' (preferred), or
     *  - 'saas_core'
     */
    // protected function coreConn(): string
    // {
    //     if (config('database.connections.core')) return 'core';
    //     if (config('database.connections.saas_core')) return 'saas_core';
    //     // Fallback: default (only if you really store core tables on default)
    //     return config('database.default', 'mysql');
    // }

    protected function coreConn(): string
{
    // Your core is the default 'mysql' connection.
    // Prefer an explicit 'core' if you later add it, else fall back to 'mysql'.
    if (config('database.connections.core')) return 'core';
    if (config('database.connections.saas_core')) return 'saas_core';
    return 'mysql';
}

    /** Resolve domain table name, prefer *_s when present on domain connection. */
    protected function tbl(string $base): string
    {
        $c = $this->conn();
        $shared = $base . '_s';
        return Schema::connection($c)->hasTable($shared) ? $shared : $base;
    }

    /* ---------------- Plan product-limit helpers (CORE DB) ---------------- */

    /** Read product cap from active plan (limits_json.products) on CORE connection. */
    protected function productLimitForTenant(object $tenant): ?int
    {
        $core = $this->coreConn();
        // core tables DO NOT have `_s` suffix
        $tsub  = 'subscriptions';
        $tplan = 'plans';

        if (!Schema::connection($core)->hasTable($tsub) || !Schema::connection($core)->hasTable($tplan)) {
            return null; // don't block if core tables aren't reachable
        }

        // latest subscription (active/trialing OR fallback to latest is_active=1 if no 'status' col)
        $subQ = DB::connection($core)->table($tsub)->where('tenant_id', $tenant->id);
        if (Schema::connection($core)->hasColumn($tsub, 'status')) {
            $subQ->whereIn('status', ['active','trialing']);
        } elseif (Schema::connection($core)->hasColumn($tsub, 'is_active')) {
            $subQ->where('is_active', 1);
        }
        $sub = $subQ->orderByDesc('id')->first();
        if (!$sub) return null;

        $plan = DB::connection($core)->table($tplan)->where('id', $sub->plan_id)->first();
        if (!$plan || !property_exists($plan, 'limits_json') || !$plan->limits_json) return null;

        $limits = json_decode($plan->limits_json, true);
        if (!is_array($limits)) return null;

        $val = $limits['products'] ?? null;
        return (is_numeric($val) && (int)$val > 0) ? (int)$val : null;
    }


    /** Count ACTIVE products on domain connection. */
    protected function activeProductCount(int $tenantId, string $conn, string $tp, bool $hasStatus): int
    {
        $q = DB::connection($conn)->table($tp)->where('tenant_id', $tenantId);
        if ($hasStatus) $q->where('status', 'active');
        return (int) $q->count();

        // If you want ONLY "root" products to count, switch to the alternative in previous messages.

        // If you want ONLY catalog roots to count against the cap, switch to:
        // $tpc = $this->tbl('product_components');
        // $incoming = DB::connection($conn)->table("$tpc as ic")
        //     ->selectRaw('ic.child_product_id, COUNT(*) as cnt')
        //     ->where('ic.tenant_id', $tenantId)
        //     ->groupBy('ic.child_product_id');
        // $q = DB::connection($conn)->table("$tp as p")
        //     ->leftJoinSub($incoming, 'ci', fn($j)=>$j->on('ci.child_product_id','=','p.id'))
        //     ->where('p.tenant_id',$tenantId)
        //     ->whereRaw('COALESCE(ci.cnt,0)=0');
        // if ($hasStatus) $q->where('p.status','active');
        // return (int) $q->count();
    }

    /* ---------------- CRUD ---------------- */

    // GET /api/products   and   /api/t/{tenant}/products
    public function index(Request $req, ...$rest)
    {
        Gate::authorize('perm', 'product.read');

        $tenant = $this->tenant();
        if (!$tenant?->id) return response()->json(['message' => 'Tenant missing'], 400);

        $conn = $this->conn();
        $tp   = $this->tbl('products');
        $tpc  = $this->tbl('product_components');

        $hasType   = Schema::connection($conn)->hasColumn($tp, 'type');
        $hasStatus = Schema::connection($conn)->hasColumn($tp, 'status');

        // Outgoing edges per parent
        $compSub = DB::connection($conn)->table("$tpc as c")
            ->selectRaw('c.parent_product_id, COUNT(*) as cnt')
            ->where('c.tenant_id', $tenant->id)
            ->groupBy('c.parent_product_id');

        // Incoming edges per child (to detect is_root)
        $incomingSub = DB::connection($conn)->table("$tpc as ic")
            ->selectRaw('ic.child_product_id, COUNT(*) as cnt')
            ->where('ic.tenant_id', $tenant->id)
            ->groupBy('ic.child_product_id');

        $q = DB::connection($conn)->table("$tp as p")
            ->leftJoinSub($compSub, 'cc', fn($j) => $j->on('cc.parent_product_id', '=', 'p.id'))
            ->leftJoinSub($incomingSub, 'ci', fn($j) => $j->on('ci.child_product_id', '=', 'p.id'))
            ->where('p.tenant_id', $tenant->id)
            ->when($req->filled('q'), function ($qq) use ($req) {
                $k = '%' . $req->string('q') . '%';
                $qq->where(fn($w) => $w->where('p.sku','like',$k)->orWhere('p.name','like',$k));
            });

        if ($hasStatus && $req->filled('status')) $q->where('p.status', $req->string('status'));
        if ($req->filled('type')) {
            $type = $req->string('type');
            if ($hasType) $q->where('p.type', $type);
            else {
                if ($type === 'composite') $q->whereRaw('COALESCE(cc.cnt,0) > 0');
                if ($type === 'standard')  $q->whereRaw('COALESCE(cc.cnt,0) = 0');
            }
        }
        if ($req->boolean('root_only')) $q->whereRaw('COALESCE(ci.cnt,0) = 0');

        $select = [
            'p.id','p.tenant_id','p.sku','p.name','p.created_at',
            DB::raw('COALESCE(cc.cnt,0) as components_count'),
            DB::raw('COALESCE(ci.cnt,0) as used_as_child_count'),
            DB::raw('CASE WHEN COALESCE(ci.cnt,0)=0 THEN 1 ELSE 0 END as is_root'),
        ];
        if ($hasType)   $select[] = 'p.type';   else $select[] = DB::raw("CASE WHEN COALESCE(cc.cnt,0)>0 THEN 'composite' ELSE 'standard' END AS type");
        if ($hasStatus) $select[] = 'p.status'; else $select[] = DB::raw("'active' AS status");

        $rows = $q->orderByDesc('p.id')->get($select);
        return response()->json($rows);
    }

    // POST /api/products   and   /api/t/{tenant}/products   (idempotent upsert by SKU)
    public function store(Request $req, ...$rest)
    {
        Gate::authorize('perm', 'product.write');

        $tenant = $this->tenant();
        if (!$tenant?->id) return response()->json(['message' => 'Tenant missing'], 400);

        $conn = $this->conn();
        $tp   = $this->tbl('products');
        $tpc  = $this->tbl('product_components');

        $hasType   = Schema::connection($conn)->hasColumn($tp, 'type');
        $hasStatus = Schema::connection($conn)->hasColumn($tp, 'status');

        $data = $req->validate([
            'sku'   => ['required','string','max:64'],
            'name'  => ['required','string','max:255'],
            'type'  => ['sometimes', Rule::in(['standard','composite'])],
            'status'=> ['sometimes', Rule::in(['active','archived'])],

            'components'            => ['nullable','array'],
            'components.*.sku'      => ['required_with:components','string','max:64'],
            'components.*.quantity' => ['nullable','numeric','gt:0'],

            'auto_create_components'=> ['sometimes','boolean'], // default true
        ]);

        $type        = $data['type']  ?? (!empty($data['components']) ? 'composite' : 'standard');
        $autoCreate  = array_key_exists('auto_create_components',$data) ? (bool)$data['auto_create_components'] : true;
        $desiredStat = $hasStatus ? ($data['status'] ?? 'active') : 'active';

        return DB::connection($conn)->transaction(function () use ($tenant,$conn,$tp,$tpc,$data,$type,$autoCreate,$hasType,$hasStatus,$desiredStat) {
            $now    = now();
            $limit  = $this->productLimitForTenant($tenant); // from CORE DB
            $exists = DB::connection($conn)->table($tp)
                ->where('tenant_id',$tenant->id)->where('sku',$data['sku'])->first();

            // Count BEFORE changes (domain connection)
            $activeCount = $this->activeProductCount($tenant->id, $conn, $tp, $hasStatus);

            $creatingNewActive = !$exists && $desiredStat === 'active';
            $reactivating      = false;
            if ($exists && $hasStatus) {
                $currentStatus = $exists->status ?? 'active';
                $reactivating  = ($currentStatus !== 'active' && $desiredStat === 'active');
            }

            if (is_int($limit) && ($activeCount + ($creatingNewActive ? 1 : 0) + ($reactivating ? 1 : 0)) > $limit) {
                return response()->json([
                    'message'       => 'Product limit reached for this plan.',
                    'product_limit' => $limit,
                    'active_count'  => $activeCount,
                ], 422);
            }

            // Upsert parent
            if ($exists) {
                $pid   = (int) $exists->id;
                $patch = [];
                if (array_key_exists('name',$data)) $patch['name'] = $data['name'];
                if ($hasType && array_key_exists('type',$data))     $patch['type'] = $type;
                if ($hasStatus && array_key_exists('status',$data)) $patch['status'] = $desiredStat;
                if ($patch) {
                    $patch['updated_at'] = $now;
                    DB::connection($conn)->table($tp)
                        ->where('tenant_id',$tenant->id)->where('id',$pid)->update($patch);
                }
                $created = false;
            } else {
                $row = [
                    'tenant_id'  => $tenant->id,
                    'sku'        => $data['sku'],
                    'name'       => $data['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                    'meta'       => null,
                ];
                if ($hasType)   $row['type']   = $type;
                if ($hasStatus) $row['status'] = $desiredStat;
                $pid     = DB::connection($conn)->table($tp)->insertGetId($row);
                $created = true;
            }

            // If BOM provided, replace edges idempotently
            if (array_key_exists('components', $data)) {
                if (!Schema::connection($conn)->hasTable($tpc)) {
                    return response()->json(['message'=>"Edge table '$tpc' not found on connection '$conn'"], 500);
                }

                DB::connection($conn)->table($tpc)
                    ->where('tenant_id',$tenant->id)->where('parent_product_id',$pid)->delete();

                $components = $data['components'] ?? [];
                if (!empty($components)) {
                    $childSkus = collect($components)->pluck('sku')
                        ->map(fn($s)=>trim((string)$s))
                        ->filter(fn($s)=> $s !== '' && $s !== $data['sku'])
                        ->unique()->values()->all();

                    $childMap = DB::connection($conn)->table($tp)
                        ->where('tenant_id',$tenant->id)->whereIn('sku',$childSkus)->pluck('id','sku');

                    $missing = array_values(array_diff($childSkus, array_keys($childMap->toArray())));

                    if (!empty($missing) && $autoCreate) {
                        $toCreate  = count($missing);
                        $baseUsed  = $activeCount + ($creatingNewActive ? 1 : 0) + ($reactivating ? 1 : 0);
                        if (is_int($limit) && ($baseUsed + $toCreate) > $limit) {
                            $available = max(0, $limit - $baseUsed);
                            return response()->json([
                                'message'       => "Product limit would be exceeded by auto-creating components.",
                                'product_limit' => $limit,
                                'active_count'  => $activeCount,
                                'would_create'  => $toCreate,
                                'available'     => $available,
                            ], 422);
                        }

                        $rows = [];
                        foreach ($missing as $sku) {
                            $r = [
                                'tenant_id'  => $tenant->id,
                                'sku'        => $sku,
                                'name'       => $sku,
                                'created_at' => $now,
                                'updated_at' => $now,
                                'meta'       => null,
                            ];
                            if ($hasType)   $r['type']   = 'standard';
                            if ($hasStatus) $r['status'] = 'active';
                            $rows[] = $r;
                        }
                        if ($rows) {
                            DB::connection($conn)->table($tp)->insert($rows);
                            $childMap = DB::connection($conn)->table($tp)
                                ->where('tenant_id',$tenant->id)->whereIn('sku',$childSkus)->pluck('id','sku');
                            $missing = array_values(array_diff($childSkus, array_keys($childMap->toArray())));
                        }
                    }

                    if (!empty($missing)) {
                        return response()->json(['message'=>"Component SKU(s) not found: ".implode(', ', $missing)], 422);
                    }

                    $edges=[]; $order=0;
                    foreach ($components as $c) {
                        $sku = trim((string)($c['sku'] ?? ''));
                        if ($sku === '' || !isset($childMap[$sku])) continue;
                        $edges[] = [
                            'tenant_id'         => $tenant->id,
                            'parent_product_id' => $pid,
                            'child_product_id'  => (int)$childMap[$sku],
                            'quantity'          => isset($c['quantity']) ? (float)$c['quantity'] : 1.0,
                            'sort_order'        => $order++,
                            'meta'              => null,
                            'created_at'        => $now,
                            'updated_at'        => $now,
                        ];
                    }
                    if ($edges) DB::connection($conn)->table($tpc)->insert($edges);

                    if ($hasType) {
                        DB::connection($conn)->table($tp)
                            ->where('tenant_id',$tenant->id)->where('id',$pid)
                            ->update(['type'=>'composite','updated_at'=>$now]);
                    }
                } else if ($hasType) {
                    DB::connection($conn)->table($tp)
                        ->where('tenant_id',$tenant->id)->where('id',$pid)
                        ->update(['type'=>'standard','updated_at'=>$now]);
                }
            }

            $payload = [
                'id'      => $pid,
                'sku'     => $data['sku'],
                'name'    => $data['name'],
                'type'    => $hasType ? (DB::connection($conn)->table($tp)->where('id',$pid)->value('type') ?? 'standard') : 'standard',
                'status'  => $hasStatus ? (DB::connection($conn)->table($tp)->where('id',$pid)->value('status') ?? 'active') : 'active',
                'existed' => (bool)$exists,
            ];
            return response()->json($payload, $exists ? 200 : 201);
        });
    }

    // PATCH /api/products/{id}   and   /api/t/{tenant}/products/{id}
    public function update(Request $req, ...$rest)
    {
        Gate::authorize('perm', 'product.write');

        $tenant = $this->tenant();
        if (!$tenant?->id) return response()->json(['message'=>'Tenant missing'], 400);

        $id = (int) ($req->route('id') ?? $req->route('product') ?? $req->route('product_id'));
        if (!$id) return response()->json(['message'=>'Product id missing'], 400);

        $conn = $this->conn();
        $tp   = $this->tbl('products');
        $tpc  = $this->tbl('product_components');

        if (!Schema::connection($conn)->hasTable($tpc)) {
            return response()->json(['message' => "Edge table '$tpc' not found on connection '$conn'"], 500);
        }

        $hasType   = Schema::connection($conn)->hasColumn($tp, 'type');
        $hasStatus = Schema::connection($conn)->hasColumn($tp, 'status');

        $current = DB::connection($conn)->table($tp)
            ->where('tenant_id',$tenant->id)->where('id',$id)->first();
        if (!$current) return response()->json(['message'=>'Not found'], 404);

        $data = $req->validate([
            'sku'   => ['sometimes','string','max:64', function($a,$v,$f) use($conn,$tp,$tenant,$id){
                $exists = DB::connection($conn)->table($tp)
                    ->where('tenant_id',$tenant->id)->where('sku',$v)->where('id','!=',$id)->exists();
                if ($exists) $f('SKU already exists for this tenant.');
            }],
            'name'   => ['sometimes','string','max:255'],
            'type'   => ['sometimes', Rule::in(['standard','composite'])],
            'status' => ['sometimes', Rule::in(['active','archived'])],

            'components'            => ['nullable','array'],
            'components.*.sku'      => ['required_with:components','string','max:64'],
            'components.*.quantity' => ['nullable','numeric','gt:0'],

            'auto_create_components'=> ['sometimes','boolean'],
        ]);

        return DB::connection($conn)->transaction(function () use ($tenant,$conn,$tp,$tpc,$id,$current,$data,$hasType,$hasStatus) {
            $now   = now();
            $limit = $this->productLimitForTenant($tenant); // from CORE DB

            // Reactivation check
            if ($hasStatus && array_key_exists('status', $data) && $data['status'] === 'active' && ($current->status ?? 'active') !== 'active') {
                if (is_int($limit)) {
                    $activeCount = $this->activeProductCount($tenant->id, $conn, $tp, $hasStatus);
                    if ($activeCount >= $limit) {
                        return response()->json([
                            'message'       => 'Product limit reached for this plan. Cannot reactivate.',
                            'product_limit' => $limit,
                            'active_count'  => $activeCount,
                        ], 422);
                    }
                }
            }

            // Patch base fields
            $patch = [];
            if (array_key_exists('sku',$data))    $patch['sku']    = $data['sku'];
            if (array_key_exists('name',$data))   $patch['name']   = $data['name'];
            if ($hasType   && array_key_exists('type',$data))   $patch['type']   = $data['type'];
            if ($hasStatus && array_key_exists('status',$data)) $patch['status'] = $data['status'];
            if ($patch) {
                $patch['updated_at'] = $now;
                DB::connection($conn)->table($tp)
                    ->where('tenant_id',$tenant->id)->where('id',$id)->update($patch);
            }

            // Replace BOM
            if (array_key_exists('components', $data)) {
                DB::connection($conn)->table($tpc)
                    ->where('tenant_id',$tenant->id)->where('parent_product_id',$id)->delete();

                $components = $data['components'] ?? [];
                if (!empty($components)) {
                    $childSkus = collect($components)->pluck('sku')
                        ->map(fn($s)=>trim((string)$s))
                        ->filter(fn($s)=> $s !== '' && $s !== $current->sku)
                        ->unique()->values()->all();

                    $childMap = DB::connection($conn)->table($tp)
                        ->where('tenant_id',$tenant->id)->whereIn('sku',$childSkus)->pluck('id','sku');

                    $missing    = array_values(array_diff($childSkus, array_keys($childMap->toArray())));
                    $autoCreate = array_key_exists('auto_create_components',$data) ? (bool)$data['auto_create_components'] : true;

                    if (!empty($missing) && $autoCreate) {
                        $toCreate    = count($missing);
                        $activeCount = is_int($limit) ? $this->activeProductCount($tenant->id, $conn, $tp, $hasStatus) : 0;

                        if (is_int($limit) && ($activeCount + $toCreate) > $limit) {
                            $available = max(0, $limit - $activeCount);
                            return response()->json([
                                'message'       => "Product limit would be exceeded by auto-creating components.",
                                'product_limit' => $limit,
                                'active_count'  => $activeCount,
                                'would_create'  => $toCreate,
                                'available'     => $available,
                            ], 422);
                        }

                        $rows = [];
                        foreach ($missing as $sku) {
                            $r = [
                                'tenant_id'  => $tenant->id,
                                'sku'        => $sku,
                                'name'       => $sku,
                                'created_at' => $now,
                                'updated_at' => $now,
                                'meta'       => null,
                            ];
                            if ($hasType)   $r['type']   = 'standard';
                            if ($hasStatus) $r['status'] = 'active';
                            $rows[] = $r;
                        }
                        if ($rows) {
                            DB::connection($conn)->table($tp)->insert($rows);
                            $childMap = DB::connection($conn)->table($tp)
                                ->where('tenant_id',$tenant->id)->whereIn('sku',$childSkus)->pluck('id','sku');
                            $missing = array_values(array_diff($childSkus, array_keys($childMap->toArray())));
                        }
                    }

                    if (!empty($missing)) {
                        return response()->json(['message'=>"Component SKU(s) not found: ".implode(', ', $missing)], 422);
                    }

                    $edges=[]; $order=0;
                    foreach ($components as $c) {
                        $sku = trim((string)($c['sku'] ?? ''));
                        if ($sku === '' || !isset($childMap[$sku])) continue;
                        $edges[] = [
                            'tenant_id'         => $tenant->id,
                            'parent_product_id' => $id,
                            'child_product_id'  => (int)$childMap[$sku],
                            'quantity'          => isset($c['quantity']) ? (float)$c['quantity'] : 1.0,
                            'sort_order'        => $order++,
                            'meta'              => null,
                            'created_at'        => $now,
                            'updated_at'        => $now,
                        ];
                    }
                    if ($edges) DB::connection($conn)->table($tpc)->insert($edges);

                    if ($hasType) {
                        DB::connection($conn)->table($tp)
                            ->where('tenant_id',$tenant->id)->where('id',$id)
                            ->update(['type'=>'composite','updated_at'=>$now]);
                    }
                } else if ($hasType) {
                    DB::connection($conn)->table($tp)
                        ->where('tenant_id',$tenant->id)->where('id',$id)
                        ->update(['type'=>'standard','updated_at'=>$now]);
                }
            }

            return response()->json(['ok'=>true]);
        });
    }

    // DELETE /api/products/{id}   and   /api/t/{tenant}/products/{id}
    public function destroy(Request $req, ...$rest)
    {
        Gate::authorize('perm', 'product.write');

        $tenant = $this->tenant();
        if (!$tenant?->id) return response()->json(['message'=>'Tenant missing'], 400);

        $id = (int) ($req->route('id') ?? $req->route('product') ?? $req->route('product_id'));
        if (!$id) return response()->json(['message'=>'Product id missing'], 400);

        $conn = $this->conn();
        $tp   = $this->tbl('products');

        $exists = DB::connection($conn)->table($tp)
            ->where('tenant_id',$tenant->id)->where('id',$id)->exists();
        if (!$exists) return response()->json(['message'=>'Not found'], 404);

        $hasStatus = Schema::connection($conn)->hasColumn($tp, 'status');
        if ($hasStatus) {
            DB::connection($conn)->table($tp)
                ->where('tenant_id',$tenant->id)->where('id',$id)
                ->update(['status'=>'archived','updated_at'=>now()]);
        } else {
            DB::connection($conn)->table($tp)
                ->where('tenant_id',$tenant->id)->where('id',$id)->delete();
        }

        return response()->json(['ok'=>true]);
    }
}
