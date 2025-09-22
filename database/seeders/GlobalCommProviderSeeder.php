<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Core\CommProvider;

class GlobalCommProviderSeeder extends Seeder
{
    public function run(): void
    {
        // Only seed if SES envs are present (avoid broken credentials)
        $key    = env('AWS_ACCESS_KEY_ID');
        $secret = env('AWS_SECRET_ACCESS_KEY');
        $region = env('AWS_DEFAULT_REGION');
        $from   = env('MAIL_FROM_ADDRESS');
        $name   = env('MAIL_FROM_NAME', 'Your SaaS');

        if (!($key && $secret && $region && $from)) {
            // Skip silently; your app will still run.
            return;
        }

        CommProvider::updateOrCreate(
            ['tenant_id' => null, 'channel' => 'email', 'provider' => 'ses'],
            [
                'name' => 'Global SES',
                'credentials_json' => [
                    'key' => $key,
                    'secret' => $secret,
                    'region' => $region,
                ],
                'from_email' => $from,
                'from_name'  => $name,
                'status' => 'active',
            ]
        );
    }
}
