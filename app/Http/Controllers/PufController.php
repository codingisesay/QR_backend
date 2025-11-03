<?php

namespace App\Http\Controllers;

use App\Support\ResolvesTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PufController extends Controller
{
    use ResolvesTenant;

    // POST /puf/jobs/poll
    public function pollJob(Request $req)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $c = $this->sharedConn();
        if (!Schema::connection($c)->hasTable('puf_jobs_s') ||
            !Schema::connection($c)->hasTable('puf_devices_s') ||
            !Schema::connection($c)->hasTable('qr_codes_s')) {
            return response()->json(['message'=>'PUF tables missing'], 500);
        }

        $data = $req->validate(['device_code'=>'required|string|max:64']);
        $deviceId = DB::connection($c)->table('puf_devices_s')
            ->where('tenant_id',$tenant->id)->where('code',$data['device_code'])->where('status','active')
            ->value('id');
        if (!$deviceId) return response()->json(['message'=>'Unknown/inactive device'], 403);

        return DB::connection($c)->transaction(function () use ($c, $tenant, $deviceId) {
            $job = DB::connection($c)->table('puf_jobs_s')
                ->where('tenant_id',$tenant->id)->where('status','queued')
                ->orderBy('id')->lockForUpdate()->first();
            if (!$job) return response()->json(['message'=>'no_jobs'], 204);

            DB::connection($c)->table('puf_jobs_s')->where('id',$job->id)->update([
                'status'=>'taken','taken_by_device_id'=>$deviceId,'taken_at'=>now(),'updated_at'=>now(),
            ]);

            $qr = DB::connection($c)->table('qr_codes_s')->where('id',$job->qr_code_id)
                  ->first(['id','token','puf_alg','puf_score_threshold','print_run_id','batch_id']);
            return response()->json([
                'job_id'       => (int)$job->id,
                'qr_code_id'   => (int)$qr->id,
                'token'        => $qr->token,
                'alg'          => $qr->puf_alg ?: 'ORBv1',
                'threshold'    => $qr->puf_score_threshold ?: 75.00,
                'print_run_id' => $qr->print_run_id,
                'batch_id'     => $qr->batch_id,
            ]);
        });
    }

    // POST /puf/jobs/{jobId}/submit  (multipart: image, optional fingerprint_hash/quality/meta, device_code)
    public function submitCapture(Request $req, int $jobId)
    {
        $tenant = $this->tenant($req);
        if (!$tenant?->id) return response()->json(['message'=>'Tenant not resolved'], 400);

        $c = $this->sharedConn();
        if (!Schema::connection($c)->hasTable('puf_jobs_s') ||
            !Schema::connection($c)->hasTable('puf_captures_s') ||
            !Schema::connection($c)->hasTable('puf_devices_s') ||
            !Schema::connection($c)->hasTable('qr_codes_s')) {
            return response()->json(['message'=>'PUF tables missing'], 500);
        }

        $data = $req->validate([
            'device_code'     => 'required|string|max:64',
            'image'           => 'required|file|mimes:jpg,jpeg,png|max:8192',
            'alg'             => 'nullable|string|max:40',
            'fingerprint_hash'=> 'nullable|regex:/^[0-9a-fA-F]{64}$/',
            'quality'         => 'nullable|numeric|min:0|max:100',
            'meta'            => 'nullable|json',
        ]);

        $deviceId = DB::connection($c)->table('puf_devices_s')
            ->where('tenant_id',$tenant->id)->where('code',$data['device_code'])->where('status','active')
            ->value('id');
        if (!$deviceId) return response()->json(['message'=>'Unknown/inactive device'], 403);

        $job = DB::connection($c)->table('puf_jobs_s')
            ->where('tenant_id',$tenant->id)->where('id',$jobId)->first();
        if (!$job || !in_array($job->status, ['taken','queued'])) {
            return response()->json(['message'=>'Invalid job status'], 422);
        }

        $qr = DB::connection($c)->table('qr_codes_s')->where('id',$job->qr_code_id)
            ->first(['id','token','puf_alg','puf_score_threshold']);
        if (!$qr) return response()->json(['message'=>'QR not found'], 404);

        $path = $req->file('image')->store("puf/tenant_{$tenant->id}/qr_{$qr->id}", ['disk'=>'public']);

        DB::connection($c)->transaction(function () use ($c, $tenant, $job, $deviceId, $path, $data, $qr) {
            DB::connection($c)->table('puf_captures_s')->updateOrInsert(
                ['tenant_id'=>$tenant->id, 'qr_code_id'=>$job->qr_code_id],
                [
                    'job_id'          => $job->id,
                    'device_id'       => $deviceId,
                    'image_path'      => $path,
                    'fingerprint_hash'=> $data['fingerprint_hash'] ?? null,
                    'alg'             => $data['alg'] ?? ($qr->puf_alg ?: 'ORBv1'),
                    'quality'         => $data['quality'] ?? null,
                    'meta'            => $data['meta'] ?? null,
                    'updated_at'      => now(),
                    'created_at'      => now(),
                ]
            );

            DB::connection($c)->table('qr_codes_s')->where('id',$job->qr_code_id)->update([
                'puf_id'               => "PUF-{$job->id}",
                'puf_fingerprint_hash' => $data['fingerprint_hash'] ?? null,
                'puf_alg'              => $data['alg'] ?? ($qr->puf_alg ?: 'ORBv1'),
                'updated_at'           => now(),
            ]);

            DB::connection($c)->table('puf_jobs_s')->where('id',$job->id)->update([
                'status'=>'done','done_at'=>now(),'updated_at'=>now(),'taken_by_device_id'=>$deviceId,
            ]);
        });

        return response()->json(['ok'=>true,'job_id'=>$jobId,'qr_code_id'=>$job->qr_code_id,'image_path'=>$path]);
    }
}
