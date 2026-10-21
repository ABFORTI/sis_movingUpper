<?php

namespace App\Console\Commands;

use App\Models\Tarima;
use App\Models\User;
use App\Services\TarimaService;
use Illuminate\Console\Command;



class CompletarPartidas extends Command {
    protected $signature = 'tarimas:completar-partidas
        {--reparar : Crea las partidas que faltan}
        {--usuario= : Correo del administrador que queda registrado en la bitácora}';

    protected $description = 'Detecta (y repara con --reparar) embarques guardados cuyas piezas no tienen consecutivo';

    public function handle(TarimaService $tarimas): int {
        $afectadas = Tarima::with('mudanza')->guardadas()->vigentes()->orderBy('mudanza_id')->orderBy('numero')->get()
            ->map(fn (Tarima $t) => [$t, $tarimas->piezasSinPartida($t)])
            ->filter(fn (array $f) => $f[1] > 0);

        if ($afectadas->isEmpty()) {
            $this->info('Todos los embarques guardados tienen sus piezas con consecutivo.');

            return self::SUCCESS;
        }

        $this->table(['Embarque', 'Piezas sin consecutivo'], $afectadas->map(fn (array $f) => [$f[0]->identificador, $f[1]])->all());
        if (! $this->option('reparar')) {
            $this->line('Ejecuta de nuevo con --reparar --usuario=<correo del administrador> para completarlas.');

            return self::SUCCESS;
        }

        $usuario = User::where('email', (string) $this->option('usuario'))->first();
        if (! $usuario?->isAdmin()) {
            $this->error('Indica con --usuario el correo de un administrador.');

            return self::FAILURE;
        }

        foreach ($afectadas as [$tarima]) {
            $n = $tarimas->completarPartidas($tarima, $usuario);
            $this->info("{$tarima->identificador}: {$n} piezas numeradas desde el consecutivo ".$tarima->partidas()->min('consecutivo').'.');
        }

        return self::SUCCESS;
    }
}
