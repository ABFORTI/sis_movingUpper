<?php

namespace Tests\Feature\KpiApi;

use App\Enums\TipoRegistro;
use App\KpiApi\KpiApi;
use App\Models\Destino;
use App\Models\Impresion;
use App\Models\NumeroSerie;
use App\Models\Registro;
use App\Models\Role;
use App\Models\Tarima;
use App\Models\User;
use App\Services\Etiquetas\EtiquetaService;
use App\Services\TarimaService;
use Closure;
use Database\Seeders\DestinoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Valores concretos de cada KPI de MovingUpper (docs/kpi-api/sistemas/movingupper.md). */
class MovingUpperKpisTest extends TestCase {
    use RefreshDatabase;

    private const HOY = '2026-10-06 12:00:00';

    private string $token;
    private int $secuencia = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->seed([RoleSeeder::class, DestinoSeeder::class]);
        $this->hoy();
        $this->token = User::factory()->create()->createToken('kpi-central', [KpiApi::ABILITY])->plainTextToken;
    }

    // ---------- Ayudantes ----------

    private function hoy(): void {
        Carbon::setTestNow(Carbon::parse(self::HOY, config('app.timezone')));
    }

    /** Ejecuta $fn con el reloj en $momento (hora local del sistema) y lo regresa a HOY. */
    private function en(string $momento, Closure $fn): mixed {
        Carbon::setTestNow(Carbon::parse($momento, config('app.timezone')));
        try {
            return $fn();
        } finally {
            $this->hoy();
        }
    }

    private function escanear(string $momento, ?User $usuario = null, string $sku = 'SKU-P', ?Tarima $tarima = null, ?string $ns = null): NumeroSerie {
        return $this->en($momento, function () use ($usuario, $sku, $tarima, $ns) {
            $tarima ??= Tarima::create(['created_by' => $usuario?->id]);
            $registro = Registro::firstOrCreate(
                ['tarima_id' => $tarima->id, 'sku' => $sku],
                ['tipo' => TipoRegistro::Padre, 'destino_id' => Destino::first()->id, 'user_id' => $usuario?->id],
            );

            return $registro->numerosSerie()->create(['numero_serie' => $ns ?? 'SN-'.++$this->secuencia, 'user_id' => $usuario?->id]);
        });
    }

    private function simple(string $momento, Tarima $tarima, string $sku, ?User $usuario = null): Registro {
        return $this->en($momento, fn () => $tarima->registros()->create([
            'tipo' => TipoRegistro::Simple, 'sku' => $sku, 'destino_id' => Destino::first()->id, 'user_id' => $usuario?->id,
        ]));
    }

    private function guardada(string $momento, ?User $usuario = null, array $skus = ['SKU-T']): Tarima {
        $tarima = $this->en($momento, fn () => Tarima::create(['created_by' => $usuario?->id]));
        foreach ($skus as $sku) {
            $this->simple($momento, $tarima, $sku, $usuario);
        }
        $tarima->forceFill(['impresa_at' => $momento, 'guardada_at' => $momento, 'guardada_by' => $usuario?->id])->save();

        return $tarima;
    }

    /** @return array<int, array<string, mixed>> */
    private function datos(string $clave, array $query = []): array {
        $r = $this->withToken($this->token)->getJson("/api/v3/kpis/{$clave}?".http_build_query($query));
        $r->assertOk();

        return $r->json('datos');
    }

    /** @return list<int> la columna $campo de cada renglón */
    private function serie(string $clave, string $campo, array $query): array {
        return array_column($this->datos($clave, $query), $campo);
    }

    // ---------- numeros-serie-escaneados ----------

    public function test_sin_datos_la_serie_viene_completa_en_ceros_y_la_variacion_en_null(): void {
        $datos = $this->datos('numeros-serie-escaneados', ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-03', 'agrupacion' => 'dia']);

        $this->assertSame([
            ['periodo' => '2026-10-01', 'periodo_inicio' => '2026-10-01', 'periodo_fin' => '2026-10-01', 'total_numeros_serie' => 0, 'total_periodo_anterior' => 0, 'porcentaje_variacion' => null],
            ['periodo' => '2026-10-02', 'periodo_inicio' => '2026-10-02', 'periodo_fin' => '2026-10-02', 'total_numeros_serie' => 0, 'total_periodo_anterior' => 0, 'porcentaje_variacion' => null],
            ['periodo' => '2026-10-03', 'periodo_inicio' => '2026-10-03', 'periodo_fin' => '2026-10-03', 'total_numeros_serie' => 0, 'total_periodo_anterior' => 0, 'porcentaje_variacion' => null],
        ], $datos);
    }

    public function test_un_solo_escaneo(): void {
        $this->escanear('2026-10-02 09:15:00');

        $this->assertSame(
            [['total_numeros_serie' => 1, 'total_periodo_anterior' => 0, 'porcentaje_variacion' => null]],
            $this->datos('numeros-serie-escaneados', ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-06', 'agrupacion' => 'total'])
        );
    }

    public function test_varios_dias_con_variacion_contra_el_dia_anterior(): void {
        $this->escanear('2026-09-30 10:00:00');
        $this->escanear('2026-10-01 08:00:00');
        $this->escanear('2026-10-01 17:30:00');
        foreach (['08:00', '09:00', '10:00'] as $hora) {
            $this->escanear("2026-10-03 {$hora}:00");
        }

        $datos = $this->datos('numeros-serie-escaneados', ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-03', 'agrupacion' => 'dia']);

        $this->assertSame([2, 0, 3], array_column($datos, 'total_numeros_serie'));
        $this->assertSame([1, 2, 0], array_column($datos, 'total_periodo_anterior'), 'El 1-oct se compara con el 30-sep, fuera del rango pedido');
        $this->assertSame([100.0, -100.0, null], array_column($datos, 'porcentaje_variacion'));
    }

    public function test_semanas_iso_de_lunes_a_domingo(): void {
        $this->escanear('2026-10-04 23:59:59'); // domingo, W40
        $this->escanear('2026-10-05 00:00:00'); // lunes, W41
        $this->escanear('2026-10-06 08:00:00');

        $datos = $this->datos('numeros-serie-escaneados', ['fecha_inicio' => '2026-09-28', 'fecha_fin' => '2026-10-11', 'agrupacion' => 'semana']);

        $this->assertSame(['2026-W40', '2026-W41'], array_column($datos, 'periodo'));
        $this->assertSame(['2026-09-28', '2026-10-05'], array_column($datos, 'periodo_inicio'));
        $this->assertSame(['2026-10-04', '2026-10-11'], array_column($datos, 'periodo_fin'));
        $this->assertSame([1, 2], array_column($datos, 'total_numeros_serie'));
    }

    public function test_cambio_de_mes_en_la_frontera_de_medianoche_local(): void {
        $this->escanear('2026-09-30 23:59:59');
        $this->escanear('2026-10-01 00:00:00');

        $datos = $this->datos('numeros-serie-escaneados', ['fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-10-06', 'agrupacion' => 'mes']);

        $this->assertSame(['2026-09', '2026-10'], array_column($datos, 'periodo'));
        $this->assertSame([1, 1], array_column($datos, 'total_numeros_serie'));
    }

    public function test_comparacion_mensual_cubeta_completa_y_mes_en_curso_recortado(): void {
        $this->escanear('2026-08-10 10:00:00');
        $this->escanear('2026-08-10 11:00:00');
        $this->escanear('2026-09-05 10:00:00'); // cae en el tramo 1–6 de septiembre
        $this->escanear('2026-09-20 10:00:00');
        $this->escanear('2026-09-20 11:00:00');
        $this->escanear('2026-09-20 12:00:00');
        $this->escanear('2026-10-03 10:00:00');

        $datos = $this->datos('numeros-serie-escaneados', ['fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-10-06', 'agrupacion' => 'mes']);

        $this->assertSame([2, 4, 1], array_column($datos, 'total_numeros_serie'));
        // ago contra jul completo; sep completo contra ago completo; 1–6 oct contra 1–6 sep.
        $this->assertSame([0, 2, 1], array_column($datos, 'total_periodo_anterior'));
        $this->assertSame([null, 100.0, 0.0], array_column($datos, 'porcentaje_variacion'));
    }

    public function test_comparacion_total_contra_el_mismo_numero_de_dias_anteriores(): void {
        $this->escanear('2026-08-01 10:00:00'); // fuera: el anterior de sep (30 días) empieza el 2-ago
        $this->escanear('2026-08-02 10:00:00');
        $this->escanear('2026-08-31 10:00:00');
        foreach ([1, 2, 3] as $dia) {
            $this->escanear("2026-09-1{$dia} 10:00:00");
        }

        $this->assertSame(
            [['total_numeros_serie' => 3, 'total_periodo_anterior' => 2, 'porcentaje_variacion' => 50.0]],
            $this->datos('numeros-serie-escaneados', ['fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-30', 'agrupacion' => 'total'])
        );
    }

    public function test_cambio_de_trimestre(): void {
        $this->escanear('2026-07-03 10:00:00');
        $this->escanear('2026-09-30 18:00:00');
        $this->escanear('2026-10-01 07:00:00');

        $datos = $this->datos('numeros-serie-escaneados', ['fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-10-06', 'agrupacion' => 'trimestre']);

        $this->assertSame(['2026-Q3', '2026-Q4'], array_column($datos, 'periodo'));
        $this->assertSame([['2026-07-01', '2026-09-30'], ['2026-10-01', '2026-10-06']], array_map(fn ($r) => [$r['periodo_inicio'], $r['periodo_fin']], $datos));
        $this->assertSame([2, 1], array_column($datos, 'total_numeros_serie'));
        // Q4 recortado (1–6 oct) contra el mismo tramo de Q3 (1–6 jul), donde hubo 1.
        $this->assertSame([0, 1], array_column($datos, 'total_periodo_anterior'));
    }

    public function test_cambio_de_anio_no_mezcla_trimestres_ni_meses_de_anios_distintos(): void {
        $this->escanear('2025-10-15 10:00:00');
        $this->escanear('2025-12-31 23:59:00');
        $this->escanear('2026-01-01 00:01:00');

        $query = ['fecha_inicio' => '2025-10-01', 'fecha_fin' => '2026-03-31'];

        $trimestres = $this->datos('numeros-serie-escaneados', $query + ['agrupacion' => 'trimestre']);
        $this->assertSame(['2025-Q4', '2026-Q1'], array_column($trimestres, 'periodo'));
        $this->assertSame([2, 1], array_column($trimestres, 'total_numeros_serie'));
        $this->assertSame([0, 2], array_column($trimestres, 'total_periodo_anterior'), '2026-Q1 se compara con 2025-Q4');

        $this->assertSame([1, 0, 1, 1, 0, 0], $this->serie('numeros-serie-escaneados', 'total_numeros_serie', $query + ['agrupacion' => 'mes']));
        $this->assertSame([2, 1], $this->serie('numeros-serie-escaneados', 'total_numeros_serie', $query + ['agrupacion' => 'anio']));
    }

    public function test_series_duplicadas_borrada_y_reescaneada_cuenta_una_vez(): void {
        $operador = User::factory()->conRol(Role::OPERADOR)->create();
        $primera = Tarima::create(['created_by' => $operador->id]);
        $this->escanear('2026-10-01 10:00:00', $operador, 'SKU-P', $primera, 'SN-DUP');
        $this->escanear('2026-10-01 10:05:00', $operador, 'SKU-P', $primera, 'SN-OTRA');

        // El operador vacía la tarima: borrado lógico, la N/S queda libre.
        $this->en('2026-10-02 08:00:00', fn () => app(TarimaService::class)->vaciar($primera, $operador));
        $this->escanear('2026-10-02 09:00:00', $operador, 'SKU-P', null, 'SN-DUP');

        $this->assertSame(2, NumeroSerie::withTrashed()->where('numero_serie', 'SN-DUP')->count());
        $this->assertSame([0, 1], $this->serie('numeros-serie-escaneados', 'total_numeros_serie',
            ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-02', 'agrupacion' => 'dia']));
    }

    public function test_filtros_usuario_y_producto(): void {
        $ana = User::factory()->create();
        $beto = User::factory()->create();
        $this->escanear('2026-10-01 10:00:00', $ana, 'SKU-A');
        $this->escanear('2026-10-01 11:00:00', $ana, 'SKU-B');
        $this->escanear('2026-10-02 10:00:00', $beto, 'SKU-A');

        $total = fn (array $filtros) => $this->datos('numeros-serie-escaneados',
            ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-06', 'agrupacion' => 'total'] + $filtros)[0]['total_numeros_serie'];

        $this->assertSame(3, $total([]));
        $this->assertSame(2, $total(['usuario' => (string) $ana->id]));
        $this->assertSame(3, $total(['usuario' => "{$ana->id},{$beto->id}"]), 'Entre valores es OR');
        $this->assertSame(2, $total(['producto' => 'SKU-A']));
        $this->assertSame(1, $total(['usuario' => (string) $ana->id, 'producto' => 'SKU-A']), 'Entre filtros es AND');
        $this->assertSame(0, $total(['usuario' => '99999']), 'Un ID inexistente no es error: no hay datos');

        $r = $this->withToken($this->token)->getJson('/api/v3/kpis/numeros-serie-escaneados?usuario='.$ana->id);
        $this->assertSame(['usuario' => [(string) $ana->id]], $r->json('filtros'));
    }

    // ---------- tarimas-registradas ----------

    public function test_tarimas_registradas_cuenta_solo_guardadas_por_fecha_de_guardado(): void {
        $ana = User::factory()->create();
        $beto = User::factory()->create();
        $this->guardada('2026-09-30 18:00:00', $ana, ['SKU-A', 'SKU-B']);
        $this->guardada('2026-10-01 09:00:00', $ana, ['SKU-A']);
        $this->guardada('2026-10-01 10:00:00', $beto, ['SKU-C']);
        $this->en('2026-10-01 11:00:00', fn () => Tarima::create(['created_by' => $ana->id])); // temporal: no cuenta

        $query = ['fecha_inicio' => '2026-09-30', 'fecha_fin' => '2026-10-01', 'agrupacion' => 'dia'];
        $this->assertSame([1, 2], $this->serie('tarimas-registradas', 'total_tarimas', $query));
        $this->assertSame([1, 1], $this->serie('tarimas-registradas', 'total_tarimas', $query + ['usuario' => (string) $ana->id]));
        $this->assertSame([1, 1], $this->serie('tarimas-registradas', 'total_tarimas', $query + ['producto' => 'SKU-A,SKU-B']),
            'Una tarima con dos SKUs filtrados cuenta una vez');
    }

    // ---------- skus-registrados ----------

    public function test_skus_registrados_separa_padre_simple_y_distintos(): void {
        $t1 = Tarima::create();
        $t2 = Tarima::create();
        $this->escanear('2026-10-01 10:00:00', null, 'SKU-P', $t1);
        $this->escanear('2026-10-01 10:01:00', null, 'SKU-P', $t1); // misma fila padre: no suma registros
        $this->simple('2026-10-01 11:00:00', $t1, 'SKU-S');
        $this->simple('2026-10-02 11:00:00', $t2, 'sku-s');         // mismo SKU (sin distinguir mayúsculas) en otra tarima
        $borrado = $this->simple('2026-10-02 12:00:00', $t2, 'SKU-X');
        $borrado->delete();                                          // papelera: no cuenta

        $this->assertSame(
            [['total_registros' => 3, 'total_registros_padre' => 1, 'total_registros_simples' => 2, 'total_skus_distintos' => 2, 'total_periodo_anterior' => 0, 'porcentaje_variacion' => null]],
            $this->datos('skus-registrados', ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-02', 'agrupacion' => 'total'])
        );
        $this->assertSame([2, 1], $this->serie('skus-registrados', 'total_skus_distintos',
            ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-02', 'agrupacion' => 'dia']));
    }

    // ---------- etiquetas-impresas ----------

    public function test_cada_confirmacion_de_impresion_queda_en_la_bitacora_con_sus_etiquetas(): void {
        $operador = User::factory()->conRol(Role::OPERADOR)->create();
        $tarima = Tarima::create(['created_by' => $operador->id]);
        foreach (range(1, 12) as $i) {
            $this->simple('2026-10-05 09:00:00', $tarima, "SKU-{$i}", $operador);
        }
        $etiquetas = count(app(EtiquetaService::class)->paginas($tarima->fresh()));
        $this->assertGreaterThan(1, $etiquetas, 'El listado de 12 SKUs ocupa más de una etiqueta');

        $this->en('2026-10-05 10:00:00', fn () => $this->actingAs($operador)->postJson(route('tarimas.impresa', $tarima))->assertOk()->assertJson(['impresa' => true]));
        $this->en('2026-10-06 08:00:00', fn () => $this->actingAs($operador)->postJson(route('tarimas.impresa', $tarima))->assertOk());

        $this->assertSame([false, true], Impresion::orderBy('id')->pluck('es_reimpresion')->all());
        $this->assertSame([$operador->id, $operador->id], Impresion::pluck('user_id')->all());

        $datos = $this->datos('etiquetas-impresas', ['fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-10-06', 'agrupacion' => 'dia']);
        $this->assertSame([$etiquetas, $etiquetas], array_column($datos, 'total_etiquetas'));
        $this->assertSame([1, 1], array_column($datos, 'total_impresiones'));
        $this->assertSame([0, 1], array_column($datos, 'total_reimpresiones'));
        $this->assertSame([1, 1], array_column($datos, 'total_tarimas_impresas'));

        $total = $this->datos('etiquetas-impresas', ['fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-10-06', 'agrupacion' => 'total'])[0];
        $this->assertSame([2 * $etiquetas, 2, 1, 1, 0], [
            $total['total_etiquetas'], $total['total_impresiones'], $total['total_reimpresiones'], $total['total_tarimas_impresas'], $total['total_impresiones_sin_conteo'],
        ]);
        $this->assertSame(0, $this->datos('etiquetas-impresas', ['agrupacion' => 'total', 'usuario' => '99999'])[0]['total_impresiones']);
    }

    public function test_impresiones_historicas_sin_conteo_se_reportan_aparte(): void {
        $tarima = $this->guardada('2026-09-15 10:00:00');
        // Así quedan tras el backfill de la migración: sin usuario ni etiquetas.
        Impresion::forceCreate(['tarima_id' => $tarima->id, 'user_id' => null, 'etiquetas' => null, 'es_reimpresion' => false, 'created_at' => '2026-09-15 10:00:00']);

        $total = $this->datos('etiquetas-impresas', ['fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-30', 'agrupacion' => 'total'])[0];

        $this->assertSame(0, $total['total_etiquetas']);
        $this->assertSame(1, $total['total_impresiones']);
        $this->assertSame(1, $total['total_impresiones_sin_conteo']);
    }

    public function test_backfill_crea_una_impresion_por_tarima_impresa(): void {
        $impresa = $this->guardada('2026-09-15 10:00:00');
        Tarima::create(); // sin imprimir
        Impresion::query()->delete();

        (require database_path('migrations/2026_10_06_000002_create_impresiones_table.php'))->asignarHistoricas();

        $this->assertSame([[$impresa->id, null, null, false, '2026-09-15 10:00:00']], Impresion::get()
            ->map(fn (Impresion $i) => [$i->tarima_id, $i->user_id, $i->etiquetas, $i->es_reimpresion, $i->created_at->format('Y-m-d H:i:s')])->all());
    }

    // ---------- productividad-por-usuario ----------

    public function test_productividad_por_usuario_ordenada_y_consistente_con_los_kpis_de_serie(): void {
        $ana = User::factory()->create(['name' => 'Ana']);
        $beto = User::factory()->create(['name' => 'Beto']);
        $carla = User::factory()->create(['name' => 'Carla']);
        $this->escanear('2026-10-01 10:00:00', $ana);
        $this->escanear('2026-10-01 10:01:00', $ana);
        $this->escanear('2026-10-02 10:00:00', $beto, 'SKU-B');
        $this->guardada('2026-10-03 10:00:00', $beto);
        $this->escanear('2026-08-01 10:00:00', $carla); // fuera del periodo

        $query = ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-06'];
        $r = $this->withToken($this->token)->getJson('/api/v3/kpis/productividad-por-usuario?'.http_build_query($query))->assertOk();

        $this->assertSame([
            // escanear() sin tarima abre una tarima (y un SKU padre) por escaneo.
            ['usuario_id' => $ana->id, 'usuario_nombre' => 'Ana', 'total_numeros_serie' => 2, 'total_registros' => 2, 'total_tarimas' => 0, 'total_impresiones' => 0],
            ['usuario_id' => $beto->id, 'usuario_nombre' => 'Beto', 'total_numeros_serie' => 1, 'total_registros' => 2, 'total_tarimas' => 1, 'total_impresiones' => 0],
        ], $r->json('datos'));
        $this->assertSame(['pagina' => 1, 'por_pagina' => 50, 'total_paginas' => 1], $r->json('metadatos.paginacion'));

        $this->assertSame(
            array_sum(array_column($r->json('datos'), 'total_numeros_serie')),
            $this->datos('numeros-serie-escaneados', $query + ['agrupacion' => 'total'])[0]['total_numeros_serie']
        );

        $pagina2 = $this->withToken($this->token)->getJson('/api/v3/kpis/productividad-por-usuario?'.http_build_query($query + ['pagina' => 2, 'por_pagina' => 1]));
        $this->assertSame([$beto->id], array_column($pagina2->json('datos'), 'usuario_id'));
        $this->assertSame(2, $pagina2->json('metadatos.total_registros'));

        $this->assertSame([$beto->id], array_column($this->datos('productividad-por-usuario', $query + ['usuario' => (string) $beto->id]), 'usuario_id'));
        $this->assertSame([], $this->datos('productividad-por-usuario', ['fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-31']), 'Sin actividad: 200 con datos vacíos');
    }

    public function test_actividad_de_usuarios_eliminados_se_agrupa_sin_usuario(): void {
        $temporal = User::factory()->create();
        $this->escanear('2026-10-01 10:00:00', $temporal);
        $temporal->delete();

        $this->assertSame(
            [['usuario_id' => null, 'usuario_nombre' => null, 'total_numeros_serie' => 1, 'total_registros' => 1, 'total_tarimas' => 0, 'total_impresiones' => 0]],
            $this->datos('productividad-por-usuario', ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-06'])
        );
    }
}
