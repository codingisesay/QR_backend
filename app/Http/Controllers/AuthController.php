<?php

namespace App\Http\Controllers;
use OpenApi\Annotations as OA;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Core\Role;
use App\Models\Core\Permission;
use App\Models\Core\UserRole;       // pivot: user_id, tenant_id, role_id
use App\Models\Core\RolePermission; // pivot: role_id, permission_id
use App\Models\Core\OrgMember;
use App\Models\Core\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;





class AuthController extends Controller
{

        /**
     * @OA\Post(
     *   path="/auth/login",
     *   tags={"Auth"},
     *   summary="Login with email & password",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"email","password"},
     *       @OA\Property(property="email", type="string", format="email", example="admin@example.com"),
     *       @OA\Property(property="password", type="string", format="password", example="Admin@123")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Logged in",
     *     @OA\JsonContent(
     *       required={"token","user"},
     *       @OA\Property(property="token", type="string", example="1|eyJ0eXAiOiJKV1QiLCJh..."),
     *       @OA\Property(
     *         property="user",
     *         type="object",
     *         required={"id","email"},
     *         @OA\Property(property="id", type="integer", example=42),
     *         @OA\Property(property="email", type="string", format="email", example="user@example.com")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Invalid credentials"),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
   

          public function login(Request $req)
    {
        $data = $req->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
        }

        // Enforce verification for non-superadmin
        if (!$user->is_superadmin && is_null($user->email_verified_at)) {
            throw ValidationException::withMessages([
                'email' => ['Email address is not verified. Please verify to continue.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        // Superadmin: return all permissions immediately (no tenant needed)
        if ($user->is_superadmin) {
            $allPerms = Permission::pluck('key')->unique()->values()->all();

            return response()->json([
                'token' => $token,
                'user'  => [
                    'id'             => $user->id,
                    'name'           => $user->name,
                    'email'          => $user->email,
                    'email_verified' => (bool) $user->email_verified_at,
                    'is_superadmin'  => true,
                ],
                'roles'       => ['superadmin'],
                'permissions' => $allPerms,
            ]);
        }

        // Regular user: roles/perms are tenant-scoped; frontend calls /auth/me after selecting/creating tenant
        return response()->json([
            'token' => $token,
            'user'  => [
                'id'             => $user->id,
                'name'           => $user->name,
                'email'          => $user->email,
                'email_verified' => (bool) $user->email_verified_at,
                'is_superadmin'  => false,
            ],
            'roles'       => [],
            'permissions' => [],
        ]);
    }


    // GET /auth/me (requires Bearer token)
        /**
     * Get the currently authenticated user.
     *
     * Returns the basic profile of the user tied to the provided Bearer token.
     *
     * @OA\Get(
     *   path="/auth/me",
     *   operationId="authMe",
     *   tags={"Auth"},
     *   summary="Current user profile",
     *   description="Requires Sanctum Bearer token (personal access token).",
     *   security={{"sanctum":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       required={"id","email","email_verified"},
     *       @OA\Property(property="id", type="integer", example=1),
     *       @OA\Property(property="name", type="string", nullable=true, example="Akash Singh"),
     *       @OA\Property(property="email", type="string", format="email", example="akashsngh681681@gmail.com"),
     *       @OA\Property(property="email_verified", type="boolean", example=true)
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Unauthenticated",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Unauthenticated.")
     *     )
     *   )
     * )
     */
public function me(Request $req)
{
    $u = $req->user();

    // Soft-resolve tenant: never throw 400/423 here
    $tenant = $this->softResolveTenantFromRequest($req); // may be null
    $tenantId = $tenant?->id;

    // Superadmin: full access (optionally still report tenant if present)
    if (!empty($u->is_superadmin)) {
        $allPerms = Permission::pluck('key')->unique()->values()->all();

        return response()->json([
            'user' => [
                'id'             => (int) $u->id,
                'name'           => (string) $u->name,
                'email'          => (string) $u->email,
                'email_verified' => (bool) $u->email_verified_at,
                'is_superadmin'  => true,
            ],
            'tenant'      => $tenant ? [
                'id'     => (int) $tenant->id,
                'slug'   => (string) $tenant->slug,
                'status' => (string) ($tenant->status ?? 'active'),
            ] : null,
            'roles'       => ['superadmin'],
            'permissions' => $allPerms,
        ]);
    }

    // No tenant context yet (or invalid/locked): return user only
    if (!$tenantId) {
        return response()->json([
            'user' => [
                'id'             => (int) $u->id,
                'name'           => (string) $u->name,
                'email'          => (string) $u->email,
                'email_verified' => (bool) $u->email_verified_at,
                'is_superadmin'  => false,
            ],
            'tenant'      => null,
            'roles'       => [],
            'permissions' => [],
        ]);
    }

    // Tenant present & active → compute roles/perms
    [$roles, $perms] = $this->rolesAndPermsFor((int) $u->id, (int) $tenantId);

    return response()->json([
        'user' => [
            'id'             => (int) $u->id,
            'name'           => (string) $u->name,
            'email'          => (string) $u->email,
            'email_verified' => (bool) $u->email_verified_at,
            'is_superadmin'  => false,
        ],
        'tenant'      => [
            'id'     => (int) $tenant->id,
            'slug'   => (string) $tenant->slug,
            'status' => (string) ($tenant->status ?? 'active'),
        ],
        'roles'       => $roles,
        'permissions' => $perms,
    ]);
}

protected function softResolveTenantFromRequest(Request $req): ?Tenant
{
    // If middleware already bound it, use that
    if (app()->bound('tenant')) {
        $t = app('tenant');
        if ($t && ($t->status ?? 'active') === 'active') {
            return $t;
        }
        return null;
    }

    // Otherwise, look at X-Tenant (don’t fail hard)
    $slug = trim((string) $req->header('X-Tenant', ''));
    if ($slug === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $slug)) {
        return null;
    }

