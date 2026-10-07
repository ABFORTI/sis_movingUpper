<?php

namespace App\Services;

use App\Enums\TipoRegistro;
use App\Models\NumeroSerie;
use App\Models\Registro;
use App\Models\Tarima;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class TarimaService {
    /** Fila de `contadores` con el último consecutivo entregado. */
    public const CONTADOR_CONSECUTIVO = 'consecutivo_tarimas';

    public function crear(User $usuario): Tarima {
        return Tarima::create(['created_by' => $usuario->id]);
    }

    public function vaciar(Tarima $tarima, User $usuario): void {
        DB::transaction(function () use ($tarima, $usuario) {
            if ($usuario->isAdmin()) {
                Registro::withTrashed()->where('tarima_id', $tarima->id)->forceDelete();

                return;
            }

            $ids = $tarima->registros()->pluck('id');
            $ahora = now();

            NumeroSerie::whereIn('registro_id', $ids)
                ->update(['deleted_at' => $ahora, 'deleted_by' => $usuario->id]);
            Registro::whereIn('id', $ids)
                ->update(['deleted_at' => $ahora, 'deleted_by' => $usuario->id]);
        });

        $this->invalidarImpresion($tarima);
        $tarima->unsetRelation('registros');
    }

    public function marcarImpresa(Tarima $tarima): void {
        if ($tarima->estaGuardada()) {
            return;
        }

        abort_if($tarima->cargarDetalle()->registros->isEmpty(), 422, 'La tarima no tiene productos.');

        Tarima::whereKey($tarima->id)->whereNull('impresa_at')->update(['impresa_at' => now()]);
        $tarima->refresh();
    }

    public function invalidarImpresion(Tarima $tarima): void {
        Tarima::whereKey($tarima->id)->whereNull('guardada_at')->update(['impresa_at' => null]);
        $tarima->impresa_at = null;
    }

    /**
     * Pasa la tarima de temporal a guardada y le reserva su rango de consecutivos.
     * Es idempotente y atómico: la fila de la tarima se bloquea (FOR UPDATE), así
     * que dos solicitudes a la vez (doble clic, recarga, reintento) la guardan una
     * sola vez, y el escaneo no puede agregar piezas mientras se cuenta el rango.
     *
     * @return bool true si se guardó ahora; false si ya estaba guardada.
     */
    public function guardar(Tarima $tarima, User $usuario): bool {
        if ($tarima->estaGuardada()) {
            return false;
        }

        if (! $tarima->estaImpresa()) {
            throw ValidationException::withMessages([
                'tarima' => "Primero imprime la etiqueta de la {$tarima->nombre}.",
            ]);
        }

        $guardadaAhora = DB::transaction(function () use ($tarima, $usuario) {
            $actual = Tarima::whereKey($tarima->id)->lockForUpdate()->firstOrFail();

            if ($actual->estaGuardada() || ! $actual->estaImpresa()) {
                return false;
            }

            Tarima::whereKey($actual->id)->update([
                'guardada_at' => now(),
                'guardada_by' => $usuario->id,
                'consecutivo_inicial' => $this->reservarConsecutivos($this->filas($actual)->count()),
            ]);

            return true;
        });

        $tarima->refresh();

        if (! $guardadaAhora && ! $tarima->estaGuardada()) {
            throw ValidationException::withMessages([
                'tarima' => "El contenido de la {$tarima->nombre} cambió. Vuelve a imprimir la etiqueta.",
            ]);
        }

        return $guardadaAhora;
    }

    /**
     * Reserva $cantidad consecutivos y devuelve el primero. Debe llamarse dentro de
     * una transacción: el FOR UPDATE sobre la fila del contador hace que los
     * guardados simultáneos esperen su turno y reciban rangos contiguos sin traslape.
     */
    private function reservarConsecutivos(int $cantidad): int {
        DB::table('contadores')->insertOrIgnore([
            'clave' => self::CONTADOR_CONSECUTIVO, 'valor' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ultimo = (int) DB::table('contadores')
            ->where('clave', self::CONTADOR_CONSECUTIVO)
            ->lockForUpdate()
            ->value('valor');

        // Una tarima guardada siempre tiene al menos una fila; max() evita que un
        // rango vacío repita el inicial de la siguiente tarima.
        DB::table('contadores')
            ->where('clave', self::CONTADOR_CONSECUTIVO)
            ->update(['valor' => $ultimo + max(1, $cantidad), 'updated_at' => now()]);

        return $ultimo + 1;
    }

    public function fechaEtiqueta(Tarima $tarima): CarbonInterface {
        return $tarima->impresa_at ?? now();
    }

    public function eliminar(Tarima $tarima): void {
        $tarima->delete();
    }

    /**
     * Aplana la tarima a una fila por pieza.
     *
     * @return Collection<int, array{sku: string, ns: string, descripcion: string, destino: string, fecha: string, hora: string}>
     */
    public function filas(Tarima $tarima): Collection {
        $tarima->cargarDetalle();

        return $tarima->registros->flatMap(function (Registro $r) {
            $base = [
                'sku' => $r->sku,
                'descripcion' => $r->descripcion_mostrada,
                'destino' => $r->destino->codigo,
                'fecha' => $r->created_at->format('Y-m-d'),
            ];

            if ($r->tipo === TipoRegistro::Simple) {
                return [$base + ['ns' => '—', 'hora' => $r->created_at->format('H:i:s')]];
            }

            if ($r->numerosSerie->isEmpty()) {
                return [$base + ['ns' => '(sin N/S)', 'hora' => $r->created_at->format('H:i:s')]];
            }

            return $r->numerosSerie->map(fn (NumeroSerie $ns) => $base + [
                'ns' => $ns->numero_serie,
                'hora' => $ns->created_at->format('H:i:s'),
            ]);
        })->values();
    }

    public function destino(Tarima $tarima): string {
        return $tarima->cargarDetalle()->registros->first()?->destino->codigo ?? 'N/A';
    }

    public function codigoBarras(Tarima $tarima): string {
        return 'TAR-'.$tarima->id.'|'.$this->fechaEtiqueta($tarima)->format('Ymd');
    }

    public function urlDetalle(Tarima $tarima): string {
        return URL::signedRoute('tarimas.publico', ['tarima' => $tarima->uuid]);
    }
}
