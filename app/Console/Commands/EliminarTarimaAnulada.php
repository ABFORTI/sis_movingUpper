<?php

namespace App\Console\Commands;

use App\Models\Mudanza;
use App\Models\Tarima;
use App\Models\User;
use App\Services\BitacoraService;
use App\Services\TarimaService;
use App\Support\Contador;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;


class EliminarTarimaAnulada extends Command {
    protected $signature = 'tarimas:eliminar-anulada
        {embarque : Número del embarque, p. ej. E-001}
        {--mudanza= : Folio de la mudanza (si hay más de una)}
        {--usuario= : Correo del administrador que queda registrado en la bitácora}
        {--simular : Solo muestra lo que se eliminaría}';

    protected $description = 'Elimina por completo un embarque anulado (con --simular solo informa)';

    public function handle(BitacoraService $bitacora): int {
        $mudanza = $this->mudanza();
        if (! $mudanza) {
            return self::FAILURE;
        }
        $numero = (int) preg_replace('/\D/', '', preg_replace('/^.*[ET]/i', '', (string) $this->argument('embarque')));
        $tarima = Tarima::where('mudanza_id', $mudanza->id)->where('numero', $numero)->first();
        if (! $tarima) {
            $this->error("No existe el embarque {$this->argument('embarque')} en {$mudanza->folio}.");

            return self::FAILURE;
        }
        if (! $tarima->estaAnulada()) {
            $this->error("{$tarima->identificador} no está anulado: solo se eliminan embarques anulados.");

            return self::FAILURE;
        }

        $partidas = DB::table('partidas')->where('tarima_id', $tarima->id)->pluck('id');
        $registros = DB::table('registros')->where('tarima_id', $tarima->id)->pluck('id');
        $series = DB::table('numeros_serie')->whereIn('registro_id', $registros)->pluck('id');
        $incidencias = DB::table('incidencias')->where(fn ($q) => $q->where('tarima_id', $tarima->id)->orWhereIn('numero_serie_id', $series))->pluck('folio', 'id');

        $enTrailer = DB::table('embarque_partidas')->where(fn ($q) => $q->where('tarima_id', $tarima->id)->orWhereIn('partida_id', $partidas))->count();
        $enReporte = DB::table('entrega_partidas')->whereIn('partida_id', $partidas)->count();
        if ($enTrailer > 0 || $enReporte > 0) {
            $this->error("{$tarima->identificador} no se puede eliminar: {$enTrailer} piezas cargadas en tráiler y {$enReporte} en Reportes Diarios ya generados.");

            return self::FAILURE;
        }

        $this->table(['Embarque', 'Piezas', 'N/S', 'Incidencias'], [[
            $tarima->identificador, $partidas->count(), $series->count(), $incidencias->join(', ') ?: '—',
        ]]);
        if ($this->option('simular')) {
            $this->line('Simulación: no se cambió nada. Ejecuta sin --simular para eliminarlo.');

            return self::SUCCESS;
        }

        $usuario = User::where('email', (string) $this->option('usuario'))->first();
        if (! $usuario?->isAdmin()) {
            $this->error('Indica con --usuario el correo de un administrador.');

            return self::FAILURE;
        }
        if (! $this->confirm("¿Eliminar {$tarima->identificador} por completo? No se puede deshacer.")) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        $reutiliza = DB::transaction(function () use ($tarima, $partidas, $registros, $series, $incidencias, $usuario, $bitacora, $mudanza) {
            DB::table('incidencia_partida')->where(fn ($q) => $q->whereIn('incidencia_id', $incidencias->keys())->orWhereIn('partida_id', $partidas))->delete();
            DB::table('incidencia_eventos')->whereIn('incidencia_id', $incidencias->keys())->delete();
            DB::table('incidencias')->whereIn('id', $incidencias->keys())->delete();
            DB::table('movimientos_partida')->where(fn ($q) => $q->whereIn('partida_id', $partidas)
                ->orWhere('tarima_origen_id', $tarima->id)->orWhere('tarima_destino_id', $tarima->id))->delete();
            DB::table('partidas')->whereIn('id', $partidas)->delete();
            DB::table('numeros_serie')->whereIn('id', $series)->delete();
            DB::table('registros')->whereIn('id', $registros)->delete();
            DB::table('impresiones')->where('tarima_id', $tarima->id)->delete();
            DB::table('tarimas')->where('id', $tarima->id)->delete();

            $bitacora->registrar(BitacoraService::TARIMA_ELIMINADA, $usuario, $tarima, [
                'tarima' => $tarima->identificador, 'piezas' => $partidas->count(), 'incidencias' => $incidencias->values()->all(),
            ], 'Embarque anulado eliminado (tarimas:eliminar-anulada)');

            return Contador::liberarUltimo(TarimaService::claveNumeracion($mudanza), $tarima->numero);
        });

        $this->info("{$tarima->identificador} eliminado.".($reutiliza ? ' Su número se asignará al siguiente embarque.' : ' Su número no se reutiliza.'));

        return self::SUCCESS;
    }

    private function mudanza(): ?Mudanza {
        $folio = $this->option('mudanza');
        $mudanzas = $folio ? Mudanza::where('folio', $folio)->get() : Mudanza::all();
        if ($mudanzas->count() !== 1) {
            $this->error($folio ? "No existe la mudanza {$folio}." : 'Hay varias mudanzas: indica cuál con --mudanza=MUD-0001.');

            return null;
        }

        return $mudanzas->first();
    }
}
