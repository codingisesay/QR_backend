<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\SystemController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\TenantAdminController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\RbacController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CommController;
use App\Http\Controllers\CommProviderController;
use App\Http\Controllers\TenantOnboardingController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\TenantUserController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DeviceAssemblyController;
use App\Http\Controllers\CompositeQrController;
// use App\Http\Controllers\QrPreviewController;
// use App\Http\Controllers\DeviceAssemblyPublicController;

// /* Preview dialog */
// Route::get('/print-runs/{run}/codes', [QrController::class, 'codesByRun']);

// /* Tree for "Grouped by root" */
// Route::get('/devices/{deviceUid}/assembly', [DeviceAssemblyController::class, 'show']);

// /* Product → Batches, Batch → Runs */
// Route::get('/products/{sku}/batches', [QrController::class, 'batchesByProduct']);
// Route::get('/batches/{batch}/runs', [QrController::class, 'runsByBatch']);

// /* Stats, ZIP */
// Route::get('/qr/plan-stats', [QrController::class, 'planStats']);
// Route::get('/products/{sku}/label-stats', [QrController::class, 'labelStats']);
// Route::get('/print-runs/{run}/qr.zip', [QrController::class, 'downloadZip']);

// /* Composite one-click */
// Route::post('/qr/composite/mint-assemble', [CompositeQrController::class, 'mintAssemble']);

// Route::prefix('v2')->group(function () {
//     // Preview tiles
//     Route::get('/print-runs/{run}/codes', [QrPreviewController::class, 'codesByRun']);

//     // Product → Batches, Batch → Runs
//     Route::get('/products/{sku}/batches', [QrPreviewController::class, 'batchesByProduct']);
//     Route::get('/batches/{batch}/runs',   [QrPreviewController::class, 'runsByBatch']);

//     // Stats & ZIP
//     Route::get('/qr/plan-stats',              [QrPreviewController::class, 'planStats']);
//     Route::get('/products/{sku}/label-stats', [QrPreviewController::class, 'labelStats']);
//     Route::get('/print-runs/{run}/qr.zip',    [QrPreviewController::class, 'downloadZip']);

//     // Per-root tree for “Grouped by root”
//     Route::get('/devices/{deviceUid}/assembly', [DeviceAssemblyPublicController::class, 'show']);
// });


/* -------------------- Health -------------------- */
Route::get('/ping', [SystemController::class, 'ping']);
Route::get('/qr/peek/{token}', [QrController::class, 'peek']); // dev-only

Route::get('/qr/composite/selftest', [CompositeQrController::class, 'selfTest']);
Route::post('/qr/composite/mint-assemble', [CompositeQrController::class, 'mintAssemble']);

