<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Core\Plan;

class PlanController extends Controller
{
    /**
     * @OA\Get(
     *   path="/admin/plans",
     *   tags={"Plans"},
     *   security={{"sanctum":{}}},
     *   summary="List plans",
     *   @OA\Response(response=200, description="OK")
     * )
     */
      // Token-only (no tenant), used during onboarding
    public function index()
    {
        return Plan::query()
            ->active()
            ->select([
                'id','name','period',
                'price','price_cents',
                'included_qr_per_month','overage_price_cents'
            ])
            ->orderByRaw('COALESCE(price_cents, price*100) asc')
            ->get();
    }

    /**
     * @OA\Post(
     *   path="/admin/plans",
     *   tags={"Plans"},
     *   security={{"sanctum":{}}},
     *   summary="Create plan",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","price","period"},
     *       @OA\Property(property="name", type="string"),
     *       @OA\Property(property="price", type="number", format="float"),
     *       @OA\Property(property="period", type="string", enum={"monthly","yearly"}),
     *       @OA\Property(property="limits_json", type="object")
     *     )
     *   ),
     *   @OA\Response(response=201, description="Created")
     * )
     */
    public function store(Request $req)
    {
        $data = $req->validate([
            'name' => 'required|string|max:80',
            'price' => 'required|numeric|min:0',
            'period' => 'required|in:monthly,yearly',
            'limits_json' => 'nullable|array',
        ]);

        $plan = Plan::create($data);
        return response()->json($plan, 201);
    }

    /**
     * @OA\Put(
     *   path="/admin/plans/{id}",
     *   tags={"Plans"},
     *   security={{"sanctum":{}}},
     *   summary="Update plan",
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\RequestBody(
     *     @OA\JsonContent(
     *       @OA\Property(property="name", type="string"),
     *       @OA\Property(property="price", type="number", format="float"),
     *       @OA\Property(property="period", type="string", enum={"monthly","yearly"}),
     *       @OA\Property(property="limits_json", type="object")
     *     )
     *   ),
     *   @OA\Response(response=200, description="OK")
     * )
     */
    public function update($id, Request $req)
    {
        $plan = Plan::findOrFail($id);
        $plan->fill($req->only(['name','price','period','limits_json']))->save();
        return response()->json($plan);
    }
}
