<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

abstract class ApiController extends Controller
{
    protected function ok(mixed $data, int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json(['data' => $data] + $extra, $status);
    }

    protected function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json(['error' => [
            'code' => $code,
            'message' => $message,
            'details' => $details ?: (object) [],
            'requestId' => request()->attributes->get('request_id', 'req_'.str()->random(20)),
        ]], $status);
    }
}
