<?php

namespace App\Services\Comm;

use GuzzleHttp\Client;

class FcmPushSender implements \App\Services\Comm\ChannelSender
{
    /**
     * Expected $m:
     *  [
     *    'to_device_token' => 'DEVICE_TOKEN',
     *    'title' => 'Title',
     *    'body_text' => 'Body text',   // you can also pass 'body'
     *    'data' => [ ... ],            // optional
     *    'provider_config' => [
     *       'server_key' => 'AAAA....' // optional -> env('FCM_SERVER_KEY')
     *    ]
     *  ]
     */
    public function send(array $m): array
    {
        $cfg       = $m['provider_config'] ?? [];
        $serverKey = $cfg['server_key'] ?? env('FCM_SERVER_KEY');
        $token     = $m['to_device_token'] ?? null;

        if (!$serverKey) return ['ok' => false, 'error' => 'FCM server_key missing'];
        if (!$token)     return ['ok' => false, 'error' => 'to_device_token required'];

        $title = (string)($m['title'] ?? '');
        $body  = (string)($m['body'] ?? $m['body_text'] ?? '');

        $http = new Client(['base_uri' => 'https://fcm.googleapis.com', 'timeout' => 10]);

        $payload = [
            'to' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => $m['data'] ?? new \stdClass(),
        ];

        try {
            $res = $http->post('/fcm/send', [
                'headers' => [
                    'Authorization' => 'key ' . $serverKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]);
            $status = $res->getStatusCode();
            $raw    = (string)$res->getBody();

            return ['ok' => $status === 200, 'meta' => ['http' => $status, 'body' => $raw]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
