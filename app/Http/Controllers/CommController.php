<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Comm\CommSender;
use OpenApi\Annotations as OA;

class CommController extends Controller
{
/**
 * @OA\Post(
 *   path="/comm/queue",
 *   tags={"CommController"},
 *   summary="Queue a message to be sent later",
 *   security={{"bearerAuth":{}}},
 *
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"channel"},
 *       @OA\Property(property="channel", type="string", enum={"email","sms","whatsapp","push"}, example="email"),
 *       @OA\Property(property="to_email", type="string", format="email", nullable=true, example="user@example.com"),
 *       @OA\Property(property="to_phone", type="string", maxLength=32, nullable=true, example="+919999999999"),
 *       @OA\Property(property="subject", type="string", maxLength=191, nullable=true, example="Welcome"),
 *       @OA\Property(property="body_text", type="string", nullable=true, example="Hello from tenant"),
 *       @OA\Property(property="body_html", type="string", nullable=true, example="<p>Hello from tenant</p>"),
 *       @OA\Property(
 *         property="vars_json",
 *         type="object",
 *         nullable=true,
 *         additionalProperties=true,
 *         example={"name":"Abhay","orderId":"12345"}
 *       ),
 *       @OA\Property(property="template_id", type="integer", nullable=true, example=12),
 *       @OA\Property(property="scheduled_at", type="string", format="date-time", nullable=true, example="2025-09-05T14:30:00+05:30"),
 *       @OA\Property(property="priority", type="integer", nullable=true, example=0),
 *       @OA\Property(property="idempotency_key", type="string", maxLength=64, nullable=true, example="email-12345-unique")
 *     )
 *   ),
 *
 *   @OA\Response(
 *     response=200,
 *     description="Queued",
 *     @OA\JsonContent(
 *       @OA\Property(property="queued", type="boolean", example=true),
 *       @OA\Property(property="outbox_id", type="integer", example=1024)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthenticated"),
 *   @OA\Response(response=403, description="Forbidden"),
 *   @OA\Response(
 *     response=422,
 *     description="Validation error",
 *     @OA\JsonContent(
 *       @OA\Property(property="message", type="string", example="The channel field is required."),
 *       @OA\Property(property="errors", type="object", additionalProperties=true)
 *     )
 *   )
 * )
 */
    public function enqueue($tenant, Request $req)
    {
        $payload = $req->validate([
            'channel'         => 'required|in:email,sms,whatsapp,push',
            'to_email'        => 'nullable|email',
            'to_phone'        => 'nullable|string|max:32',
            'subject'         => 'nullable|string|max:191',
            'body_text'       => 'nullable|string',
            'body_html'       => 'nullable|string',
            'vars_json'       => 'nullable|array',
            'template_id'     => 'nullable|integer',
            'scheduled_at'    => 'nullable|date',
            'priority'        => 'nullable|integer',
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        $scheduled = $payload['scheduled_at'] ?? now();
        $priority  = $payload['priority'] ?? 0;

        // Insert into tenant-local or shared outbox
        if (app('tenant.mode') === 'schema' || app('tenant.mode') === 'database') {
            $id = DB::connection('tenant')->table('t_comm_outbox')->insertGetId([
                'channel'         => $payload['channel'],
                'template_id'     => $payload['template_id'] ?? null,
                'to_email'        => $payload['to_email'] ?? null,
                'to_phone'        => $payload['to_phone'] ?? null,
                'subject'         => $payload['subject'] ?? null,
                'body_text'       => $payload['body_text'] ?? null,
                'body_html'       => $payload['body_html'] ?? null,
                'vars_json'       => isset($payload['vars_json']) ? json_encode($payload['vars_json']) : null,
                'provider_hint'   => null,
                'status'          => 'queued',
                'priority'        => $priority,
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'scheduled_at'    => $scheduled,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } else {
            $id = DB::connection('domain_shared')->table('comm_outbox_s')->insertGetId([
                'tenant_id'       => app('tenant.id'),
                'channel'         => $payload['channel'],
                'template_id'     => $payload['template_id'] ?? null,
                'to_email'        => $payload['to_email'] ?? null,
                'to_phone'        => $payload['to_phone'] ?? null,
                'subject'         => $payload['subject'] ?? null,
                'body_text'       => $payload['body_text'] ?? null,
                'body_html'       => $payload['body_html'] ?? null,
                'vars_json'       => isset($payload['vars_json']) ? json_encode($payload['vars_json']) : null,
                'provider_hint'   => null,
                'status'          => 'queued',
                'priority'        => $priority,
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'scheduled_at'    => $scheduled,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        // Insert a core dispatch row (core DB = mysql)
        DB::connection('mysql')->table('comm_dispatch_queue')->insert([
            'tenant_id'        => app('tenant.id'),
            'channel'          => $payload['channel'],
            'outbox_local_id'  => $id,
            'due_at'           => $scheduled,
            'priority'         => $priority,
            'state'            => 'queued',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return response()->json(['queued' => true, 'outbox_id' => $id]);
    }


    /**
     * @OA\Post(
     *   path="/t/{tenant}/comm/send",
     *   tags={"CommController"},
     *   security={{"sanctum":{}}},
     *   summary="Send a message immediately (tenant -> global provider fallback)",
     *   @OA\Parameter(name="tenant", in="path", required=true, @OA\Schema(type="string")),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"channel"},
     *       @OA\Property(property="channel", type="string", enum={"email","sms","push"}),
     *       @OA\Property(property="to_email", type="string", format="email"),
     *       @OA\Property(property="subject", type="string"),
     *       @OA\Property(property="body_text", type="string"),
     *       @OA\Property(property="body_html", type="string"),
     *       @OA\Property(property="to_phone", type="string"),
     *       @OA\Property(property="to_device_token", type="string"),
     *       @OA\Property(property="title", type="string"),
     *       @OA\Property(property="data", type="object")
     *     )
     *   ),
     *   @OA\Response(response=200, description="Sent"),
     *   @OA\Response(response=500, description="Provider error or misconfiguration")
     * )
     */
    public function sendNow($tenant, Request $req, CommSender $sender)
    {
        $base = $req->validate(['channel' => 'required|in:email,sms,push']);

        $payload = match ($base['channel']) {
            'email' => array_merge($base, $req->validate([
                'to_email'  => 'required|email',
                'subject'   => 'required|string|max:180',
                'body_text' => 'nullable|string',
                'body_html' => 'nullable|string',
            ])),
            'sms' => array_merge($base, $req->validate([
                'to_phone'  => 'required|string|max:32',
                'body_text' => 'required|string|max:500',
            ])),
            'push' => array_merge($base, $req->validate([
                'to_device_token' => 'required|string',
                'title'           => 'required|string|max:120',
                'body_text'       => 'required|string|max:500',
                'data'            => 'nullable|array',
            ])),
        };

        $res = $sender->sendForTenant((int) app('tenant.id'), $base['channel'], $payload);

        return response()->json([
            'ok'   => $res['ok']   ?? false,
            'used' => $res['used'] ?? null,
            'meta' => $res['meta'] ?? null,
            'err'  => $res['error'] ?? null,
        ], ($res['ok'] ?? false) ? 200 : 500);
    }

 /**
     * @OA\Post(
     *   path="/t/{tenant}/comm/test",
     *   tags={"CommController"},
     *   security={{"sanctum":{}}},
     *   summary="Quick test helper that builds a minimal payload and sends",
     *   @OA\Parameter(name="tenant", in="path", required=true, @OA\Schema(type="string")),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"channel","to"},
     *       @OA\Property(property="channel", type="string", enum={"email","sms","push"}),
     *       @OA\Property(property="to", type="string", description="Email, phone, or device token")
     *     )
     *   ),
     *   @OA\Response(response=200, description="Sent"),
     *   @OA\Response(response=500, description="Provider error or misconfiguration")
     * )
     */
    public function test($tenant, Request $req, CommSender $sender)
    {
        $req->validate(['channel' => 'required|in:email,sms,push', 'to' => 'required|string']);

        $payload = match ($req->channel) {
            'email' => ['channel' => 'email', 'to_email' => $req->to, 'subject' => 'Test', 'body_text' => 'Hello from tenant'],
            'sms'   => ['channel' => 'sms',   'to_phone' => $req->to, 'body_text' => 'SMS test from tenant'],
            'push'  => ['channel' => 'push',  'to_device_token' => $req->to, 'title' => 'Test', 'body_text' => 'Push test'],
        };

        $res = $sender->sendForTenant((int) app('tenant.id'), $req->channel, $payload);

        return response()->json([
            'ok'   => $res['ok']   ?? false,
            'used' => $res['used'] ?? null,
            'meta' => $res['meta'] ?? null,
            'err'  => $res['error'] ?? null,
        ], ($res['ok'] ?? false) ? 200 : 500);
    }
}
