<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege /api/automation/* con clave service-to-service (no JWT de usuarios).
 *
 * Configurar en .env: AUTOMATION_API_KEY=...
 * Enviar en cada petición: header X-API-Key: <clave>
 * Alternativa compatible con n8n: Authorization: Bearer <clave>
 */
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

        $provided = $this->resolveProvidedKey($request);

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json([
                'message' => 'API key inválida o ausente.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    private function resolveProvidedKey(Request $request): string
    {
        $fromHeader = trim((string) $request->header('X-API-Key', ''));
        if ($fromHeader !== '') {
            return $fromHeader;
        }

        $authorization = trim((string) $request->header('Authorization', ''));
        if (str_starts_with(strtolower($authorization), 'bearer ')) {
            return trim(substr($authorization, 7));
        }

        return '';
    }
}
