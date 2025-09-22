<?php

namespace App\Services\Comm;

use Illuminate\Support\Facades\Log;

class LogChannelSender implements \App\Services\Comm\ChannelSender
{
    public function send(array $m): array
    {
        Log::info('[Comm LOG] '.json_encode($m));
        return ['ok' => true, 'meta' => ['logged' => true]];
    }
}
