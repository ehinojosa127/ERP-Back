<?php

use App\Exceptions\Billing\BillingException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'automation.api_key' => \App\Http\Middleware\AutomationApiKeyMiddleware::class,
        ]);

        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
                ? null
                : '/login',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'No autenticado.',
            ], Response::HTTP_UNAUTHORIZED);
        });

        $exceptions->render(function (BillingException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $payload = ['message' => $exception->getMessage()];
            if ($exception->errors() !== []) {
                $payload['errors'] = $exception->errors();
            }

            return response()->json($payload, $exception->status());
        });

        /**
         * Evita filtrar SQL técnico al frontend. Preferible validar en servicios
         * antes de borrar; esto es la red de seguridad.
         */
        $exceptions->render(function (QueryException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            $message = $exception->getMessage();

            if (
                $sqlState === '23503'
                || $driverCode === 1451
                || str_contains($message, '1451')
            ) {
                return response()->json([
                    'message' => 'No se puede eliminar el registro porque tiene información relacionada en el sistema.',
                ], Response::HTTP_CONFLICT);
            }

            if ($driverCode === 1452 || str_contains($message, '1452')) {
                return response()->json([
                    'message' => 'No se puede guardar: hay una referencia a un registro inexistente.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (
                $sqlState === '23505'
                || $driverCode === 1062
                || str_contains($message, '1062')
                || str_contains($message, 'Duplicate entry')
                || str_contains($message, 'duplicate key')
            ) {
                return response()->json([
                    'message' => 'Ya existe un registro con esos datos.',
                ], Response::HTTP_CONFLICT);
            }

            return response()->json([
                'message' => 'No se pudo completar la operación en la base de datos.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
