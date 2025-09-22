<?php
namespace App\Http\Controllers;
use OpenApi\Annotations as OA;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use App\Models\User; // canonical
// use App\Models\Core\{Tenant, OrgMember, Role, UserRole};
use Illuminate\Support\Facades\DB;
use App\Models\Core\{Tenant, Role, Permission, OrgMember, UserRole};

class RegistrationController extends Controller
{
    /**
     * Public user signup.
     * Optionally accepts "company" to also create a tenant in shared mode.
     */

      /**
     * @OA\Post(
     *   path="/auth/register",
     *   tags={"Registration"},
     *   summary="Register a new user (optionally with company)",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"email","password"},
     *       @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *       @OA\Property(property="password", type="string", format="password", example="Strong@123"),
     *       @OA\Property(property="company", type="string", nullable=true, example="Acme Inc."),
     *       @OA\Property(property="plan_id", type="integer", nullable=true, example=1)
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Registered",
     *     @OA\JsonContent(
     *       @OA\Property(property="ok", type="boolean", example=true),
     *       @OA\Property(property="user_id", type="integer", example=101)
     *     )
     *   ),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    // 1) Signup: user only (no tenant)
public function registerUser(Request $req)
{
    $data = $req->validate([
        'name'     => 'nullable|string|max:191',
        'email'    => 'required|email|unique:users,email',
        'password' => 'required|string|min:8|confirmed',
        // remove company/plan from here to match your flow
    ]);

    $user = User::create([
        'name'          => $data['name'] ?? (Str::before($data['email'], '@') ?: 'User'),
        'email'         => $data['email'],
        'password'      => $data['password'],
        'is_superadmin' => false,
    ]);

    $user->sendEmailVerificationNotification();

    return response()->json([

            'ok' => true, 
            'user_id' => $user->id,
            'message' => 'Account created. Please check your email to verify.',
            
        ],201);
}
    /**
     * Authenticated: Register a tenant (shared|schema|database).
     * If schema/database, it provisions DB and runs tenant migrations.
     */

        /**
     * @OA\Post(
     *   path="/tenants",
     *   tags={"Registration"},
     *   summary="Create a tenant (shared / schema / database)",
     *   description="Authenticated endpoint. Provisions DB/schema when needed and seeds owner role.",
     *   security={{"bearerAuth":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","mode"},
     *       @OA\Property(property="name", type="string", example="Acme HQ"),
     *       @OA\Property(property="slug", type="string", nullable=true, example="acme-hq"),
     *       @OA\Property(property="plan_id", type="integer", nullable=true, example=2),
     *       @OA\Property(property="mode", type="string", enum={"shared","schema","database"}, example="schema"),
     *       @OA\Property(property="owner_user_id", type="integer", nullable=true, example=42)
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Created",
     *     @OA\JsonContent(
     *       @OA\Property(property="ok", type="boolean", example=true),
     *       @OA\Property(property="tenant", type="string", example="acme-hq"),
     *       @OA\Property(property="mode", type="string", example="schema")
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */


// 2) Owner creates a tenant (auth only; no tenant middleware)
public function registerTenant(Request $req)
{
    $user = $req->user();

    $data = $req->validate([
        'name'    => 'required|string|max:191',
        'slug'    => 'required|string|max:191|alpha_dash|unique:tenants,slug',
        'mode'    => 'nullable|in:shared,schema,database',
        'plan_id' => 'nullable|integer|exists:plans,id',
    ]);

    return DB::transaction(function () use ($data, $user) {
        $tenant = Tenant::create([
            'slug'           => $data['slug'],
            'name'           => $data['name'],
            'status'         => 'active',
            'plan_id'        => $data['plan_id'] ?? null,
            'isolation_mode' => $data['mode'] ?? 'shared',
        ]);

        // roles
        $owner  = Role::firstOrCreate(['tenant_id'=>$tenant->id,'key'=>'owner'],  ['name'=>'Owner']);
        $admin  = Role::firstOrCreate(['tenant_id'=>$tenant->id,'key'=>'admin'],  ['name'=>'Admin']);
        $viewer = Role::firstOrCreate(['tenant_id'=>$tenant->id,'key'=>'viewer'], ['name'=>'Viewer']);

        // permissions → your existing Permission keys
        $neededKeys = [
            'dashboard.read',
            'product.read','product.write',
            'user.write',
            'role.read','role.write',
            'comm.provider.read','comm.provider.write','comm.send',
            'tenant.write','subscription.write',
        ];
        $permIds = Permission::whereIn('key', $neededKeys)->pluck('id','key')->all();

        $syncFor = function(array $keys) use ($permIds, $tenant) {
            $sync = [];
            foreach ($keys as $k) if (isset($permIds[$k])) $sync[$permIds[$k]] = ['tenant_id'=>$tenant->id];
            return $sync;
        };

        // map
        $owner->permissions()->sync($syncFor(array_keys($permIds)));
        $admin->permissions()->sync($syncFor([
            'dashboard.read','product.read','product.write','user.write','role.read','role.write','comm.provider.read','comm.send',
        ]));
        $viewer->permissions()->sync($syncFor(['dashboard.read','product.read']));

        // make creator a member + owner
        OrgMember::updateOrCreate(
            ['tenant_id'=>$tenant->id,'user_id'=>$user->id],
            ['status'=>'active','joined_at'=>now()]
        );
        UserRole::updateOrCreate(
            ['tenant_id'=>$tenant->id,'user_id'=>$user->id,'role_id'=>$owner->id],
            []
        );

        return response()->json([
            'ok'     => true,
            'tenant' => ['id'=>$tenant->id, 'slug'=>$tenant->slug, 'name'=>$tenant->name],
            'role'   => 'owner',
        ], 201);
    });
}



