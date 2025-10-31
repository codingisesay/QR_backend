<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class NfcController extends Controller
{
    /**
     * Helper to resolve tenant_id (adjust to your project’s tenant resolution)
     */
    private function resolveTenantId(Request $req): ?int
    {
        // Prefer your existing tenant() helper if available:
        // $tenant = $this->tenant($req); return $tenant?->id;
        if ($req->user() && isset($req->user()->tenant_id)) {
            return (int) $req->user()->tenant_id;
        }
        // fallback from request for admin/scripted uploads
        return $req->integer('tenant_id') ?: null;
    }

    /**
     * POST /nfc/provision/bulk
     * Body: multipart/form-data with "file" (CSV), optional "default_key_ref", "default_chip_family", "default_status"
     */
    public function provisionBulk(Request $req)
    {
        $tenantId = $this->resolveTenantId($req);
        if (!$tenantId) {
            return response()->json(['message' => 'Tenant not resolved'], 400);
        }

        $v = Validator::make($req->all(), [
            'file' => 'required|file|mimes:csv,txt', // simple & reliable
            'default_key_ref' => 'nullable|string|max:64',
            'default_chip_family' => 'nullable|in:NTAG424,DESFireEV3,Other',
            'default_status' => 'nullable|in:new,qc_pass,reserved,bound,retired,revoked',
        ]);
        if ($v->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }

        $defaults = [
            'key_ref' => $req->string('default_key_ref')->toString() ?: null,
            'chip_family' => $req->string('default_chip_family')->toString() ?: 'NTAG424',
            'status' => $req->string('default_status')->toString() ?: 'new',
        ];

        $path = $req->file('file')->getRealPath();
        if (!is_readable($path)) {
            return response()->json(['message' => 'Upload not readable'], 400);
        }

        // Parse CSV (assumes header row present)
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return response()->json(['message' => 'Failed to open uploaded file'], 400);
        }

        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            return response()->json(['message' => 'Empty CSV or missing header'], 400);
        }

        // Map headers (case-insensitive)
        $map = [];
        foreach ($header as $i => $name) {
            $key = Str::of($name)->trim()->lower()->toString();
            $map[$key] = $i;
        }

        $requiredCols = ['nfc_uid','nfc_key_ref'];
        foreach ($requiredCols as $col) {
            if (!array_key_exists($col, $map) && $col === 'nfc_key_ref' && $defaults['key_ref']) {
                // ok, we'll use default for key_ref if not present
                continue;
            }
            if (!array_key_exists($col, $map)) {
                fclose($fh);
                return response()->json(['message' => "Missing required column: {$col}"], 400);
            }
        }

        $rows = [];
        $errors = [];
        $lineNo = 1; // header line
        while (($data = fgetcsv($fh)) !== false) {
            $lineNo++;

            // Extract fields with fallbacks
            $nfc_uid = isset($map['nfc_uid']) ? trim((string)$data[$map['nfc_uid']]) : '';
            $nfc_key_ref = isset($map['nfc_key_ref']) ? trim((string)$data[$map['nfc_key_ref']]) : ($defaults['key_ref'] ?? '');
            $chip_family = isset($map['chip_family']) ? trim((string)$data[$map['chip_family']]) : $defaults['chip_family'];
            $ctr_seed = isset($map['ctr_seed']) ? (string)$data[$map['ctr_seed']] : '0';
            $status = isset($map['status']) ? trim((string)$data[$map['status']]) : $defaults['status'];
            $qc_notes = isset($map['qc_notes']) ? trim((string)$data[$map['qc_notes']]) : null;

            // Row-level validation
            if ($nfc_uid === '') {
                $errors[] = ['line' => $lineNo, 'error' => 'nfc_uid empty'];
                continue;
            }
            if ($nfc_key_ref === '') {
                $errors[] = ['line' => $lineNo, 'error' => 'nfc_key_ref empty (and no default provided)'];
                continue;
            }
            if (!in_array($chip_family, ['NTAG424','DESFireEV3','Other'], true)) {
                $errors[] = ['line' => $lineNo, 'error' => 'chip_family invalid'];
                continue;
            }
            if (!in_array($status, ['new','qc_pass','reserved','bound','retired','revoked'], true)) {
                $errors[] = ['line' => $lineNo, 'error' => 'status invalid'];
                continue;
            }
            $ctr_seed_val = 0;
            if ($ctr_seed !== '' && $ctr_seed !== null) {
                if (ctype_digit((string)$ctr_seed)) {
                    $ctr_seed_val = (int)$ctr_seed;
                } else {
                    $errors[] = ['line' => $lineNo, 'error' => 'ctr_seed must be unsigned integer'];
                    continue;
                }
            }

            $rows[] = [
                'tenant_id'     => $tenantId,
                'nfc_uid'       => $nfc_uid,
                'nfc_key_ref'   => $nfc_key_ref,
                'chip_family'   => $chip_family,
                'status'        => $status,
                'qc_notes'      => $qc_notes,
                'ctr_seed'      => $ctr_seed_val,
                // batch/print_run left null on import
                'imported_at'   => now(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }
        fclose($fh);

        if (empty($rows) && empty($errors)) {
            return response()->json(['message' => 'No data rows found in CSV'], 400);
        }

        // Upsert (unique on tenant_id + nfc_uid)
        // On duplicate: update key_ref, chip_family, status, qc_notes, ctr_seed, updated_at
        DB::table('nfc_tags_s')->upsert(
            $rows,
            ['tenant_id','nfc_uid'],
            ['nfc_key_ref','chip_family','status','qc_notes','ctr_seed','updated_at']
        );

        return response()->json([
            'message' => 'Import complete',
            'inserted_or_updated' => count($rows),
            'errors' => $errors, // empty if none
            'hint' => 'Rows are available for reservation during batch creation.',
        ]);
    }

    /**
     * OPTIONAL: POST /nfc/keys — quick key metadata stub
     * If you already have nfc_keys_s or use KMS aliases, you can keep this minimal.
     */
    public function storeKey(Request $req)
    {
        $tenantId = $this->resolveTenantId($req);
        if (!$tenantId) return response()->json(['message' => 'Tenant not resolved'], 400);

        $data = $req->validate([
            'key_ref' => 'required|string|max:64',
            'chip_family' => 'required|in:NTAG424,DESFireEV3,Other',
            'status' => 'nullable|in:active,retired,revoked',
        ]);

        // If you have nfc_keys_s, insert metadata there; else you might just validate existence.
        // Example no-op response for now:
        return response()->json([
            'message' => 'Key reference accepted (store/validate against nfc_keys_s or KMS as per your setup).',
            'data' => [
                'tenant_id' => $tenantId,
                'key_ref' => $data['key_ref'],
                'chip_family' => $data['chip_family'],
                'status' => $data['status'] ?? 'active',
            ]
        ], 201);
    }
}
