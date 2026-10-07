<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consecutivo persistente por pieza. Cada tarima guardada reserva un rango
 * [consecutivo_inicial, consecutivo_inicial + piezas - 1]; el Excel solo lo lee.
 *
 * `contadores` guarda el último consecutivo entregado; TarimaService::guardar lo
 * bloquea (SELECT ... FOR UPDATE) para que dos guardados simultáneos no reciban
 * rangos superpuestos. El índice único de consecutivo_inicial es la red de seguridad.
 */
return new class extends Migration
{
    public const CONTADOR = 'consecutivo_tarimas';

    public function up(): void
    {
        Schema::create('contadores', function (Blueprint $table) {
            $table->string('clave', 50)->primary();
            $table->unsignedBigInteger('valor')->default(0);
            $table->timestamps();
        });

        Schema::table('tarimas', function (Blueprint $table) {
            $table->unsignedBigInteger('consecutivo_inicial')->nullable()->after('guardada_by');
        });

        $this->asignarHistoricas();

        Schema::table('tarimas', function (Blueprint $table) {
            $table->unique('consecutivo_inicial');
        });
    }

    /**
     * Backfill: las tarimas ya guardadas reciben su rango en el orden en que se
     * guardaron. El número de piezas replica TarimaService::filas(): un SKU
     * simple es 1 fila; un padre es 1 fila por N/S viva, o 1 si no tiene N/S.
     * Las temporales quedan en NULL y reciben su rango al guardarse.
     * Es idempotente: solo toca tarimas guardadas sin consecutivo.
     */
    public function asignarHistoricas(): void
    {
        DB::transaction(function () {
            DB::table('contadores')->insertOrIgnore([
                'clave' => self::CONTADOR, 'valor' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $ultimo = (int) DB::table('contadores')->where('clave', self::CONTADOR)->lockForUpdate()->value('valor');

            $tarimas = DB::table('tarimas')
                ->whereNotNull('guardada_at')
                ->whereNull('consecutivo_inicial')
                ->orderBy('guardada_at')
                ->orderBy('id')
                ->pluck('id');

            foreach ($tarimas as $id) {
                $piezas = DB::table('registros')
                    ->where('tarima_id', $id)
                    ->whereNull('deleted_at')
                    ->get(['id', 'tipo'])
                    ->sum(function ($registro) {
                        if ($registro->tipo !== 'padre') {
                            return 1;
                        }

                        return max(1, DB::table('numeros_serie')
                            ->where('registro_id', $registro->id)
                            ->whereNull('deleted_at')
                            ->count());
                    });

                DB::table('tarimas')->where('id', $id)->update(['consecutivo_inicial' => $ultimo + 1]);
                $ultimo += max(1, $piezas);
            }

            DB::table('contadores')->where('clave', self::CONTADOR)->update(['valor' => $ultimo, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        Schema::table('tarimas', function (Blueprint $table) {
            $table->dropUnique(['consecutivo_inicial']);
            $table->dropColumn('consecutivo_inicial');
        });

        Schema::dropIfExists('contadores');
    }
};