    /**
     * Tenant-scoped: create a user inside a tenant and assign a role.
     * Route: POST /api/t/{tenant}/users
     */

       /**
     * @OA\Post(
     *   path="/t/{tenant}/users",
     *   tags={"Registration"},
     *   summary="Create a user inside a tenant and assign a role",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="tenant",
     *     in="path",
     *     required=true,
     *     description="Tenant slug",
     *     @OA\Schema(type="string", example="acme-hq")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"email","password"},
     *       @OA\Property(property="email", type="string", format="email", example="member@example.com"),
     *       @OA\Property(property="password", type="string", format="password", example="TempPwd#123"),
     *       @OA\Property(property="role_key", type="string", nullable=true, example="admin")
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Created",
     *     @OA\JsonContent(
     *       @OA\Property(property="ok", type="boolean", example=true),
     *       @OA\Property(property="user_id", type="integer", example=501),
     *       @OA\Property(property="role", type="string", example="admin")
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden"),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    // 3) Create a user inside the current tenant (auth + tenant)
public function createTenantUser($tenant, Request $req)
{
    $data = $req->validate([
        'email'    => 'required|email',
        'password' => 'required|min:8',
        'role_key' => 'nullable|string', // defaults to viewer
    ]);

    $user = User::firstOrCreate(
        ['email' => $data['email']],
        ['password' => $data['password'], 'is_superadmin' => false]
    );

    $roleKey = $data['role_key'] ?? 'viewer';
    $role = Role::where('tenant_id', app('tenant.id'))->where('key', $roleKey)->firstOrFail();

    OrgMember::updateOrCreate(
        ['tenant_id'=>app('tenant.id'),'user_id'=>$user->id],
        ['status'=>'active','joined_at'=>now()]
    );
    UserRole::updateOrCreate(
        ['tenant_id'=>app('tenant.id'),'user_id'=>$user->id,'role_id'=>$role->id],
        []
    );

    $user->sendEmailVerificationNotification();

    return response()->json(['ok'=>true,'user_id'=>$user->id,'role'=>$roleKey], 201);
}
}
