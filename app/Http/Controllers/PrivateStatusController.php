<?php
// app/Http/Controllers/PrivateStatusController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\BulkStatusUpdate;

class PrivateStatusController extends Controller
{
  public function preview(Request $r) {
    $r->validate([
      'target'     => 'required|in:code,device,both',
      'set_status' => 'required|in:active,in_stock,shipped,sold,returned,retired,void',
      'filter.print_run_id' => 'nullable|integer',
      'filter.product_id'   => 'nullable|integer',
      'filter.current_status_in' => 'nullable|array',
      'tokens' => 'nullable|array',
      'device_uids' => 'nullable|array',
    ]);
    $tenantId = (int) $r->user()->tenant_id;
    $conn = 'domain_shared';

    // Build selection query (codes bound to devices only)
    $q = DB::connection($conn)->table('qr_codes_s as q')
      ->where('q.tenant_id',$tenantId);

    // If you maintain a links table, join it to find only bound pairs.
    // Example: device_qr_links_s with (qr_code_id, device_id, unbound_at NULL)
    if (in_array($r->input('target'), ['device','both'])) {
      $q->join('device_qr_links_s as l','l.qr_code_id','=','q.id')
        ->join('devices_s as d','d.id','=','l.device_id')
        ->whereNull('l.unbound_at');
    }

    if ($r->filled('filter.print_run_id')) $q->where('q.print_run_id', $r->input('filter.print_run_id'));
    if ($r->filled('filter.product_id'))   $q->where('q.product_id',   $r->input('filter.product_id'));
    if ($r->filled('filter.current_status_in')) $q->whereIn('q.status', $r->input('filter.current_status_in'));

    if ($r->filled('tokens'))      $q->whereIn('q.token', $r->input('tokens'));
    if ($r->filled('device_uids')) $q->whereIn('d.device_uid', $r->input('device_uids'));

    $count = (clone $q)->count();
    $sample = (clone $q)->select('q.id','q.token','q.status')->limit(20)->get();

    return response()->json([
      'ok'=>true,
      'candidates'=>$count,
      'sample'=>$sample,
      'will_set'=>$r->input('set_status')
    ]);
  }

  public function commit(Request $r) {
    $r->validate([
      'target'     => 'required|in:code,device,both',
      'set_status' => 'required|in:active,in_stock,shipped,sold,returned,retired,void',
      'reason'     => 'nullable|string|max:160',
      'filter'     => 'nullable|array',
      'tokens'     => 'nullable|array',
      'device_uids'=> 'nullable|array',
    ]);
    $tenantId = (int) $r->user()->tenant_id;

    $job = new BulkStatusUpdate(
      tenantId: $tenantId,
      target: $r->input('target'),
      setStatus: $r->input('set_status'),
      reason: $r->input('reason'),
      filter: $r->input('filter',[]),
      tokens: $r->input('tokens',[]),
      deviceUids: $r->input('device_uids',[])
    );
    dispatch($job);
    return response()->json(['ok'=>true, 'job_id'=>$job->jobId()]);
  }

  public function jobStatus(string $id) {
    // store progress in cache/DB from the Job (simple: cache("bulkstatus:$id"))
    $state = cache()->get("bulkstatus:$id", ['processed'=>0,'total'=>0,'errors'=>[]]);
    return response()->json(['ok'=>true] + $state);
  }
}
