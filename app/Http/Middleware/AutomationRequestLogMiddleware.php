<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AutomationRequestLogMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        Log::info('automation.request', [
            'route' => $request->route()?->getName() ?? $request->path(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
        ]);

        return $response;
    }
}
