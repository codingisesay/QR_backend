<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use OpenApi\Annotations as OA;

class SystemController extends Controller
{
    /**
     * @OA\Get(
     *   path="/ping",
     *   tags={"Testing System Checkup"},
     *   summary="Health check",
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       @OA\Property(property="ok", type="boolean", example=true)
     *     )
     *   )
     * )
     */
    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
