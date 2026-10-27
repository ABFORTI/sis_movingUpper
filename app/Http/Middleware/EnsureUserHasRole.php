<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole {
    public function handle(Request $request, Closure $next, string ...$roles): Response {
        $usuario = $request->user();

        abort_unless($usuario && collect($roles)->contains(fn (string $rol) => $usuario->hasRole($rol)), 403);

        return $next($request);
    }
}
