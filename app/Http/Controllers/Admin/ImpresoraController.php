<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Impresora;
use App\Services\BitacoraService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;


class ImpresoraController extends Controller {
    public function __construct(private readonly BitacoraService $bitacora) { }

    public function index(): View {
        return view('impresoras.index', ['impresoras' => Impresora::orderByDesc('activa')->orderBy('nombre')->get()]);
    }

    public function create(): View {
        return view('impresoras.form', ['impresora' => new Impresora(['activa' => true])]);
    }

    public function store(Request $request): RedirectResponse {
        $impresora = Impresora::create($this->validar($request));
        $this->bitacora->registrar('impresora.creada', $request->user(), $impresora, $impresora->only(['nombre', 'mac', 'nombre_bluetooth']));

        return redirect()->route('impresoras.index')->with('ok', "Impresora {$impresora->nombre} ({$impresora->mac}) registrada.");
    }

    public function edit(Impresora $impresora): View {
        return view('impresoras.form', ['impresora' => $impresora]);
    }

    public function update(Request $request, Impresora $impresora): RedirectResponse {
        $antes = $impresora->only(['nombre', 'mac', 'nombre_bluetooth', 'activa']);
        $impresora->update($this->validar($request, $impresora));
        $this->bitacora->registrar('impresora.actualizada', $request->user(), $impresora, [
            'antes' => $antes, 'despues' => $impresora->only(['nombre', 'mac', 'nombre_bluetooth', 'activa']),
        ]);

        return redirect()->route('impresoras.index')->with('ok', "Impresora {$impresora->nombre} actualizada.");
    }

    private function validar(Request $request, ?Impresora $impresora = null): array {
        $request->merge(['mac' => Impresora::normalizarMac($request->input('mac')) ?? $request->input('mac')]);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'mac' => ['required', 'regex:/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', Rule::unique('impresoras', 'mac')->ignore($impresora?->id)],
            'nombre_bluetooth' => ['nullable', 'string', 'max:100'],
            'ubicacion' => ['nullable', 'string', 'max:150'],
            'activa' => ['boolean'],
        ], [
            'mac.required' => 'Escribe la MAC Bluetooth de la impresora.',
            'mac.regex' => 'La MAC debe tener 12 caracteres hexadecimales, p. ej. AC:3F:A4:CD:C5:15.',
            'mac.unique' => 'Esa MAC ya está registrada.',
        ]);

        return $datos + ['activa' => $request->boolean('activa')];
    }
}
