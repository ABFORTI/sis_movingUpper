<?php

namespace App\KpiApi\Http;

use App\KpiApi\KpiApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForzarRespuestaJson {
    public function handle(Request $request, Closure $next): Response
    {
        if (KpiApi::atiende($request)) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
