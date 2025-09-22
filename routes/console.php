<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('tenant:ping {slug}', function (string $slug) {
    $this->call('tenant:health', ['slug' => $slug]);
})->purpose('Quick health check for a tenant');
