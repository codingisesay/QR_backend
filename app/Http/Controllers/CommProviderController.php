<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Core\CommProvider;

class CommProviderController extends Controller
{
    /**
     * @OA\Get(
     *   path="/t/{tenant}/comm/providers",
     *   tags={"Comm Providers"},
     *   security={{"sanctum":{}}},
     *   summary="List providers for this tenant",
     *   @OA\Response(response=200, description="OK")
     * )
     */
    public function index()
    {
        return response()->json(
            CommProvider::where('tenant_id', app('tenant.id'))->orderBy('id')->get()
        );
    }

    /**
     * @OA\Post(
     *   path="/t/{tenant}/comm/providers",
     *   tags={"Comm Providers"},
     *   security={{"sanctum":{}}},
     *   summary="Create provider (email|sms|push)",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"channel","provider"},
     *       @OA\Property(property="channel", type="string", enum={"email","sms","push"}),
     *       @OA\Property(property="provider", type="string", example="ses"),
     *       @OA\Property(property="name", type="string"),
     *       @OA\Property(property="credentials_json", type="object"),
     *       @OA\Property(property="from_email", type="string"),
     *       @OA\Property(property="from_name", type="string"),
     *       @OA\Property(property="sender_id", type="string"),
     *       @OA\Property(property="status", type="string", example="active")
     *     )
     *   ),
     *   @OA\Response(response=201, description="Created")
     * )
     */
    public function store(Request $req)
    {
        $data = $req->validate([
            'channel' => 'required|in:email,sms,push',
            'provider'=> 'required|string|max:40',
            'name'    => 'nullable|string|max:100',
            'credentials_json' => 'nullable|array',
            'from_email' => 'nullable|email',
            'from_name'  => 'nullable|string|max:100',
            'sender_id'  => 'nullable|string|max:40',
            'status'     => 'nullable|string|max:20',
        ]);

        $row = CommProvider::create(array_merge($data, [
            'tenant_id' => app('tenant.id'),
        ]));

        return response()->json($row, 201);
    }

    /**
     * @OA\Put(
     *   path="/t/{tenant}/comm/providers/{id}",
     *   tags={"Comm Providers"},
     *   security={{"sanctum":{}}},
     *   summary="Update provider",
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\RequestBody(@OA\JsonContent(
     *     @OA\Property(property="credentials_json", type="object"),
     *     @OA\Property(property="from_email", type="string"),
     *     @OA\Property(property="from_name", type="string"),
     *     @OA\Property(property="sender_id", type="string"),
     *     @OA\Property(property="status", type="string")
     *   )),
     *   @OA\Response(response=200, description="OK")
     * )
     */
    public function update($id, Request $req)
    {
        $row = CommProvider::where('tenant_id', app('tenant.id'))->findOrFail($id);
        $row->fill($req->only(['credentials_json','from_email','from_name','sender_id','status']))->save();
        return response()->json($row);
    }
}