/* -------------------- Public -------------------- */
Route::prefix('auth')->group(function () {
    Route::post('/register',        [RegistrationController::class, 'registerUser']);
    Route::post('/login',           [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgot']);
    Route::post('/reset-password',  [AuthController::class, 'reset']);

     // verify link itself should be public (signed)
    Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed','throttle:6,1'])
        ->name('verification.verify');

          // Optional public re-send (useful since you block login before verify)
  Route::post('/resend-verification', [AuthController::class, 'resendPublic'])
      ->middleware('throttle:3,5');

    // send verification email (needs token, no tenant)
    Route::post('/email/verification-notification', [AuthController::class, 'sendVerification'])
        ->middleware(['auth:sanctum','throttle:6,1'])
        ->name('verification.send');

   
});
    
// ---------------- Token-only (no tenant) ----------------
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/tenants/init', [TenantOnboardingController::class, 'init']);
    Route::get('/tenants/{id}/status', [TenantOnboardingController::class, 'status']);

    // optional helpers
    Route::get('/auth/my-tenants', [AuthController::class, 'myTenants']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/plans', [PlanController::class, 'index']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});


    // Webhooks
Route::post('/webhooks/stripe', [TenantOnboardingController::class, 'stripeWebhook']);
Route::post('/webhooks/razorpay', [TenantOnboardingController::class, 'razorpayWebhook']);

/* -------------------- Authenticated + Tenant -------------------- */
Route::middleware(['auth:sanctum','tenant'])->group(function () {

    // current user (tenant-scoped roles/permissions)
    

    // Users inside a tenant (owner/admin)
    // Route::post('/users', [RegistrationController::class, 'createTenantUser'])
    //     ->middleware('perm:user.write');

    Route::get( '/users',        [TenantUserController::class, 'index'])->middleware('perm:user.read');
    Route::post('/users',        [TenantUserController::class, 'store'])->middleware('perm:user.write');
    Route::patch('/users/{id}',  [TenantUserController::class, 'update'])->middleware('perm:user.write');
    Route::delete('/users/{id}', [TenantUserController::class, 'destroy'])->middleware('perm:user.write');

    Route::get( '/products',        [ProductController::class, 'index'])->middleware('perm:product.read');
    Route::post('/products',        [ProductController::class, 'store'])->middleware('perm:product.write');
    Route::patch('/products/{id}',  [ProductController::class, 'update'])->middleware('perm:product.write');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->middleware('perm:product.write');

    Route::post('/products/{idOrSku}/codes/mint', [QrController::class, 'mintForProduct']);

      // Bind by scanning a QR token and entering a device UID + attrs
  Route::post('/qr/{token}/bind', [DeviceController::class, 'bindByToken']);

  // Bulk bind (allocate next available tokens or use provided tokens)
  Route::post('/products/{idOrSku}/devices/bind-bulk', [DeviceController::class, 'bulkBind']);

  // Lookups
  Route::get('/devices/{deviceUid}', [DeviceController::class, 'show']);

  Route::get('/products/{idOrSku}/label-stats', [QrController::class, 'labelStats']);

   Route::get('/qr/print-runs/{runId}/codes', [QrController::class, 'listRunCodes']); // NEW

     // Create/extend an assembly for a parent device
    Route::post('/devices/assemble', [DeviceAssemblyController::class, 'assemble']);

    // Get assembly (children, coverage vs BOM)
    Route::get('/devices/{deviceUid}/assembly', [DeviceAssemblyController::class, 'getAssembly']);

    // (Optional) detach a child device from its parent
    Route::delete('/devices/{deviceUid}/assembly/{childUid}', [DeviceAssemblyController::class, 'detach']);
 

});

/* -------- Superadmin-only (optional) -------- */
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/admin/users', [AdminUserController::class, 'users']); // checks is_superadmin inside
});

/* -------------------- Admin area -------------------- */
// Keep admin-only tenant creation separate and permissioned
Route::middleware(['auth:sanctum'])->group(function () {

    // Plans
    Route::get ('/admin/plans',      [PlanController::class, 'index'])->middleware('perm:plan.read');
    Route::post('/admin/plans',      [PlanController::class, 'store'])->middleware('perm:plan.write');
    Route::put ('/admin/plans/{id}', [PlanController::class, 'update'])->middleware('perm:plan.write');

    // Tenants (admin-provisioned)
    Route::get ('/admin/tenants',                [TenantAdminController::class, 'list'])->middleware('perm:tenant.read');
    Route::post('/admin/tenants',                [TenantAdminController::class, 'registerTenant'])->middleware('perm:tenant.create');
    Route::put ('/admin/tenants/{id}',           [TenantAdminController::class, 'updateStatus'])->middleware('perm:tenant.write');

    // Admin creates/renews a subscription for a tenant
    Route::post('/admin/tenants/{tenantId}/subscription', [SubscriptionController::class, 'store'])
        ->middleware('perm:tenant.write');
     // Mint + plan stats + zip export
    Route::post('/products/{idOrSku}/codes/mint', [QrController::class, 'mintForProduct']);
    Route::get('/qr/plan-stats', [QrController::class, 'planStats']);
    Route::get('/print-runs/{printRunId}/qr.zip', [QrController::class, 'exportZip']);

    // Lists for UI
    Route::get('/print-runs/{printRunId}/codes', [QrController::class, 'listForPrintRun']);
    Route::get('/products/{idOrSku}/codes',     [QrController::class, 'listForProduct']);

    // Batches & runs
    Route::get('/products/{idOrSku}/batches', [QrController::class, 'batchesForProduct']);
    Route::get('/batches/{batchId}/runs',     [QrController::class, 'runsForBatch']);
});

// TEMP during development: no auth
// Route::middleware(['resolve.tenant'])->group(function () {
//     Route::post('/products/{idOrSku}/codes/mint', [QrController::class, 'mintForProduct']);
//     Route::get('/print-runs/{printRunId}/qr.zip', [QrController::class, 'exportZip']);
//     Route::get('/qr/plan-stats', [QrController::class, 'planStats']);
// });

/* -------------------- Tenant-scoped -------------------- */
Route::prefix('/t/{tenant}')
    ->middleware(['tenant','auth:sanctum'])
    ->group(function () {

//   Route::get( '/products',        [ProductController::class, 'index'])->middleware('perm:product.read');
// Route::post('/products',        [ProductController::class, 'store'])->middleware('perm:product.write');
// Route::patch('/products/{id}',  [ProductController::class, 'update'])->middleware('perm:product.write');
// Route::delete('/products/{id}', [ProductController::class, 'destroy'])->middleware('perm:product.write');

        // // RBAC
        // Route::get ('/roles',                      [RbacController::class, 'listRoles'])->middleware('perm:role.read');
        // Route::post('/roles',                      [RbacController::class, 'createRole'])->middleware('perm:role.write');
        // Route::post('/roles/{roleId}/permissions', [RbacController::class, 'setRolePermissions'])->middleware('perm:role.write');
        // Route::post('/users/{userId}/roles',       [RbacController::class, 'assignUserRoles'])->middleware('perm:role.write');
        // Route::get ('/permissions',                [RbacController::class, 'listPermissions'])->middleware('perm:role.read');

        // // 👇 Create a user inside the tenant (and assign a role)
        // // Your controller signature: createTenantUser(string $tenant, Request $req)
        // Route::post('/users', [RegistrationController::class, 'createTenantUser'])
        //     ->middleware('perm:user.write');

        // // (Optional) Owner-only: buy/renew plan for this tenant (self-serve billing)
        // // Guard strictly: perm 'tenant.write' OR a custom 'role:owner'.
        // Route::post('/subscription', [SubscriptionController::class, 'storeTenant'])
        //     ->middleware('perm:tenant.write');

        // // Dashboard
        // Route::get('/dashboard', [DashboardController::class, 'summary'])->middleware('perm:dashboard.read');

        // // Products
        // Route::get   ('/products',          [ProductController::class, 'index'])->middleware('perm:product.read');
        // Route::get   ('/products/{sku}',    [ProductController::class, 'show'])->middleware('perm:product.read');
        // Route::post  ('/products',          [ProductController::class, 'store'])->middleware('perm:product.write');
        // Route::put   ('/products/{sku}',    [ProductController::class, 'update'])->middleware('perm:product.write');
        // Route::delete('/products/{sku}',    [ProductController::class, 'destroy'])->middleware('perm:product.write');

        // // Comms
        // Route::post('/comm/queue', [CommController::class, 'enqueue'])->middleware('perm:comm.send');
        // Route::post('/comm/send',  [CommController::class, 'sendNow'])->middleware('perm:comm.send');
        // Route::post('/comm/test',  [CommController::class, 'test'])->middleware('perm:comm.send');

        // // Provider configs
        // Route::get ('/comm/providers',      [CommProviderController::class, 'index'])->middleware('perm:comm.provider.read');
        // Route::post('/comm/providers',      [CommProviderController::class, 'store'])->middleware('perm:comm.provider.write');
        // Route::put ('/comm/providers/{id}', [CommProviderController::class, 'update'])->middleware('perm:comm.provider.write');
    });