    $tenant = Tenant::where('slug', $slug)->first();
    if (!$tenant) return null;
    if (($tenant->status ?? 'active') !== 'active') return null;

    return $tenant;
}

/** Compute roles/permissions for a user within a tenant (cached ~60s). */
protected function rolesAndPermsFor(int $userId, int $tenantId): array
{
    $key = "permset:u{$userId}:t{$tenantId}";
    return Cache::remember($key, 60, function () use ($userId, $tenantId) {

        $roleIds = UserRole::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return [[], []];
        }

        // Your schema uses Role.key (not slug)
        $roleKeys = Role::whereIn('id', $roleIds)
            ->pluck('key')->unique()->values()->all();

        // Owner → wildcard
        if (in_array('owner', $roleKeys, true)) {
            $allPerms = Permission::pluck('key')->unique()->values()->all();
            return [$roleKeys, $allPerms];
        }

        $permIds = RolePermission::whereIn('role_id', $roleIds)
            ->pluck('permission_id');

        $permKeys = Permission::whereIn('id', $permIds)
            ->pluck('key')->unique()->values()->all();

        return [$roleKeys, $permKeys];
    });
}
        /**
     * @OA\Post(
     *   path="/auth/logout",
     *   tags={"Auth"},
     *   summary="Logout (revoke current token)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(@OA\Property(property="ok", type="boolean", example=true))
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated")
     * )
     */

       public function logout(Request $req)
    {
        $req->user()?->currentAccessToken()?->delete();
        return response()->json(['ok' => true]);
    }
/**
 * @OA\Post(
 *   path="/auth/forgot-password",
 *   tags={"Auth"},
 *   summary="Send password reset link to email",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"email"},
 *       @OA\Property(property="email", type="string", format="email", example="user@example.com")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Reset link email sent",
 *     @OA\JsonContent(
 *       @OA\Property(property="ok", type="boolean", example=true),
 *       @OA\Property(property="message", type="string", example="We have emailed your password reset link!")
 *     )
 *   ),
 *   @OA\Response(
 *     response=422,
 *     description="Validation error or user not found",
 *     @OA\JsonContent(
 *       @OA\Property(property="message", type="string", example="The given data was invalid."),
 *       @OA\Property(
 *         property="errors",
 *         type="object",
 *         @OA\Property(
 *           property="email",
 *           type="array",
 *           @OA\Items(type="string", example="We can't find a user with that email address.")
 *         )
 *       )
 *     )
 *   )
 * )
 */

public function forgot(Request $req)
{
    $data = $req->validate(['email' => 'required|email']);
    $status = Password::sendResetLink(['email' => $data['email']]);
    if ($status === Password::RESET_LINK_SENT) {
        return response()->json(['ok'=>true, 'message'=>'Reset link sent.'], 202);
    }
    throw ValidationException::withMessages(['email' => [__($status)]]);
}

