<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;


class DiagnosticoImpresionController extends Controller {
    public function __invoke(Request $request): Response {
        $datos = $request->validate([
            'metodo' => ['required', 'string', 'in:browserprint,bluetooth,navegador,ninguno'],
            'etapa' => ['required', 'string', 'max:40'],
            'error' => ['nullable', 'string', 'max:120'],
            'detalle' => ['nullable', 'string', 'max:500'],
            'tarima' => ['nullable', 'uuid'],
        ]);

        Log::channel('impresion')->warning('Falla de impresión', $datos + [
            'usuario' => $request->user()->id,
            'ip' => $request->ip(),
            'navegador' => mb_substr((string) $request->userAgent(), 0, 250),
        ]);

        return response()->noContent();
    }
}
