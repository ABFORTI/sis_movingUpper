<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de impresiones confirmadas: una fila cada vez que el usuario confirma
 * que la etiqueta salió bien (TarimaController::marcarImpresa). tarimas.impresa_at
 * solo guarda la primera confirmación del contenido actual y se borra si el
 * contenido cambia, así que no sirve para contar etiquetas ni reimpresiones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impresiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarima_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Etiquetas físicas de la impresión; NULL en las filas históricas, donde no se conoce.
            $table->unsignedSmallInteger('etiquetas')->nullable();
            $table->boolean('es_reimpresion')->default(false);
            $table->timestamp('created_at')->nullable()->index();
        });

        $this->asignarHistoricas();
    }

    /**
     * Backfill aproximado: una impresión por tarima con impresa_at, en esa fecha,
     * sin usuario ni número de etiquetas (no se registraban).
     */
    public function asignarHistoricas(): void
    {
        DB::table('impresiones')->insertUsing(
            ['tarima_id', 'user_id', 'etiquetas', 'es_reimpresion', 'created_at'],
            DB::table('tarimas')
                ->whereNotNull('impresa_at')
                ->select(['id', DB::raw('NULL'), DB::raw('NULL'), DB::raw('0'), 'impresa_at'])
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('impresiones');
    }
};
