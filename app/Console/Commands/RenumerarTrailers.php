<?php

namespace App\Console\Commands;

use App\Models\Embarque;
use App\Models\Mudanza;
use App\Models\User;
use App\Services\BitacoraService;
use App\Services\EmbarqueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;


class RenumerarTrailers extends Command {
    protected $signature = 'trailers:renumerar
        {--eliminar=* : Folio de un tráiler vacío a eliminar antes de renumerar (se puede repetir)}
        {--mudanza= : Folio de la mudanza (si hay más de una)}
        {--usuario= : Correo del administrador que queda registrado en la bitácora}
        {--simular : Solo muestra lo que cambiaría}';

    protected $description = 'Elimina tráileres vacíos y renumera los folios TRL de la mudanza (con --simular solo informa)';

    public function handle(BitacoraService $bitacora): int {
        $mudanza = $this->mudanza();
        if (! $mudanza) {
            return self::FAILURE;
        }
        $trailers = Embarque::where('mudanza_id', $mudanza->id)->withCount('cargas')->orderBy('id')->get();

        $eliminar = collect($this->option('eliminar'))->map(fn ($f) => strtoupper(trim($f)))->unique();
        foreach ($eliminar as $folio) {
            $trailer = $trailers->firstWhere('folio', $folio);
            $problema = match (true) {
                $trailer === null => 'no existe en esta mudanza',
                $trailer->cargas_count > 0 => "tiene {$trailer->cargas_count} piezas cargadas",
                ! $trailer->enCarga() => "está {$trailer->estado_nombre}",
                default => null,
            };
            if ($problema) {
                $this->error("{$folio} no se puede eliminar: {$problema}. Solo se eliminan tráileres en carga y sin piezas.");

                return self::FAILURE;
            }
        }

        $quedan = $trailers->reject(fn (Embarque $e) => $eliminar->contains($e->folio))->values();
        $cambios = $quedan->mapWithKeys(fn (Embarque $e, int $i) => [$e->folio => sprintf('TRL-%04d', $i + 1)])
            ->filter(fn (string $nuevo, string $antes) => $nuevo !== $antes);

        $this->table(['Folio actual', 'Folio nuevo', 'Piezas'], $trailers->map(fn (Embarque $e) => [
            $e->folio, $eliminar->contains($e->folio) ? '(se elimina)' : ($cambios[$e->folio] ?? 'sin cambio'), $e->cargas_count,
        ])->all());
        $this->line("El siguiente tráiler será TRL-".sprintf('%04d', $quedan->count() + 1).'.');

        if ($eliminar->isEmpty() && $cambios->isEmpty()) {
            $this->info('Los folios ya están en secuencia: no hay nada que cambiar.');

            return self::SUCCESS;
        }
        if ($this->option('simular')) {
            $this->line('Simulación: no se cambió nada. Ejecuta sin --simular para aplicarlo.');

            return self::SUCCESS;
        }

        $usuario = User::where('email', (string) $this->option('usuario'))->first();
        if (! $usuario?->isAdmin()) {
            $this->error('Indica con --usuario el correo de un administrador.');

            return self::FAILURE;
        }
        if (! $this->confirm('¿Aplicar estos cambios? No se puede deshacer.')) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($mudanza, $trailers, $eliminar, $quedan, $cambios, $usuario, $bitacora) {
            Embarque::whereKey($trailers->modelKeys())->lockForUpdate()->get();
            Embarque::whereKey($trailers->whereIn('folio', $eliminar->all())->modelKeys())->delete();

            // En dos pasos para no chocar con el folio único mientras se recorren.
            foreach ($quedan as $e) {
                DB::table('embarques')->where('id', $e->id)->update(['folio' => "TMP-{$e->id}"]);
            }
            foreach ($quedan as $i => $e) {
                DB::table('embarques')->where('id', $e->id)->update(['folio' => sprintf('TRL-%04d', $i + 1)]);
            }
            DB::table('contadores')->updateOrInsert(['clave' => EmbarqueService::claveFolio($mudanza)], ['valor' => $quedan->count(), 'updated_at' => now()]);

            $bitacora->registrar('embarque.renumerado', $usuario, null, [
                'eliminados' => $eliminar->values()->all(), 'cambios' => $cambios->all(),
            ], 'Tráileres renumerados (trailers:renumerar)', $mudanza);
        });

        $this->info('Listo: '.$eliminar->count().' eliminado(s) y '.$cambios->count().' folio(s) renumerado(s).');

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
