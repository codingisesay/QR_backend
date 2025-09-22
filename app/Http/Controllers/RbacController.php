<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Core\{Role, Permission, RolePermission, UserRole};

class RbacController extends Controller
{
    /**
     * @OA\Get(
     *   path="/t/{tenant}/roles",
     *   tags={"RBAC"},
     *   security={{"sanctum":{}}},
     *   summary="List roles in tenant",
     *   @OA\Parameter(name="tenant", in="path", required=true, @OA\Schema(type="string")),
     *   @OA\Response(response=200, description="OK")
     * )
     */
    public function listRoles()
    {
        return response()->json(Role::where('tenant_id', app('tenant.id'))->get());
    }

    /**
     * @OA\Post(
     *   path="/t/{tenant}/roles",
     *   tags={"RBAC"},
     *   security={{"sanctum":{}}},
     *   summary="Create role in tenant",
     *   @OA\RequestBody(@OA\JsonContent(required={"key"}, @OA\Property(property="key", type="string"), @OA\Property(property="name", type="string"))),
     *   @OA\Response(response=201, description="Created")
     * )
     */
    public function createRole(Request $req)
    {
        $data = $req->validate(['key'=>'required|string|max:64','name'=>'nullable|string|max:100']);
        $role = Role::create([
            'tenant_id'=>app('tenant.id'),
            'key'=>$data['key'],
            'name'=>$data['name'] ?? ucfirst($data['key'])
        ]);
        return response()->json($role, 201);
    }

    /**
     * @OA\Post(
     *   path="/t/{tenant}/roles/{roleId}/permissions",
     *   tags={"RBAC"},
     *   security={{"sanctum":{}}},
     *   summary="Set permissions for a role (replace)",
     *   @OA\Parameter(name="roleId", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\RequestBody(
     *     @OA\JsonContent(
     *       required={"permission_keys"},
     *       @OA\Property(property="permission_keys", type="array", @OA\Items(type="string"))
     *     )
     *   ),
     *   @OA\Response(response=200, description="OK")
     * )
     */
    public function setRolePermissions($roleId, Request $req)
    {
        $role = Role::where('tenant_id', app('tenant.id'))->findOrFail($roleId);
        $data = $req->validate(['permission_keys'=>'required|array']);

        // Map keys -> ids
        $permIds = Permission::whereIn('key', $data['permission_keys'])->pluck('id')->all();

        // Replace current
        RolePermission::where('tenant_id', app('tenant.id'))->where('role_id', $role->id)->delete();
        foreach ($permIds as $pid) {
            RolePermission::create(['tenant_id'=>app('tenant.id'),'role_id'=>$role->id,'permission_id'=>$pid]);
        }
        return response()->json(['ok'=>true,'count'=>count($permIds)]);
    }

    /**
     * @OA\Post(
     *   path="/t/{tenant}/users/{userId}/roles",
     *   tags={"RBAC"},
     *   security={{"sanctum":{}}},
     *   summary="Assign roles to user in tenant (replace)",
     *   @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\RequestBody(
     *     @OA\JsonContent(
     *       required={"role_keys"},
     *       @OA\Property(property="role_keys", type="array", @OA\Items(type="string"))
     *     )
     *   ),
     *   @OA\Response(response=200, description="OK")
     * )
     */
    public function assignUserRoles($userId, Request $req)
    {
        $data = $req->validate(['role_keys'=>'required|array']);
        $roles = Role::where('tenant_id', app('tenant.id'))->whereIn('key', $data['role_keys'])->get(['id']);

        UserRole::where('tenant_id', app('tenant.id'))->where('user_id', $userId)->delete();
        foreach ($roles as $r) {
            UserRole::create(['tenant_id'=>app('tenant.id'),'user_id'=>$userId,'role_id'=>$r->id]);
        }
        return response()->json(['ok'=>true,'count'=>$roles->count()]);
    }

    /**
     * @OA\Get(
     *   path="/t/{tenant}/permissions",
     *   tags={"RBAC"},
     *   security={{"sanctum":{}}},
     *   summary="List available permission keys",
     *   @OA\Response(response=200, description="OK")
     * )
     */
    public function listPermissions()
    {
        return response()->json(\App\Models\Core\Permission::orderBy('key')->get(['id','key','name']));
    }
}
