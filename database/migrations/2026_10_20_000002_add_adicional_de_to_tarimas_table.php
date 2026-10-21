<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Embarque "adicional": uno guardado cuyas piezas se movieron todas a un
     * mismo embarque se llama como ése ("E-012" → "E-011_adicional"). Se
     * llenan los que ya están así.
     */
    public function up(): void
    {
        Schema::table('tarimas', function (Blueprint $table) {
            $table->foreignId('adicional_de_id')->nullable()->after('numero')->constrained('tarimas')->nullOnDelete();
        });

        $vacias = DB::table('tarimas')->whereNotNull('guardada_at')->whereNull('anulada_at')
            ->whereNotExists(fn ($q) => $q->from('partidas')->whereColumn('partidas.tarima_id', 'tarimas.id')->where('estado', 'en_tarima'))
            ->pluck('id');

        foreach ($vacias as $id) {
            $destinos = DB::table('movimientos_partida')
                ->join('partidas', 'partidas.id', '=', 'movimientos_partida.partida_id')
                ->where('movimientos_partida.tipo', 'cambio_tarima')
                ->where('movimientos_partida.tarima_origen_id', $id)
                ->where('partidas.estado', 'en_tarima')
                ->whereColumn('partidas.tarima_id', 'movimientos_partida.tarima_destino_id')
                ->distinct()->pluck('movimientos_partida.tarima_destino_id');

            if ($destinos->count() === 1) {
                DB::table('tarimas')->where('id', $id)->update(['adicional_de_id' => $destinos->first()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tarimas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adicional_de_id');
        });
    }
};
