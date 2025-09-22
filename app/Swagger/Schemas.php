<?php
// app/Swagger/Schemas.php
namespace App\Swagger;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="Product",
 *   type="object",
 *   required={"id","name","price"},
 *   @OA\Property(property="id", type="integer", example=12),
 *   @OA\Property(property="name", type="string", example="Watch"),
 *   @OA\Property(property="price", type="number", format="float", example=1999.99),
 *   @OA\Property(property="description", type="string", nullable=true, example="Steel strap"),
 *   @OA\Property(property="created_at", type="string", format="date-time"),
 *   @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class Schemas {}
