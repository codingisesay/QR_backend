<?php
// app/Http/Middleware/RequirePermission.php (Laravel 12 – good to go)

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Gate;

class RequirePermission
{
    public function handle($request, Closure $next, string $permKey)
    {
        Gate::authorize('perm', $permKey);
        return $next($request);
    }
}
