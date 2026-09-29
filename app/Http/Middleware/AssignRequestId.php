<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->validRequestId($request->header('X-Request-ID'))
            ?? (string) Str::uuid();

        Log::shareContext([
            'environment' => app()->environment(),
            'request_id' => $requestId,
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    private function validRequestId(?string $requestId): ?string
    {
        if ($requestId === null || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $requestId) !== 1) {
            return null;
        }

        return $requestId;
    }
}
