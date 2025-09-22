<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;     
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use App\Models\Core\{Tenant, Role, OrgMember, UserRole};
use Illuminate\Foundation\Auth\Access\AuthorizesRequests; // <- add this
use App\Models\Core\Plan;
use App\Models\Core\Subscription; 
use Illuminate\Support\Facades\Schema;

class TenantUserController extends Controller
{

    use AuthorizesRequests;
    /**
     * GET /users  (auth:sanctum + tenant + perm:user.read)
     * Returns members of the current tenant with their role slugs.
     */
    public function index(Request $req)
    {
        // $tenant = tenant(); // resolved by ResolveTenant middleware
          $tenant = app()->bound('tenant') ? app('tenant') : null;
    if (!$tenant) {
        return response()->json(['message' => 'Tenant missing'], 400);
    }
        // $this->authorize('perm', 'user.read');
        
// // ...inside your methods:
// Gate::authorize('perm', 'user.read');
// Gate::authorize('perm', 'user.write');

        // Users joined via OrgMember; attach role slugs for this tenant
        $members = OrgMember::query()
            ->where('tenant_id', $tenant->id)
            ->join('users', 'users.id', '=', 'org_members.user_id')
            ->select('users.id', 'users.name', 'users.email', 'org_members.status')
            ->get()
            ->map(function ($u) use ($tenant) {
                $roleIds = UserRole::where('tenant_id', $tenant->id)
                    ->where('user_id', $u->id)
                    ->pluck('role_id');
                $roles = Role::whereIn('id', $roleIds)->pluck('key');
                return [
                    'id'     => (int) $u->id,
                    'name'   => $u->name,
                    'email'  => $u->email,
                    'status' => $u->status ?? 'active',
                    'roles'  => $roles->values(),
                ];
            });

        return response()->json($members);
    }

   
public function store(Request $req)
{
    $tenant = app()->bound('tenant') ? app('tenant') : null;
    if (!$tenant) {
        return response()->json(['message' => 'Tenant missing'], 400);
    }

    Gate::authorize('perm', 'user.write');

    $data = $req->validate([
        'name'     => ['required','string','max:255'],
        'email'    => ['required','email','max:255'],
        'password' => ['nullable','string','min:8'],
        'role'     => ['required', Rule::in(['admin','viewer'])],
    ]);

    $seatLimit = $this->seatLimitForTenant($tenant); // <- reads plans.limits_json

    return DB::transaction(function () use ($tenant, $data, $seatLimit) {

        // Lock the tenant row to serialize seat counting
        Tenant::where('id', $tenant->id)->lockForUpdate()->first();

        // Count active users (exclude soft-deleted org_members if you use SoftDeletes)
        $activeCount = OrgMember::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->when(\Schema::hasColumn('org_members','deleted_at'), fn($q) => $q->whereNull('deleted_at'))
            ->count();

        // If user exists & has a disabled membership, re-activation will consume a seat
        $existingUser   = User::where('email', $data['email'])->first();
        $existingMember = $existingUser
            ? OrgMember::where('tenant_id', $tenant->id)->where('user_id', $existingUser->id)->first()
            : null;

        $willConsumeSeat = !$existingMember || ($existingMember && $existingMember->status !== 'active');

        if (is_int($seatLimit) && $willConsumeSeat && $activeCount >= $seatLimit) {
            return response()->json([
                'message'       => 'Seat limit reached for this plan. Upgrade your plan to add more users.',
                'seat_limit'    => $seatLimit,
                'active_users'  => $activeCount,
            ], 422);
        }

        // Role by KEY (never slug), scoped to this tenant or global
        $role = Role::where('key', $data['role'])
            ->where(function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->id)->orWhereNull('tenant_id');
            })->first();

        if (!$role) {
            return response()->json(['message' => "Role '{$data['role']}' not found"], 422);
        }
        if ($role->key === 'owner') {
            return response()->json(['message' => 'Cannot assign owner from this endpoint'], 403);
        }

        // Create or get base user
        $user = User::firstOrCreate(
            ['email' => $data['email']],
            ['name' => $data['name'], 'password' => Hash::make($data['password'] ?? Str::random(14))]
        );

        // Membership (composite key safe)
        $member = OrgMember::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['status' => 'active']
        );

        if ($member->exists && $member->status !== 'active') {
            // Flip to active (safe: we already passed the seat check)
            OrgMember::where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->update(['status' => 'active']);
            $member->status = 'active';
        }

        // Reset roles for this tenant & assign chosen one
        UserRole::where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();
        UserRole::create([
            'tenant_id' => $tenant->id,
            'user_id'   => $user->id,
            'role_id'   => $role->id,
        ]);

