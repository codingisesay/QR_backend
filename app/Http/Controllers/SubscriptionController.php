<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Core\{Tenant, Subscription};

class SubscriptionController extends Controller
{
    /**
     * @OA\Post(
     *   path="/admin/tenants/{tenantId}/subscription",
     *   tags={"Subscriptions"},
     *   security={{"sanctum":{}}},
     *   summary="Create or renew a subscription for a tenant",
     *   @OA\Parameter(name="tenantId", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"plan_id","period_start","period_end","status"},
     *       @OA\Property(property="plan_id", type="integer"),
     *       @OA\Property(property="period_start", type="string", format="date-time"),
     *       @OA\Property(property="period_end", type="string", format="date-time"),
     *       @OA\Property(property="status", type="string", enum={"active","trial","past_due","canceled"})
     *     )
     *   ),
     *   @OA\Response(response=201, description="Created")
     * )
     */
    public function store($tenantId, Request $req)
    {
        $tenant = Tenant::findOrFail($tenantId);

        $data = $req->validate([
            'plan_id' => 'required|integer',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'status' => 'required|in:active,trial,past_due,canceled',
        ]);

        $sub = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $data['plan_id'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'status' => $data['status'],
        ]);

        return response()->json($sub, 201);
    }
}
