<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PublicVerifyController;

Route::get('/login', fn() => response('Please login via the frontend app.', 200))->name('login');

Route::get('/v/{token}', [PublicVerifyController::class, 'verify'])->name('qr.verify');
Route::get('/t/{tenant}/v/{token}', [PublicVerifyController::class, 'verifyWithTenant'])->name('qr.verify.tenant');