/**
 * @OA\Post(
 *   path="/auth/reset-password",
 *   tags={"Auth"},
 *   summary="Reset password using email + reset token",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"email","token","password","password_confirmation"},
 *       @OA\Property(property="email", type="string", format="email", example="user@example.com"),
 *       @OA\Property(property="token", type="string", example="reset-token-from-email"),
 *       @OA\Property(property="password", type="string", format="password", example="NewStrong#123"),
 *       @OA\Property(property="password_confirmation", type="string", format="password", example="NewStrong#123")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Password reset successful",
 *     @OA\JsonContent(@OA\Property(property="ok", type="boolean", example=true))
 *   ),
 *   @OA\Response(response=400, description="Invalid or expired token"),
 *   @OA\Response(response=422, description="Validation error")
 * )
 */

public function reset(Request $req)
{
    $data = $req->validate([
        'email'    => 'required|email',
        'token'    => 'required|string',
        'password' => 'required|string|min:8|confirmed',
    ]);

    $status = Password::reset(
        $data,
        function (User $user, string $password) {
            $user->forceFill(['password' => bcrypt($password)]);
            $user->setRememberToken(Str::random(60));
            $user->save();
            event(new PasswordReset($user));
        }
    );

    if ($status === Password::PASSWORD_RESET) {
        return response()->json(['ok'=>true, 'message'=>'Password has been reset.'], 200);
    }
    throw ValidationException::withMessages(['email' => [__($status)]]);
}

/**
 * @OA\Post(
 *   path="/auth/email/send-verification",
 *   tags={"Auth"},
 *   summary="Send email verification link to the authenticated user",
 *   security={{"bearerAuth":{}}},
 *   @OA\Response(
 *     response=200,
 *     description="Verification email sent",
 *     @OA\JsonContent(@OA\Property(property="ok", type="boolean", example=true))
 *   ),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 */

public function sendVerification(Request $req)
{
    $u = $req->user();
    if (!$u) abort(401);

    if ($u->hasVerifiedEmail()) {
        return response()->json(['ok'=>true, 'message'=>'Email already verified.']);
    }
    $u->sendEmailVerificationNotification();
    return response()->json(['ok'=>true, 'message'=>'Verification link sent.'], 202);
}


// Public re-send (since you block login before verify)
public function resendPublic(Request $req)
{
    $data = $req->validate(['email' => 'required|email']);
    $user = User::where('email', $data['email'])->first();
    if (!$user) return response()->json(['ok'=>true], 202); // don’t reveal existence

    if ($user->hasVerifiedEmail()) {
        return response()->json(['ok'=>true, 'message'=>'Email already verified.'], 200);
    }
    $user->sendEmailVerificationNotification();
    return response()->json(['ok'=>true, 'message'=>'Verification link sent.'], 202);
}



/**
 * @OA\Post(
 *   path="/auth/email/verify",
 *   tags={"Auth"},
 *   summary="Verify email using token/OTP or signed URL fields",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       oneOf={
 *         @OA\Schema(
 *           required={"token"},
 *           @OA\Property(property="token", type="string", example="verification-token-or-otp")
 *         ),
 *         @OA\Schema(
 *           required={"id","hash","expires","signature"},
 *           @OA\Property(property="id", type="integer", example=42, description="User ID from the link"),
 *           @OA\Property(property="hash", type="string", example="abcdef123456"),
 *           @OA\Property(property="expires", type="integer", example=1725400000),
 *           @OA\Property(property="signature", type="string", example="signed-url-signature")
 *         )
 *       }
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Email verified",
 *     @OA\JsonContent(@OA\Property(property="ok", type="boolean", example=true))
 *   ),
 *   @OA\Response(response=400, description="Invalid/expired verification"),
 *   @OA\Response(response=422, description="Validation error")
 * )
 */

public function verifyEmail(Request $req, $id, $hash)
{
    $user = User::findOrFail($id);
    if (! hash_equals((string)$hash, sha1($user->getEmailForVerification()))) {
        abort(403, 'Invalid verification link');
    }
    if (!$user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
    }
    // After verifying, redirect to your SPA
    $front = rtrim(config('app.frontend_url', env('FRONTEND_URL')), '/');
    return redirect()->away($front . '/authentication/sign-in?verified=1');
}

public function myTenants(Request $req) {
    $rows = \App\Models\Core\OrgMember::with('tenant:id,slug,name')
        ->where('user_id', $req->user()->id)
        ->where('status', 'active')
        ->get()
        ->map(fn($m) => ['id'=>$m->tenant->id, 'slug'=>$m->tenant->slug, 'name'=>$m->tenant->name])
        ->values();
    return response()->json($rows);
}
}
