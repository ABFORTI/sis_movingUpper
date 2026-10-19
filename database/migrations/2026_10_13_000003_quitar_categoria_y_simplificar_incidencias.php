<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Debe coincidir con Incidencia::NO_EN_INVENTARIO y Incidencia::ABIERTA. */
    private const NO_EN_INVENTARIO = 'no_en_inventario';

    /**
     * 1. La categoría (destino_id / "Producto destino") deja de capturarse: las
     *    columnas pasan a ser opcionales y los registros históricos conservan la suya.
     *
     * 2. Incidencias: solo dos estados (abierta, resuelta). Las que estaban en
     *    investigación o pendientes del cliente siguen abiertas; las descartadas
     *    pasan a resueltas con resolución "descartada". El estado original queda
     *    en `estado_legado` y en un evento del historial, y down() lo restaura.
     *    Los tipos anteriores no se modifican (se muestran como históricos).
     *
     * 3. Una incidencia de "no encontrado en inventario" se liga a la N/S
     *    capturada, su SKU, su tarima y su almacén, aunque la tarima aún no se
     *    guarde (todavía no hay partida). Solo puede haber una abierta por N/S.
     */
    public function up(): void
    {
        Schema::table('registros', function (Blueprint $table) {
            $table->foreignId('destino_id')->nullable()->change();
        });
        Schema::table('partidas', function (Blueprint $table) {
            $table->foreignId('destino_id')->nullable()->change();
        });
        Schema::table('entrega_partidas', function (Blueprint $table) {
            $table->string('categoria', 30)->nullable()->change();
        });

        Schema::table('incidencias', function (Blueprint $table) {
            $table->foreignId('numero_serie_id')->nullable()->after('bloquea_traslado')->constrained('numeros_serie')->restrictOnDelete();
            $table->foreignId('tarima_id')->nullable()->after('numero_serie_id')->constrained('tarimas')->restrictOnDelete();
            $table->foreignId('almacen_id')->nullable()->after('tarima_id')->constrained('almacenes')->restrictOnDelete();
            $table->string('sku', 100)->nullable()->after('almacen_id');
            $table->string('numero_serie', 150)->nullable()->after('sku');
            $table->string('estado_legado', 20)->nullable()->after('estado');
        });

        Schema::table('incidencias', function (Blueprint $table) {
            $table->unsignedBigInteger('ns_pendiente')
                ->virtualAs("CASE WHEN estado = 'abierta' AND tipo = '".self::NO_EN_INVENTARIO."' THEN numero_serie_id END")
                ->nullable()
                ->unique();
        });

        $this->simplificarEstados();
    }

    private function simplificarEstados(): void
    {
        DB::transaction(function () {
            $mapa = ['en_investigacion' => 'abierta', 'pendiente_cliente' => 'abierta', 'descartada' => 'resuelta'];
            $nombres = ['en_investigacion' => 'En investigación', 'pendiente_cliente' => 'Pendiente del cliente', 'descartada' => 'Descartada'];

            $afectadas = DB::table('incidencias')->whereIn('estado', array_keys($mapa))->get(['id', 'estado', 'tipo_resolucion']);
            foreach ($afectadas as $incidencia) {
                $nuevo = $mapa[$incidencia->estado];
                DB::table('incidencias')->where('id', $incidencia->id)->update([
                    'estado' => $nuevo,
                    'estado_legado' => $incidencia->estado,
                    'tipo_resolucion' => $incidencia->estado === 'descartada' ? ($incidencia->tipo_resolucion ?? 'descartada') : $incidencia->tipo_resolucion,
                ]);
                DB::table('incidencia_eventos')->insert([
                    'incidencia_id' => $incidencia->id,
                    'tipo' => 'estado',
                    'detalle' => "Estado migrado de «{$nombres[$incidencia->estado]}» a «".($nuevo === 'abierta' ? 'Abierta' : 'Resuelta')."» al simplificar los estados (solo Abierta y Resuelta).",
                    'user_id' => null,
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        foreach (DB::table('incidencias')->whereNotNull('estado_legado')->get(['id', 'estado_legado']) as $incidencia) {
            DB::table('incidencias')->where('id', $incidencia->id)->update(['estado' => $incidencia->estado_legado]);
        }

        Schema::table('incidencias', function (Blueprint $table) {
            $table->dropUnique(['ns_pendiente']);
            $table->dropColumn('ns_pendiente');
        });
        Schema::table('incidencias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('numero_serie_id');
            $table->dropConstrainedForeignId('tarima_id');
            $table->dropConstrainedForeignId('almacen_id');
            $table->dropColumn(['sku', 'numero_serie', 'estado_legado']);
        });

        // Solo es reversible si todos los registros conservan su categoría.
        Schema::table('entrega_partidas', function (Blueprint $table) {
            $table->string('categoria', 30)->nullable(false)->change();
        });
        Schema::table('partidas', function (Blueprint $table) {
            $table->foreignId('destino_id')->nullable(false)->change();
        });
        Schema::table('registros', function (Blueprint $table) {
            $table->foreignId('destino_id')->nullable(false)->change();
        });
    }
};
