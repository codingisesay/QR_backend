<?php

namespace App\Http\Controllers;

use App\Support\ResolvesTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class NfcController extends Controller
{
    use ResolvesTenant;

    // POST /nfc/keys
    public function storeKey(Request $req)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $data = $req->validate([
            'key_ref'     => ['required','string','max:64'],
            'chip_family' => ['required', Rule::in(['NTAG424','DESFireEV3','Other'])],
            'status'      => ['nullable', Rule::in(['active','retired','revoked'])],
            'key_hex'     => ['nullable','regex:/^[0-9a-fA-F]{32}$/'],
        ]);

        // Example: store in core. Adjust table/connection to your project preference.
        $core = $this->coreConn();
        if (!Schema::connection($core)->hasTable('tenant_settings')) {
            return response()->json(['message'=>'tenant_settings table not found in core DB'], 500);
        }

        $payload = [
            'chip_family' => $data['chip_family'],
            'alg'         => 'SUN-AES128',
            'scope'       => 'batch',
            'status'      => $data['status'] ?? 'active',
            'created_by'  => $req->user()?->id,
            'key_hex_enc' => isset($data['key_hex']) ? base64_encode(Crypt::encryptString($data['key_hex'])) : null,
        ];

        DB::connection($core)->table('tenant_settings')->updateOrInsert(
            ['tenant_id'=>$tenant->id, 'key'=>'nfc.key.'.$data['key_ref']],
            ['value_json'=> json_encode($payload), 'updated_at'=>now()]
        );

        return response()->json(['ok'=>true,'message'=>'Key reference stored','key_ref'=>$data['key_ref']], 201);
    }

    // POST /nfc/provision/bulk  (multipart form-data: file=.csv)
    public function provisionBulk(Request $req)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $v = Validator::make($req->all(), [
            'file'                => 'required|file|mimes:csv,txt',
            'default_key_ref'     => 'nullable|string|max:64',
            'default_chip_family' => ['nullable', Rule::in(['NTAG424','DESFireEV3','Other'])],
            'default_status'      => ['nullable', Rule::in(['new','qc_pass','reserved','bound','retired','revoked'])],
        ]);
        if ($v->fails()) return response()->json(['message'=>'Validation failed','errors'=>$v->errors()], 422);

        $conn = $this->sharedConn();
        if (!Schema::connection($conn)->hasTable('nfc_tags_s')) {
            return response()->json(['message'=>'nfc_tags_s table not found on shared DB'], 500);
        }

        $defaults = [
            'key_ref'     => $req->string('default_key_ref')->toString() ?: null,
            'chip_family' => $req->string('default_chip_family')->toString() ?: 'NTAG424',
            'status'      => $req->string('default_status')->toString() ?: 'qc_pass', // ready-to-mint default
        ];

        $path = $req->file('file')->getRealPath();
        $fh = fopen($path, 'r');
        if (!$fh) return response()->json(['message'=>'Failed to open file'], 400);

        $header = fgetcsv($fh); if (!$header) { fclose($fh); return response()->json(['message'=>'Empty CSV'], 400); }
        $map = [];
        foreach ($header as $i => $name) $map[Str::of($name)->trim()->lower()->toString()] = $i;

        $need = ['nfc_uid']; // nfc_key_ref may come from default
        foreach ($need as $col) if (!array_key_exists($col, $map)) { fclose($fh); return response()->json(['message'=>"Missing column: $col"], 400); }

        $rows = []; $errors = []; $line = 1;
        while (($data = fgetcsv($fh)) !== false) {
            $line++;
            $uid = trim((string)($data[$map['nfc_uid']] ?? ''));
            $key = trim((string)($data[$map['nfc_key_ref']] ?? ($defaults['key_ref'] ?? '')));
            $fam = trim((string)($data[$map['chip_family']] ?? $defaults['chip_family']));
            $ctr = trim((string)($data[$map['ctr_seed']] ?? '0'));
            $st  = trim((string)($data[$map['status']] ?? $defaults['status']));
            $note= isset($map['qc_notes']) ? trim((string)$data[$map['qc_notes']]) : null;

            if ($uid === '') { $errors[] = ['line'=>$line,'error'=>'nfc_uid empty']; continue; }
            if ($key === '') { $errors[] = ['line'=>$line,'error'=>'nfc_key_ref empty (no default provided)']; continue; }
            if (!in_array($fam, ['NTAG424','DESFireEV3','Other'], true)) { $errors[]=['line'=>$line,'error'=>'chip_family invalid']; continue; }
            if (!ctype_digit((string)$ctr)) { $errors[]=['line'=>$line,'error'=>'ctr_seed must be unsigned integer']; continue; }
            if (!in_array($st, ['new','qc_pass','reserved','bound','retired','revoked'], true)) { $errors[]=['line'=>$line,'error'=>'status invalid']; continue; }

            $rows[] = [
                'tenant_id'   => (int)$tenant->id,
                'nfc_uid'     => strtoupper($uid),
                'nfc_key_ref' => $key,
                'chip_family' => $fam,
                'ctr_seed'    => (int)$ctr,
                'status'      => $st,
                'qc_notes'    => $note,
                'batch_id'    => null,
                'print_run_id'=> null,
                'qr_code_id'  => null,
                'imported_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ];
        }
        fclose($fh);

        if (!$rows && !$errors) return response()->json(['message'=>'No data rows found'], 400);

        DB::connection($conn)->table('nfc_tags_s')->upsert(
            $rows,
            ['tenant_id','nfc_uid'],
            ['nfc_key_ref','chip_family','ctr_seed','status','qc_notes','updated_at']
        );

        return response()->json([
            'message'=>'Import complete',
            'tenant_id'=>(int)$tenant->id,
            'inserted_or_updated'=>count($rows),
            'errors'=>$errors,
        ]);
    }
}
