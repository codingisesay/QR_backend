<?php

namespace App\Services\Comm;

use Twilio\Rest\Client;

class TwilioSmsSender implements \App\Services\Comm\ChannelSender
{
    /**
     * Expected $m:
     *  [
     *    'to_phone' => '+15005550006',
     *    'body_text' => 'Hello',
     *    'provider_config' => [
     *       'account_sid' => 'ACxxxx',          // optional -> env('TWILIO_SID')
     *       'auth_token'  => 'xxxx',            // optional -> env('TWILIO_TOKEN')
     *       'from_number' => '+15005550006',    // or 'sender_id' ; optional -> env('TWILIO_FROM')
     *    ]
     *  ]
     */
    public function send(array $m): array
    {
        $cfg  = $m['provider_config'] ?? [];
        $sid  = $cfg['account_sid'] ?? env('TWILIO_SID');
        $tok  = $cfg['auth_token']  ?? env('TWILIO_TOKEN');
        $from = $cfg['from_number'] ?? $cfg['sender_id'] ?? ($m['from_number'] ?? env('TWILIO_FROM'));
        $to   = $m['to_phone'] ?? null;

        if (!$sid || !$tok || !$from) {
            return ['ok' => false, 'error' => 'Twilio credentials missing: account_sid/auth_token/from_number'];
        }
        if (!$to) {
            return ['ok' => false, 'error' => 'to_phone required'];
        }

        try {
            $tw  = new Client($sid, $tok);
            $msg = $tw->messages->create($to, [
                'from' => $from,
                'body' => (string)($m['body_text'] ?? ''),
            ]);

            return ['ok' => true, 'meta' => ['sid' => $msg->sid, 'status' => $msg->status]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
