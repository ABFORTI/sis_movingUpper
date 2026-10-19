<?php

namespace App\Http\Controllers;

use App\Services\Etiquetas\EtiquetaPrueba;
use App\Services\Etiquetas\FormatoEtiqueta;
use Illuminate\Http\Response;
use Illuminate\View\View;


class PruebaImpresionController extends Controller {
    public function __construct(private readonly EtiquetaPrueba $prueba) { }

    public function show(FormatoEtiqueta $formato): View {
        return view('impresion.prueba', [
            'formato' => $formato,
            'svg' => $this->prueba->svg(),
        ]);
    }

    public function zpl(): Response {
        return $this->texto($this->prueba->zpl());
    }

    public function configuracion(): Response {
        return $this->texto($this->prueba->configuracion());
    }

    private function texto(string $contenido): Response {
        return response($contenido, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
