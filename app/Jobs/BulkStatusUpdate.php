<?php
// app/Jobs/BulkStatusUpdate.php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BulkStatusUpdate implements ShouldQueue
{
  use InteractsWithQueue, Queueable, SerializesModels;

  public function __construct(
    public int $tenantId,
    public string $target,           // 'code'|'device'|'both'
    public string $setStatus,
    public ?string $reason,
    public array $filter = [],
    public array $tokens = [],
    public array $deviceUids = [],
  ) {}

  public function jobId(): string {
    // a stable ID to poll
    return $this->job?->getJobId() ?? Str::uuid()->toString();
  }

  public function handle(): void {
    $conn = 'domain_shared';
    // Build selection query like preview
    $q = DB::connection($conn)->table('qr_codes_s as q')->where('q.tenant_id',$this->tenantId);

    $joinDevices = in_array($this->target,['device','both']);
    if ($joinDevices) {
      $q->join('device_qr_links_s as l','l.qr_code_id','=','q.id')
        ->join('devices_s as d','d.id','=','l.device_id')
        ->whereNull('l.unbound_at');
    }

    if (!empty($this->filter['print_run_id'])) $q->where('q.print_run_id',$this->filter['print_run_id']);
    if (!empty($this->filter['product_id']))   $q->where('q.product_id',$this->filter['product_id']);
    if (!empty($this->filter['current_status_in'])) $q->whereIn('q.status',$this->filter['current_status_in']);
    if (!empty($this->tokens))      $q->whereIn('q.token',$this->tokens);
    if (!empty($this->deviceUids))  $q->whereIn('d.device_uid',$this->deviceUids);

    $ids = $q->select('q.id','q.status', $joinDevices?'d.id as device_id':DB::raw('NULL as device_id'))->pluck('id')->toArray();

    $total = count($ids); $processed = 0; $errors = [];
    cache()->put("bulkstatus:{$this->jobId()}", compact('processed','total','errors'), 3600);

    // Chunk updates
    foreach (array_chunk($ids, 500) as $chunk) {
      DB::connection($conn)->transaction(function() use ($conn,$chunk,&$processed,&$errors) {
        $codes = DB::connection($conn)->table('qr_codes_s')->whereIn('id',$chunk)->lockForUpdate()->get();
        foreach ($codes as $code) {
          // simple transition guard: only allow forward movement from 'bound'/'active'
          if (!$this->allowCodeTransition($code->status, $this->setStatus)) {
            $errors[] = ['qr_code_id'=>$code->id,'from'=>$code->status,'to'=>$this->setStatus,'error'=>'not_allowed'];
            continue;
          }
          // Update qr status
          DB::connection($conn)->table('qr_codes_s')->where('id',$code->id)->update(['status'=>$this->setStatus]);

          DB::connection($conn)->table('qr_status_history_s')->insert([
            'tenant_id'=>$this->tenantId,
            'qr_code_id'=>$code->id,
            'old_status'=>$code->status,
            'new_status'=>$this->setStatus,
            'reason'=>$this->reason,
            'actor_user_id'=>auth()->id() ?? null,
            'at'=>now(),
            'meta_json'=>null
          ]);

          if (in_array($this->target,['device','both'])) {
            // find current device via link
            $link = DB::connection($conn)->table('device_qr_links_s')
              ->where('qr_code_id',$code->id)->whereNull('unbound_at')->first();
            if ($link) {
              $device = DB::connection($conn)->table('devices_s')->where('id',$link->device_id)->lockForUpdate()->first();
              if ($device && $this->allowDeviceTransition($device->status, $this->setStatus)) {
                DB::connection($conn)->table('devices_s')->where('id',$device->id)->update(['status'=>$this->mapDeviceStatus($this->setStatus)]);
                DB::connection($conn)->table('device_status_history_s')->insert([
                  'tenant_id'=>$this->tenantId,
                  'device_id'=>$device->id,
                  'old_status'=>$device->status,
                  'new_status'=>$this->mapDeviceStatus($this->setStatus),
                  'reason'=>$this->reason,
                  'actor_user_id'=>auth()->id() ?? null,
                  'at'=>now(),
                  'meta_json'=>null
                ]);
              }
            }
          }
          $processed++;
        }
      });
      cache()->put("bulkstatus:{$this->jobId()}", compact('processed','total','errors'), 3600);
    }
    cache()->put("bulkstatus:{$this->jobId()}", compact('processed','total','errors'), 3600);
  }

  private function allowCodeTransition(string $from, string $to): bool {
    $order = ['issued','bound','active','in_stock','shipped','sold','returned','retired','void'];
    return array_search($to,$order) >= array_search($from,$order);
  }
  private function allowDeviceTransition(string $from, string $to): bool {
    $mapToDevice = $this->mapDeviceStatus($to);
    $order = ['unbound','bound','active','in_stock','shipped','sold','returned','retired'];
    return array_search($mapToDevice,$order) >= array_search($from,$order);
  }
  private function mapDeviceStatus(string $codeStatus): string {
    return match($codeStatus) {
      'issued'   => 'unbound',
      'bound'    => 'bound',
      'active'   => 'active',
      'in_stock' => 'in_stock',
      'shipped'  => 'shipped',
      'sold'     => 'sold',
      'returned' => 'returned',
      'retired','void' => 'retired',
      default    => 'bound',
    };
  }
}
