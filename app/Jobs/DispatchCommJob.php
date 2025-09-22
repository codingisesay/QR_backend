<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use App\Models\Core\Tenant;
use App\Services\TenantConnector;
use App\Services\Comm\CommSender;

class DispatchCommJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(TenantConnector $connector, CommSender $sender)
    {
        $items = DB::connection('mysql')->table('comm_dispatch_queue')
            ->where('state','queued')
            ->where('due_at','<=', now())
            ->orderBy('priority','desc')->orderBy('id')->limit(20)->get();

        foreach ($items as $q) {
            DB::connection('mysql')->transaction(function () use ($q, $connector, $sender) {
                $ok = DB::connection('mysql')->table('comm_dispatch_queue')
                    ->where('id', $q->id)->where('state','queued')
                    ->update(['state'=>'picked','updated_at'=>now()]);
                if (!$ok) return;

                $tenant = Tenant::find($q->tenant_id);
                if (!$tenant || $tenant->status !== 'active') {
                    $this->failItem($q->id, 'tenant_inactive'); return;
                }
                $conn = $connector->activate($tenant);

                $isSchema = ($tenant->isolation_mode === 'schema');
                $outbox = $isSchema ? 't_comm_outbox' : 'comm_outbox_s';
                $events = $isSchema ? 't_comm_events' : 'comm_events_s';

                $o = DB::connection($conn)->table($outbox)->where('id',$q->outbox_local_id)->first();
                if (!$o || $o->status !== 'queued') { $this->completeItem($q->id); return; }

                DB::connection($conn)->table($outbox)->where('id',$o->id)->update([
                    'status'=>'sending','updated_at'=>now()
                ]);

                $msg = [
                    'to_email'=>$o->to_email ?? null,
                    'to_phone'=>$o->to_phone ?? null,
                    'subject'=>$o->subject ?? null,
                    'body_text'=>$o->body_text ?? null,
                    'body_html'=>$o->body_html ?? null,
                    'vars_json'=>isset($o->vars_json) ? json_decode($o->vars_json, true) : null,
                ];

                $res = $sender->sendForTenant($tenant->id, $q->channel, $msg);

                if ($res['ok']) {
                    DB::connection($conn)->table($outbox)->where('id',$o->id)->update([
                        'status'=>'sent','sent_at'=>now(),'updated_at'=>now()
                    ]);
                    DB::connection($conn)->table($events)->insert([
                        'outbox_id'=>$o->id,
                        'provider_msg_id'=>$res['provider_msg_id'] ?? null,
                        'event_type'=>'sent','event_at'=>now(),'meta_json'=>json_encode([])
                    ]);
                    $this->completeItem($q->id);
                } else {
                    DB::connection($conn)->table($outbox)->where('id',$o->id)->update([
                        'status'=>'failed','last_error'=>substr($res['error'] ?? 'send_failed',0,255),'updated_at'=>now()
                    ]);
                    $this->failItem($q->id, $res['error'] ?? 'send_failed');
                }
            });
        }
    }

    private function completeItem($id){
        DB::connection('mysql')->table('comm_dispatch_queue')->where('id',$id)->update(['state'=>'done','updated_at'=>now()]);
    }
    private function failItem($id, $err){
        DB::connection('mysql')->table('comm_dispatch_queue')->where('id',$id)->update([
            'state'=>'queued','attempts'=>DB::raw('attempts+1'),'last_error'=>substr($err,0,255),'updated_at'=>now()
        ]);
    }
}