         // ---- NEW: email flows ----
        // 1) Send verification if not verified yet
        if (method_exists($user, 'hasVerifiedEmail') && !$user->hasVerifiedEmail()) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Throwable $e) {
                \Log::warning('Invite: failed sending verification email', [
                    'user_id' => $user->id,
                    'email'   => $user->email,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // 2) If you didn’t set a password, send a reset link so they can set one
        if (empty($data['password'])) {
            try {
                \Password::sendResetLink(['email' => $user->email]);
            } catch (\Throwable $e) {
                \Log::warning('Invite: failed sending password reset link', [
                    'user_id' => $user->id,
                    'email'   => $user->email,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'id'            => (int) $user->id,
            'name'          => (string) $user->name,
            'email'         => (string) $user->email,
            'status'        => (string) $member->status,
            'roles'         => [$role->key],
            'verification'  => !$user->hasVerifiedEmail(), // true means email was (re)sent
            'password_mail' => empty($data['password']),   // true means reset link was sent
            'seat_limit'    => $seatLimit,
            'active_users'  => $activeCount + ($willConsumeSeat ? 1 : 0),
        ], 201);
    });

        // return response()->json([
        //     'id'            => (int) $user->id,
        //     'name'          => (string) $user->name,
        //     'email'         => (string) $user->email,
        //     'status'        => (string) $member->status,
        //     'roles'         => [$role->key],
        //     'seat_limit'    => $seatLimit,
        //     'active_users'  => $activeCount + ($willConsumeSeat ? 1 : 0),
        // ], 201);
    // });
}

protected function seatLimitForTenant($tenant): ?int
{
    // Prefer explicit tenant column if you keep it
    if (isset($tenant->seat_limit) && is_numeric($tenant->seat_limit)) {
        return (int) $tenant->seat_limit;
    }
    if (isset($tenant->max_users) && is_numeric($tenant->max_users)) {
        return (int) $tenant->max_users;
    }

    // Resolve plan: try tenant->plan_id first, else active subscription
    $plan = null;

    if (!empty($tenant->plan_id)) {
        $plan = Plan::find($tenant->plan_id);
    }

    if (!$plan && class_exists(Subscription::class)) {
        $sub = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
        if ($sub && !empty($sub->plan_id)) {
            $plan = Plan::find($sub->plan_id);
        }
    }

    if (!$plan) return null;               // treat as unlimited if no plan
    if ($plan->is_active === 0) return null;  // optional: inactive plan → unlimited/ignore

    // Parse limits_json → users
    $limits = $plan->limits_json;
    if (is_string($limits)) {
        $decoded = json_decode($limits, true);
        if (json_last_error() === JSON_ERROR_NONE) $limits = $decoded;
    }
    if (is_object($limits)) $limits = (array) $limits;

    if (is_array($limits) && isset($limits['users']) && is_numeric($limits['users'])) {
        return (int) $limits['users'];     // e.g. 5 on Starter
    }

    return null; // unlimited if not set
}

    /**
     * PATCH /users/{id}  (auth:sanctum + tenant + perm:user.write)
     * Body: { name?, password?, role?, status? }
     */
public function update(Request $req, int $id)
{
    // Tenant context
    $tenant = app()->bound('tenant') ? app('tenant') : null;
    if (!$tenant) {
        return response()->json(['message' => 'Tenant missing'], 400);
    }

    Gate::authorize('perm', 'user.write');

    $data = $req->validate([
        'name'     => ['sometimes','string','max:255'],
        'email'    => ['sometimes','email','max:255', Rule::unique('users','email')->ignore($id)],
        'password' => ['sometimes','string','min:8'],
        'status'   => ['sometimes', Rule::in(['active','disabled'])],
        'role'     => ['sometimes', Rule::in(['admin','viewer'])], // owner intentionally excluded
    ]);

    $user = User::findOrFail($id);

    // ----- Update basic fields -----
    if (array_key_exists('name', $data))    { $user->name = $data['name']; }
    if (array_key_exists('email', $data))   { $user->email = $data['email']; }
    if (array_key_exists('password', $data)){ $user->password = Hash::make($data['password']); }
    $user->save();

    // ----- Membership status updates (composite key safe) -----
    if (array_key_exists('status', $data)) {
        // Ensure membership exists
        $isMember = OrgMember::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->exists();
        if (!$isMember) {
            return response()->json(['message' => 'User is not a member of this tenant'], 404);
        }

        if ($data['status'] === 'active') {
            // Enforce seat cap on re-activation
            $seatLimit = $this->seatLimitForTenant($tenant);
            if (is_int($seatLimit)) {
                $result = DB::transaction(function () use ($tenant, $user, $seatLimit) {
                    Tenant::where('id', $tenant->id)->lockForUpdate()->first();

                    $activeCount = OrgMember::where('tenant_id', $tenant->id)
                        ->where('status', 'active')
                        ->when(Schema::hasColumn('org_members','deleted_at'), fn($q) => $q->whereNull('deleted_at'))
                        ->count();

                    if ($activeCount >= $seatLimit) {
                        return ['error' => ['seat_limit' => $seatLimit, 'active' => $activeCount]];
                    }

                    OrgMember::where('tenant_id', $tenant->id)
                        ->where('user_id', $user->id)
                        ->update(['status' => 'active']);

                    return ['ok' => true];
                });

                if (isset($result['error'])) {
                    return response()->json([
                        'message'      => 'Seat limit reached for this plan. Cannot re-activate more users.',
                        'seat_limit'   => $result['error']['seat_limit'],
                        'active_users' => $result['error']['active'],
                    ], 422);
                }
            } else {
                OrgMember::where('tenant_id', $tenant->id)
                    ->where('user_id', $user->id)
                    ->update(['status' => 'active']);
            }
        } elseif ($data['status'] === 'disabled') {
            // Do not allow disabling an owner
            $isOwner = Role::join('user_roles as ur', 'ur.role_id', '=', 'roles.id')
                ->where('ur.user_id', $user->id)
                ->where('ur.tenant_id', $tenant->id)
                ->where('roles.key', 'owner')  // roles.key (not slug)
                ->exists();

            if ($isOwner) {
                return response()->json(['message' => 'Owner cannot be disabled'], 403);
            }

            OrgMember::where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->update(['status' => 'disabled']);
        }
    }

    // ----- Role update (never edit owner, never assign owner) -----
    if (array_key_exists('role', $data)) {
        // current roles for this tenant
        $currentRoleKeys = Role::join('user_roles as ur', 'ur.role_id', '=', 'roles.id')
            ->where('ur.user_id', $user->id)
            ->where('ur.tenant_id', $tenant->id)
            ->pluck('roles.key')
            ->all();

        if (in_array('owner', $currentRoleKeys, true)) {
            return response()->json(['message' => 'Owner role cannot be changed'], 403);
        }

        // target role by KEY, scoped to tenant (or global)
        $role = Role::where('key', $data['role'])
            ->where(function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->id)->orWhereNull('tenant_id');
            })
            ->first();

        if (!$role) {
            return response()->json(['message' => "Role '{$data['role']}' not found"], 422);
        }
        if ($role->key === 'owner') {
            return response()->json(['message' => 'Cannot assign owner from this endpoint'], 403);
        }

        // replace roles for this tenant
        UserRole::where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();
        UserRole::create([
            'tenant_id' => $tenant->id,
            'user_id'   => $user->id,
            'role_id'   => $role->id,
        ]);
    }

    return response()->json(['ok' => true]);
}

    /**
     * DELETE /users/{id}  (auth:sanctum + tenant + perm:user.write)
     * Removes membership & roles from THIS tenant (does not delete the user globally).
     */
 public function destroy(Request $req, int $id)
{
    $tenant = app()->bound('tenant') ? app('tenant') : null;
    if (!$tenant) {
        return response()->json(['message' => 'Tenant missing'], 400);
    }

    Gate::authorize('perm', 'user.write');

    // Must be a member of this tenant (ensures 404 if not)
    $exists = OrgMember::where('tenant_id', $tenant->id)
        ->where('user_id', $id)
        ->exists();
    if (!$exists) {
        abort(404);
    }

    // 🚫 Never remove/disable an OWNER of this tenant
    $isOwner = Role::join('user_roles as ur', 'ur.role_id', '=', 'roles.id')
        ->where('ur.user_id', $id)
        ->where('ur.tenant_id', $tenant->id)
        ->where('roles.key', 'owner')
        ->exists();
    if ($isOwner) {
        return response()->json(['message' => 'Owner role cannot be removed'], 403);
    }

    DB::transaction(function () use ($tenant, $id) {
        // remove role mappings
        UserRole::where('tenant_id', $tenant->id)
            ->where('user_id', $id)
            ->delete();

        // mark membership disabled (composite key ⇒ do NOT load then delete)
        OrgMember::where('tenant_id', $tenant->id)
            ->where('user_id', $id)
            ->update(['status' => 'disabled']);
    });

    return response()->json(['ok' => true]);
}
}
