<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AutomationApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('services.automation.api_key', '');

        if ($configured === '') {
            return response()->json([
                'message' => 'Automation API no configurada.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $provided = (string) $request->header('X-API-Key', '');

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json([
                'message' => 'API key inválida o ausente.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
