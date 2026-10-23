<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',  
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only: invitados nunca redirigen (no existe ruta `login`);
        // el AuthenticationException resultante se responde como 401 JSON abajo.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API-only: invitados siempre reciben 401 JSON (nunca redirect a
        // una ruta `login` que no existe).
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            return response()->json(['message' => 'No autenticado.'], 401);
        });
    })->create();
