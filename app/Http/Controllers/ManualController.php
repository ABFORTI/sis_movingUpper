<?php

namespace App\Http\Controllers;

use App\Enums\VisibilidadManual;
use App\Http\Requests\ManualRequest;
use App\Models\Manual;
use App\Services\ManualService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManualController extends Controller {
    public function __construct(private readonly ManualService $manuales)
    { }

    public function index(Request $request): View {
        return view('manuales.index', [
            'manuales' => Manual::visiblesPara($request->user())->orderBy('nombre')->get(),
            'esAdmin' => $request->user()->isAdmin(),
        ]);
    }

    public function create(): View {
        return view('manuales.form', [
            'manual' => new Manual(['visible_para' => VisibilidadManual::OperadorYAuditor]),
        ]);
    }

    public function store(ManualRequest $request): RedirectResponse {
        $manual = $this->manuales->crear($request->datos(), $request->file('archivo'), $request->user());

        return redirect()->route('manuales.index')->with('ok', "Manual «{$manual->nombre}» publicado.");
    }

    public function edit(Manual $manual): View {
        return view('manuales.form', ['manual' => $manual]);
    }

    public function update(ManualRequest $request, Manual $manual): RedirectResponse {
        $reemplazo = $request->file('archivo');
        $this->manuales->actualizar($manual, $request->datos(), $reemplazo, $request->user());

        return redirect()->route('manuales.index')
            ->with('ok', $reemplazo ? "Manual «{$manual->nombre}» actualizado con el nuevo PDF." : "Manual «{$manual->nombre}» actualizado.");
    }

    public function destroy(Manual $manual): RedirectResponse {
        $nombre = $manual->nombre;
        $this->manuales->eliminar($manual);

        return redirect()->route('manuales.index')->with('warn', "Manual «{$nombre}» eliminado.");
    }

    /** Abre el PDF en el visor del navegador. La ruta ya pasó por can:view. */
    public function ver(Manual $manual): StreamedResponse {
        return $this->entregar($manual, 'inline');
    }

    public function descargar(Manual $manual): StreamedResponse {
        return $this->entregar($manual, 'attachment');
    }

    private function entregar(Manual $manual, string $disposicion): StreamedResponse {
        abort_unless($this->manuales->existeArchivo($manual), 404);

        return $this->manuales->disco()->response($manual->archivo, $manual->nombreDescarga(), [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $disposicion);
    }
}
