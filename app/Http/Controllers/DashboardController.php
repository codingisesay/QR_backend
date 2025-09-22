<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * @OA\Get(
     *   path="/t/{tenant}/dashboard",
     *   tags={"Dashboard"},
     *   security={{"sanctum":{}}},
     *   summary="Basic KPIs for the tenant (counts)",
     *   @OA\Response(response=200, description="OK")
     * )
     */
    public function summary()
    {
        $mode = app('tenant.mode');

        if ($mode === 'shared') {
            // Option A (shared)
            $p = \App\Models\DomainShared\Product::count();
            $q = \App\Models\DomainShared\QrCode::count();
            $s7 = \App\Models\DomainShared\ScanEvent::where('created_at','>=',now()->subDays(7))->count();
        } else {
            // Option B (schema/database)
            $p = \App\Models\Domain\Product::count();
            $q = \App\Models\Domain\QrCode::count();
            $s7 = \App\Models\Domain\ScanEvent::where('created_at','>=',now()->subDays(7))->count();
        }

        return response()->json([
            'tenant_id' => app('tenant.id'),
            'mode' => $mode,
            'products' => $p,
            'qrcodes' => $q,
            'scans_last_7_days' => $s7,
        ]);
    }
}
