<?php

namespace App\Services\Comm;

use App\Models\Core\CommProvider;
use App\Services\Comm\SesEmailSender;
use App\Services\Comm\TwilioSmsSender;
use App\Services\Comm\FcmPushSender;
use App\Services\Comm\LogChannelSender;

class CommSender
{
    public function __construct(
        protected SesEmailSender   $emailSender,
        protected TwilioSmsSender  $smsSender,
        protected FcmPushSender    $pushSender,
        protected LogChannelSender $logSender,
    ) {}

    /**
     * Resolve provider with tenant -> global fallback, then send.
     * $channel: email|sms|push
     * $message: validated payload (to_email/to_phone/device_token, subject, body_text/html ...)
     */
    public function sendForTenant(int $tenantId, string $channel, array $message): array
    {
        $provider = CommProvider::query()
            ->where('channel', $channel)
            ->where('status', 'active')
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                  ->orWhereNull('tenant_id'); // GLOBAL fallback
            })
            // prefer tenant-specific over global
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        if (!$provider) {
            return ['ok' => false, 'error' => "No active provider for channel=$channel", 'used' => null];
        }

        // Normalize provider credentials (JSON string or casted array)
        $cfg = $provider->credentials_json;
        if (!is_array($cfg)) {
            $cfg = $cfg ? json_decode($cfg, true) : [];
        }

        // Normalize common keys per channel
        if ($channel === 'sms') {
            // Accept either from_number or sender_id
            $cfg['from_number'] = $cfg['from_number'] ?? ($cfg['sender_id'] ?? null);
        } elseif ($channel === 'email') {
            // Prefer row-level from_* then env
            $cfg['from_email'] = $cfg['from_email'] ?? $provider->from_email ?? null;
            $cfg['from_name']  = $cfg['from_name']  ?? $provider->from_name  ?? null;
        }

        // Attach provider config for the adapter
        $message['provider_config'] = $cfg;

        // Choose adapter: allow explicit "log" provider for dev
        $providerName = strtolower((string) $provider->provider);
        if ($providerName === 'log') {
            $adapter = $this->logSender;
        } else {
            switch ($channel) {
                case 'email': $adapter = $this->emailSender; break;
                case 'sms':   $adapter = $this->smsSender;   break;
                case 'push':  $adapter = $this->pushSender;  break;
                default:
                    return ['ok' => false, 'error' => "Unsupported channel: $channel", 'used' => null];
            }
        }

        $res = $adapter->send($message);
        $res['used'] = [
            'id'        => $provider->id,
            'tenant_id' => $provider->tenant_id,
            'channel'   => $provider->channel,
            'provider'  => $provider->provider,
        ];
        return $res;
    }
}
