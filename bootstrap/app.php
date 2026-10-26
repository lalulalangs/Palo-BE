<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $cleanMessage = preg_replace('/\s*\(and \d+ more errors?\)$/i', '', $e->getMessage());

                return response()->json([
                    'success' => false,
                    'message' => $cleanMessage,
                    'errors' => $e->errors(),
                ], $e->status);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan.',
                ], 404);
            }
        });

        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            if (($request->is('api/*') || $request->expectsJson()) && $response instanceof JsonResponse) {
                $data = $response->getData(true);

                if (is_array($data) && $response->getStatusCode() >= 400) {
                    if (! array_key_exists('success', $data)) {
                        $data = array_merge(['success' => false], $data);
                        $response->setData($data);
                    }
                }
            }

            return $response;
        });
    })->create();
