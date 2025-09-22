<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use App\Models\Core\{Tenant, Role, OrgMember, UserRole, Subscription};
use App\Models\User;

class TenantAdminController extends Controller
{
    /**
     * @OA\Get(
     *   path="/admin/tenants",
     *   tags={"Tenants"},
     *   security={{"sanctum":{}}},
     *   summary="List tenants",
     *   @OA\Response(response=200, description="OK")
     * )
     */
    public function list()
    {
        return response()->json(Tenant::orderByDesc('id')->paginate(25));
    }

    /**
     * @OA\Post(
     *   path="/admin/tenants",
     *   tags={"Tenants"},
     *   security={{"sanctum":{}}},
     *   summary="Register tenant (shared|schema|database)",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","mode"},
     *       @OA\Property(property="name", type="string"),
     *       @OA\Property(property="slug", type="string"),
     *       @OA\Property(property="plan_id", type="integer"),
     *       @OA\Property(property="mode", type="string", enum={"shared","schema","database"}),
     *       @OA\Property(property="owner_user_id", type="integer")
     *     )
     *   ),
     *   @OA\Response(response=201, description="Created")
     * )
     */
    public function registerTenant(Request $req)
    {
        $data = $req->validate([
            'name' => 'required|string|max:150',
            'slug' => 'nullable|string|max:64',
            'plan_id' => 'nullable|integer',
            'mode' => 'required|in:shared,schema,database',
            'owner_user_id' => 'nullable|integer'
        ]);

        $slug = $data['slug'] ?: Str::slug($data['name']);
        if (Tenant::where('slug', $slug)->exists()) $slug .= '-' . Str::random(4);

        $tenant = Tenant::create([
            'slug' => $slug, 'name' => $data['name'], 'status' => 'active',
            'plan_id' => $data['plan_id'] ?? null, 'isolation_mode' => $data['mode']
        ]);

        // Attach owner
        $user = $data['owner_user_id']
            ? User::findOrFail($data['owner_user_id'])
            : $req->user();

        $owner = Role::firstOrCreate(['tenant_id'=>$tenant->id,'key'=>'owner'], ['name'=>'Owner']);
        OrgMember::updateOrCreate(['tenant_id'=>$tenant->id,'user_id'=>$user->id], ['status'=>'active','joined_at'=>now()]);
        UserRole::updateOrCreate(['tenant_id'=>$tenant->id,'user_id'=>$user->id,'role_id'=>$owner->id], []);

        // Provision & migrate if isolated
        if (in_array($tenant->isolation_mode, ['schema','database'], true)) {
            Artisan::call('tenant:provision', ['slug'=>$tenant->slug, '--name'=>$tenant->name, '--mode'=>$tenant->isolation_mode]);
            Artisan::call('tenant:migrate', ['slug'=>$tenant->slug]);
        }

        return response()->json(['ok'=>true,'tenant'=>$tenant], 201);
    }

    /**
     * @OA\Put(
     *   path="/admin/tenants/{id}",
     *   tags={"Tenants"},
     *   security={{"sanctum":{}}},
     *   summary="Update tenant status or plan",
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\RequestBody(
     *     @OA\JsonContent(
     *       @OA\Property(property="status", type="string", enum={"active","suspended","closed"}),
     *       @OA\Property(property="plan_id", type="integer")
     *     )
     *   ),
     *   @OA\Response(response=200, description="OK")
     * )
     */
    public function updateStatus($id, Request $req)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->fill($req->only(['status','plan_id']))->save();
        return response()->json($tenant);
    }
}
