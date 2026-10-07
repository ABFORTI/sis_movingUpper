<?php

namespace App\Services;

use App\Enums\TipoRegistro;
use App\Models\NumeroSerie;
use App\Models\Registro;
use App\Models\Tarima;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EscaneoService {
    public function __construct(private readonly TarimaService $tarimas)
    { }

    public function registrarSkuPadre(Tarima $tarima, array $datos, User $usuario): Registro {
        return $this->crearRegistro($tarima, TipoRegistro::Padre, $datos, $usuario);
    }

    public function registrarSkuSimple(Tarima $tarima, array $datos, User $usuario): Registro {
        return $this->crearRegistro($tarima, TipoRegistro::Simple, $datos, $usuario);
    }

    public function agregarNumeroSerie(Registro $padre, string $numeroSerie, User $usuario): NumeroSerie {
        try {
            return DB::transaction(function () use ($padre, $numeroSerie, $usuario) {
                $this->asegurarTemporal($padre->tarima, 'numero_serie');

                $existente = NumeroSerie::with('registro.tarima')->where('numero_serie', $numeroSerie)->first();
                if ($existente) {
                    $ubicacion = $existente->registro
                        ? "SKU {$existente->registro->sku} · {$existente->registro->tarima->nombre}"
                        : 'otro registro';
                    throw ValidationException::withMessages([
                        'numero_serie' => "La N/S {$numeroSerie} ya está registrada ({$ubicacion}).",
                    ]);
                }

                $ns = $padre->numerosSerie()->create([
                    'numero_serie' => $numeroSerie,
                    'user_id' => $usuario->id,
                ]);
                $this->tarimas->invalidarImpresion($padre->tarima);

                return $ns;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'numero_serie' => "La N/S {$numeroSerie} ya está registrada.",
            ]);
        }
    }

    private function crearRegistro(Tarima $tarima, TipoRegistro $tipo, array $datos, User $usuario): Registro {
        $sku = $datos['sku'];

        try {
            return DB::transaction(function () use ($tarima, $tipo, $datos, $usuario, $sku) {
                $this->asegurarTemporal($tarima, 'sku');

                if ($tarima->registros()->where('sku', $sku)->exists()) {
                    throw ValidationException::withMessages([
                        'sku' => "El SKU {$sku} ya está en la {$tarima->nombre}.",
                    ]);
                }

                $registro = $tarima->registros()->create([
                    'tipo' => $tipo,
                    'sku' => $sku,
                    'descripcion' => $datos['descripcion'] ?? null,
                    'destino_id' => $datos['destino_id'],
                    'user_id' => $usuario->id,
                ]);
                $this->tarimas->invalidarImpresion($tarima);

                return $registro;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'sku' => "El SKU {$sku} ya está en la {$tarima->nombre}.",
            ]);
        }
    }

    /**
     * Se llama dentro de la transacción de la captura: el FOR UPDATE sobre la
     * tarima la serializa con TarimaService::guardar, así que ninguna pieza entra
     * después de que se contó el rango de consecutivos.
     */
    private function asegurarTemporal(Tarima $tarima, string $campo): void {
        if (Tarima::whereKey($tarima->id)->lockForUpdate()->first()?->estaGuardada() ?? true) {
            throw ValidationException::withMessages([
                $campo => "La {$tarima->nombre} ya fue guardada y no admite más capturas.",
            ]);
        }
    }
}
