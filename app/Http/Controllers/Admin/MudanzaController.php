<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\MudanzaRequest;
use App\Models\Almacen;
use App\Models\Mudanza;
use App\Services\MudanzaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administrador: todo el ciclo de vida. Auditor: ver, crear y activar
 * (MudanzaRequest y MudanzaService::puede aplican la regla en el servidor).
 */
class MudanzaController extends Controller {
    public function __construct(private readonly MudanzaService $mudanzas) { }

    public function index(): View {
        return view('mudanzas.index', [
            'mudanzas' => Mudanza::with(['movimientos.origen', 'movimientos.destino'])->withCount([
                'tarimas',
                'tarimas as tarimas_guardadas_count' => fn ($q) => $q->guardadas()->vigentes(),
            ])->orderByDesc('id')->get(),
            'servicio' => $this->mudanzas,
        ]);
    }

    public function create(): View {
        return $this->formulario(new Mudanza());
    }

    public function store(MudanzaRequest $request): RedirectResponse {
        $mudanza = $this->mudanzas->crear($request->datos(), $request->movimientos(), $request->user());

        return redirect()->route('mudanzas.index')->with('ok', "{$mudanza->folio} creada como borrador. Actívala para empezar a capturar.");
    }

    public function edit(Mudanza $mudanza): View {
        return $this->formulario($mudanza->load(['movimientos.origen', 'movimientos.destino']));
    }

    public function update(MudanzaRequest $request, Mudanza $mudanza): RedirectResponse {
        $this->mudanzas->actualizar($mudanza, $request->datos(), $request->movimientos(), $request->user());

        return redirect()->route('mudanzas.index')->with('ok', "{$mudanza->folio} actualizada.");
    }

    public function estado(Request $request, Mudanza $mudanza): RedirectResponse {
        $datos = $request->validate([
            'accion' => ['required', Rule::in(array_keys(MudanzaService::TRANSICIONES))],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $this->mudanzas->transicionar($mudanza, $datos['accion'], $request->user(), $datos['motivo'] ?? null);

        return redirect()->route('mudanzas.index')->with('ok', "{$mudanza->folio}: {$mudanza->estado_nombre}.");
    }

    private function formulario(Mudanza $mudanza): View {
        return view('mudanzas.form', [
            'mudanza' => $mudanza,
            'almacenes' => Almacen::orderBy('codigo')->get(['codigo', 'nombre']),
        ]);
    }
}
