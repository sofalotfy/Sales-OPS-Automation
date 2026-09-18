<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /** Public liveness probe for the Compose healthcheck. */
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}