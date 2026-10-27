<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Las migraciones de 2026-10-13 sobre datos existentes: no se pierde nada y son
 * reversibles. Sin transacción envolvente (ni RefreshDatabase), como en un
 * despliegue: SQLite reconstruye tablas al cambiar columnas y solo puede
 * desactivar las llaves foráneas fuera de una transacción. La base es en
 * memoria y se descarta al terminar.
 */
class MigracionInventariosTest extends TestCase {

    /**
     * Las tres de 2026-10-13, las dos de 2026-10-14 (etiqueta por pieza, posición de ubicación)
     * las tres de 2026-10-19 (detalle de movimientos, fecha del Reporte Diario, SKU dados de alta)
     * las dos de 2026-10-20 (SKU original de las conversiones, embarque adicional)
     * y la de 2026-10-27 (¿es demo?).
     */
    private const PASOS = 11;

    public function test_convierte_los_datos_historicos_y_se_puede_revertir(): void {
        Artisan::call('migrate:fresh');
        Artisan::call('migrate:rollback', ['--step' => self::PASOS]);
        $this->assertTrue(Schema::hasColumn('mudanzas', 'almacen_origen'));

        // Estado anterior: mudanza con almacenes en texto, tarima, registro con categoría e incidencias con estados viejos.
        $usuario = DB::table('users')->insertGetId(['name' => 'A', 'email' => 'a@x.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $destino = DB::table('destinos')->insertGetId(['codigo' => 'PROD-A', 'nombre' => 'Refrigeradores', 'created_at' => now(), 'updated_at' => now()]);
        $mudanza = DB::table('mudanzas')->insertGetId([
            'folio' => 'MUD-0001', 'cliente' => 'Euroquip', 'almacen_origen' => 'Bodega Querétaro', 'almacen_destino' => 'CEDIS San Juan',
            'estado' => 'activa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tarima = DB::table('tarimas')->insertGetId(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'mudanza_id' => $mudanza, 'numero' => 1, 'created_by' => $usuario, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('registros')->insert(['tarima_id' => $tarima, 'tipo' => 'simple', 'sku' => 'SKU-1', 'destino_id' => $destino, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['INC-0001' => 'en_investigacion', 'INC-0002' => 'pendiente_cliente', 'INC-0003' => 'descartada', 'INC-0004' => 'resuelta'] as $folio => $estado) {
            DB::table('incidencias')->insert([
                'mudanza_id' => $mudanza, 'folio' => $folio, 'tipo' => 'no_en_sap', 'estado' => $estado, 'titulo' => $folio,
                'tipo_resolucion' => $estado === 'resuelta' ? 'localizado' : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Artisan::call('migrate');

        $this->assertFalse(Schema::hasColumn('mudanzas', 'almacen_origen'));
        $movimiento = DB::table('mudanza_movimientos')->sole();
        $this->assertSame(
            ['Bodega Querétaro', 'CEDIS San Juan'],
            [DB::table('almacenes')->where('id', $movimiento->almacen_origen_id)->value('codigo'), DB::table('almacenes')->where('id', $movimiento->almacen_destino_id)->value('codigo')],
        );
        $this->assertSame($movimiento->id, DB::table('tarimas')->where('id', $tarima)->value('mudanza_movimiento_id'));
        $this->assertSame($destino, (int) DB::table('registros')->value('destino_id'), 'La categoría histórica se conserva.');

        $estados = DB::table('incidencias')->orderBy('folio')->get(['estado', 'estado_legado', 'tipo', 'tipo_resolucion'])->map(fn ($i) => (array) $i)->all();
        $this->assertSame([
            ['estado' => 'abierta', 'estado_legado' => 'en_investigacion', 'tipo' => 'no_en_sap', 'tipo_resolucion' => null],
            ['estado' => 'abierta', 'estado_legado' => 'pendiente_cliente', 'tipo' => 'no_en_sap', 'tipo_resolucion' => null],
            ['estado' => 'resuelta', 'estado_legado' => 'descartada', 'tipo' => 'no_en_sap', 'tipo_resolucion' => 'descartada'],
            ['estado' => 'resuelta', 'estado_legado' => null, 'tipo' => 'no_en_sap', 'tipo_resolucion' => 'localizado'],
        ], $estados);
        $this->assertSame(3, DB::table('incidencia_eventos')->where('detalle', 'like', 'Estado migrado de%')->count());

        // Reversible: vuelven los textos y los estados originales.
        Artisan::call('migrate:rollback', ['--step' => self::PASOS]);
        $this->assertSame(['Bodega Querétaro', 'CEDIS San Juan'], array_values((array) DB::table('mudanzas')->first(['almacen_origen', 'almacen_destino'])));
        $this->assertSame(['en_investigacion', 'pendiente_cliente', 'descartada', 'resuelta'], DB::table('incidencias')->orderBy('folio')->pluck('estado')->all());
    }
}
