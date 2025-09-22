<?php
namespace App\Swagger;
use OpenApi\Annotations as OA;

/**
 * Expected payload for FCM push
 * @OA\Schema(
 *   schema="FcmPushMessage",
 *   type="object",
 *   required={"to_device_token"},
 *   @OA\Property(property="to_device_token", type="string", example="dP5Vb5m...abc"),
 *   @OA\Property(property="title", type="string", example="Welcome"),
 *   @OA\Property(property="body", type="string", nullable=true, example="Thanks for signing up!"),
 *   @OA\Property(property="body_text", type="string", nullable=true, example="Thanks for signing up!"),
 *   @OA\Property(property="data", type="object", nullable=true, additionalProperties=true, example={"orderId":"123"}),
 *   @OA\Property(
 *     property="provider_config",
 *     type="object",
 *     nullable=true,
 *     @OA\Property(property="server_key", type="string", example="AAAA....")
 *   )
 * )
 */
final class SchemasComm {}
