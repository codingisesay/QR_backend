<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PufController extends Controller {
  // CSV/JSON bulk: [{token, puf_id, puf_fingerprint_hash}]
  public function provisionBulk(Request $r) {
    $r->validate([
      'items' => 'required|array|min:1',
      'items.*.token' => 'required|string',
      'items.*.puf_id' => 'required|string|max:64',
      'items.*.puf_fingerprint_hash' => 'required|regex:/^[0-9A-Fa-f]{64}$/'
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
        DB::connection($conn)->table('qr_codes_s')->where('id',$qr->id)->update([
          'puf_id' => $it['puf_id'],
          'puf_fingerprint_hash' => strtolower($it['puf_fingerprint_hash'])
        ]);
      }
    });
    return response()->json(['ok'=>true]);
  }
}
