<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

class NfcKeyService {
  public function loadKeyHex(int $tenantId, string $keyRef): ?string {
    $row = DB::table('tenant_settings')
      ->where('tenant_id',$tenantId)
      ->where('key','nfc.key.'.$keyRef)
      ->first();
    if (!$row) return null;
    $j = json_decode($row->value_json ?? '{}', true);
    if (($j['status'] ?? '') !== 'active') return null;
    $enc = $j['key_hex_enc'] ?? null;
    if (!$enc) return null;
    return Crypt::decryptString(base64_decode($enc)); // 32-char hex (16B AES-128)
  }
}
