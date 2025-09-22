<?php
namespace App\Swagger;
use OpenApi\Annotations as OA;

/**
 * @OA\Info(title="My Laravel API", version="1.0.0", description="Docs")
 * @OA\Server(url="/api", description="API base")
 * @OA\SecurityScheme(
 *   securityScheme="bearerAuth",
 *   type="http",
 *   scheme="bearer",
 *   bearerFormat="JWT"
 * )
 */
final class OpenApi {}
