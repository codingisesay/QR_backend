<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Crypt};
use Illuminate\Validation\Rule;

class NfcController extends Controller {
  public function storeKey(Request $r) {
    $r->validate([
      'key_ref' => ['required','string','max:64'],
      'key_hex' => ['required','regex:/^[0-9a-fA-F]{32}$/'],
      'status'  => ['nullable', Rule::in(['active','retired'])]
    ]);
    $tenantId = (int) $r->user()->tenant_id;
    $payload = [
      'alg'         => 'SUN-AES128',
      'scope'       => 'batch',
      'status'      => $r->input('status','active'),
      'created_by'  => $r->user()->id,
      'key_hex_enc' => base64_encode(Crypt::encryptString($r->input('key_hex'))),
    ];
    DB::table('tenant_settings')->updateOrInsert(
      ['tenant_id'=>$tenantId,'key'=>'nfc.key.'.$r->input('key_ref')],
      ['value_json'=> json_encode($payload)]
    );
    return response()->json(['ok'=>true]);
  }

  // CSV/JSON bulk: [{token, uid}]
  public function provisionBulk(Request $r) {
    $r->validate([
      'items' => 'required|array|min:1',
      'items.*.token' => 'required|string',
      'items.*.uid'   => 'required|regex:/^[0-9A-Fa-f]{14}$/'
    ]);
    $tenantId = (int) $r->user()->tenant_id;
    $conn = 'domain_shared';

    DB::connection($conn)->transaction(function() use ($tenantId,$r,$conn){
      foreach ($r->input('items') as $it) {
        $hash = hash('sha256', $it['token']);
        $qr = DB::connection($conn)->table('qr_codes_s')
              ->where('tenant_id',$tenantId)->where('token_hash',$hash)
              ->lockForUpdate()->first();
        if (!$qr) continue;
        DB::connection($conn)->table('qr_codes_s')
          ->where('id',$qr->id)->update(['nfc_uid'=>strtoupper($it['uid'])]);
      }
    });

    return response()->json(['ok'=>true]);
  }
}
