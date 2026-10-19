<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Partida = una fila del Excel (una pieza). Se materializa al guardar la
     * tarima y su consecutivo ya no cambia aunque la pieza se mueva o se retire.
     * Es lo que se entrega, concilia, autoriza y carga en las etapas siguientes.
     */
    public function up(): void
    {
        Schema::create('partidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mudanza_id')->constrained('mudanzas')->restrictOnDelete();
            $table->foreignId('tarima_id')->constrained('tarimas')->restrictOnDelete();
            $table->foreignId('registro_id')->constrained('registros')->restrictOnDelete();
            $table->foreignId('numero_serie_id')->nullable()->unique()->constrained('numeros_serie')->restrictOnDelete();
            $table->unsignedBigInteger('consecutivo');
            // ns: pieza con N/S · sku: SKU suelto (modo Solo SKUs) · sin_ns: SKU padre sin N/S capturadas
            $table->string('tipo_identificacion', 10);
            $table->string('sku', 100);
            $table->string('descripcion')->nullable();
            $table->string('numero_serie', 150)->nullable();
            $table->foreignId('destino_id')->constrained('destinos')->restrictOnDelete();
            $table->timestamp('registrado_at')->nullable();
            $table->string('estado', 15)->default('en_tarima')->index();
            $table->timestamp('retirada_at')->nullable();
            $table->foreignId('retirada_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mudanza_id', 'consecutivo']);
            $table->index(['tarima_id', 'estado']);
        });

        Schema::create('movimientos_partida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partida_id')->constrained('partidas')->restrictOnDelete();
            $table->string('tipo', 20);
            $table->foreignId('tarima_origen_id')->nullable()->constrained('tarimas')->restrictOnDelete();
            $table->foreignId('tarima_destino_id')->nullable()->constrained('tarimas')->restrictOnDelete();
            $table->string('motivo', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::table('tarimas', function (Blueprint $table) {
            // Su contenido cambió después de guardarla: la etiqueta pegada ya no coincide.
            $table->timestamp('reimpresion_pendiente_at')->nullable()->after('motivo_anulacion');
            // El consecutivo ahora se numera por mudanza.
            $table->dropUnique(['consecutivo_inicial']);
            $table->unique(['mudanza_id', 'consecutivo_inicial']);
        });

        $this->materializarHistoricas();
    }

    /**
     * Crea las partidas de las tarimas ya guardadas con los mismos consecutivos
     * que hoy salen en el Excel (consecutivo_inicial + posición, en el orden de
     * TarimaService::filas), y deja un contador de consecutivos por mudanza que
     * continúa donde iba. Es idempotente: solo toca tarimas sin partidas.
     */
    public function materializarHistoricas(): void
    {
        DB::transaction(function () {
            $tarimas = DB::table('tarimas')
                ->whereNotNull('guardada_at')
                ->whereNotNull('consecutivo_inicial')
                ->whereNotExists(fn ($q) => $q->from('partidas')->whereColumn('partidas.tarima_id', 'tarimas.id'))
                ->orderBy('consecutivo_inicial')
                ->get(['id', 'mudanza_id', 'consecutivo_inicial', 'guardada_at', 'guardada_by']);

            foreach ($tarimas as $tarima) {
                $posicion = 0;
                $registros = DB::table('registros')->where('tarima_id', $tarima->id)->whereNull('deleted_at')->orderBy('id')->get();

                foreach ($registros as $registro) {
                    $series = $registro->tipo === 'padre'
                        ? DB::table('numeros_serie')->where('registro_id', $registro->id)->whereNull('deleted_at')->orderBy('id')->get()
                        : collect();

                    $filas = $registro->tipo !== 'padre'
                        ? [[null, null, 'sku', $registro->created_at]]
                        : ($series->isEmpty()
                            ? [[null, null, 'sin_ns', $registro->created_at]]
                            : $series->map(fn ($ns) => [$ns->id, $ns->numero_serie, 'ns', $ns->created_at])->all());

                    foreach ($filas as [$nsId, $ns, $tipo, $registrado]) {
                        $partidaId = DB::table('partidas')->insertGetId([
                            'mudanza_id' => $tarima->mudanza_id,
                            'tarima_id' => $tarima->id,
                            'registro_id' => $registro->id,
                            'numero_serie_id' => $nsId,
                            'consecutivo' => $tarima->consecutivo_inicial + $posicion++,
                            'tipo_identificacion' => $tipo,
                            'sku' => $registro->sku,
                            'descripcion' => $registro->descripcion,
                            'numero_serie' => $ns,
                            'destino_id' => $registro->destino_id,
                            'registrado_at' => $registrado,
                            'estado' => 'en_tarima',
                            'created_at' => $tarima->guardada_at,
                            'updated_at' => $tarima->guardada_at,
                        ]);
                        DB::table('movimientos_partida')->insert([
                            'partida_id' => $partidaId,
                            'tipo' => 'alta',
                            'tarima_destino_id' => $tarima->id,
                            'user_id' => $tarima->guardada_by,
                            'created_at' => $tarima->guardada_at,
                        ]);
                    }
                }
            }

            $this->sincronizarContadores();
        });
    }

    /** contador "consecutivo_mudanza_{id}" = último consecutivo reservado en la mudanza. */
    public function sincronizarContadores(): void
    {
        foreach (DB::table('mudanzas')->pluck('id') as $mudanzaId) {
            $ultimo = 0;
            foreach (DB::table('tarimas')->where('mudanza_id', $mudanzaId)->whereNotNull('consecutivo_inicial')->get(['id', 'consecutivo_inicial']) as $t) {
                $piezas = DB::table('partidas')->where('tarima_id', $t->id)->count();
                $ultimo = max($ultimo, $t->consecutivo_inicial + max(1, $piezas) - 1);
            }
            $ultimo = max($ultimo, (int) DB::table('partidas')->where('mudanza_id', $mudanzaId)->max('consecutivo'));

            $clave = "consecutivo_mudanza_{$mudanzaId}";
            DB::table('contadores')->insertOrIgnore(['clave' => $clave, 'valor' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('contadores')->where('clave', $clave)->where('valor', '<', $ultimo)->update(['valor' => $ultimo, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('tarimas', function (Blueprint $table) {
            $table->dropUnique(['mudanza_id', 'consecutivo_inicial']);
            $table->unique('consecutivo_inicial');
            $table->dropColumn('reimpresion_pendiente_at');
        });
        Schema::dropIfExists('movimientos_partida');
        Schema::dropIfExists('partidas');
        DB::table('contadores')->where('clave', 'like', 'consecutivo_mudanza_%')->delete();
    }
};
