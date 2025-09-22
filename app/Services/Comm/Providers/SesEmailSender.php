<?php

namespace App\Services\Comm;

use Aws\Ses\SesClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Accepts $m = [
 *   'to_email'   => 'user@example.com',
 *   'subject'    => 'Hi',
 *   'body_text'  => 'Hello',     // optional
 *   'body_html'  => '<p>Hello</p>', // optional
 *   'provider_config' => [
 *       'region'     => 'ap-south-1',            // optional -> falls back to AWS_DEFAULT_REGION
 *       'access_key' => 'AKIA...',               // optional -> falls back to AWS_ACCESS_KEY_ID
 *       'secret_key' => 'SECRET',                // optional -> falls back to AWS_SECRET_ACCESS_KEY
 *       'from_email' => 'noreply@yourapp.com',   // optional -> falls back to MAIL_FROM_ADDRESS
 *       'from_name'  => 'Your SaaS',            // optional -> falls back to MAIL_FROM_NAME
 *   ]
 * ]
 */
class SesEmailSender implements \App\Services\Comm\ChannelSender
{
    public function send(array $m): array
    {
        $cfg    = $m['provider_config'] ?? [];

        $region = $cfg['region']     ?? env('AWS_DEFAULT_REGION', 'us-east-1');
        $key    = $cfg['access_key'] ?? env('AWS_ACCESS_KEY_ID');
        $secret = $cfg['secret_key'] ?? env('AWS_SECRET_ACCESS_KEY');

        // Allow delivery in dev even if keys are missing only when MAIL_MAILER=log,
        // but since this is the tenant comm path, we report error clearly:
        if (!$key || !$secret) {
            return ['ok' => false, 'error' => 'SES credentials missing: access_key/secret_key'];
        }

        $fromEmail = $cfg['from_email'] ?? env('MAIL_FROM_ADDRESS', 'noreply@example.com');
        $fromName  = $cfg['from_name']  ?? env('MAIL_FROM_NAME', 'Your SaaS');
        $source    = sprintf('"%s" <%s>', $fromName, $fromEmail);

        $toEmail = $m['to_email'] ?? null;
        if (!$toEmail) {
            return ['ok' => false, 'error' => 'to_email required'];
        }

        $body = [];
        if (!empty($m['body_html'])) $body['Html'] = ['Data' => $m['body_html']];
        if (!empty($m['body_text'])) $body['Text'] = ['Data' => $m['body_text']];
        if (!$body) $body['Text'] = ['Data' => ''];

        try {
            $client = new SesClient([
                'version'     => '2010-12-01',
                'region'      => $region,
                'credentials' => ['key' => $key, 'secret' => $secret],
            ]);

            $res = $client->sendEmail([
                'Source'      => $source,
                'Destination' => ['ToAddresses' => [$toEmail]],
                'Message'     => [
                    'Subject' => ['Data' => (string)($m['subject'] ?? '')],
                    'Body'    => $body,
                ],
            ]);

            return ['ok' => true, 'meta' => ['message_id' => $res->get('MessageId')]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
