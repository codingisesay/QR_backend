<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUserController extends Controller
{
    // Very simple example: list all users across tenants (superadmin only)
    public function users(Request $req)
    {
        abort_unless($req->user()?->is_superadmin, 403);

        $rows = DB::table('users')
            ->select('id','name','email','created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json($rows);
    }
}
