<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PublicVerifyController;

Route::get('/login', fn() => response('Please login via the frontend app.', 200))->name('login');

Route::get('/qr/{token}.png', [PublicVerifyController::class, 'qrPng'])->name('qr.png');
Route::get('/qr/{token}/micro.png', [PublicVerifyController::class, 'microPng'])->name('qr.micro');

Route::get('/v/{token}', [PublicVerifyController::class, 'verify'])->name('qr.verify');
Route::get('/t/{tenant}/v/{token}', [PublicVerifyController::class, 'verifyWithTenant'])->name('qr.verify.tenant');

// routes/web.php (temporary)
Route::get('/whoami', function () {
    return response()->json([
        'php_sapi' => PHP_SAPI,
        'loaded_ini' => php_ini_loaded_file(),
    ]);
});