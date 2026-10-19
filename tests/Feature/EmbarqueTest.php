<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Embarque;
use App\Models\EmbarquePartida;
use App\Models\Incidencia;
use App\Models\Partida;
use App\Models\Tarima;
use App\Models\User;
use App\Services\MudanzaService;
use App\Services\TarimaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/**
 * Carga (subir) y recepción (bajar) por escaneo. Desde 2026-10-12 la carga no
 * exige autorización del cliente.
 */
class EmbarqueTest extends TestCase {
    use PreparaWms;

    private ?User $capturista = null;

    private ?User $supervisor = null;

    private mixed $discoFalso = null;

    private function op(): User {
        return $this->capturista ??= $this->operador();
    }

    private function sup(): User {
        return $this->supervisor ??= $this->admin();
    }

    /** Tarima guardada con N/S {prefijo}-1…n del SKU dado. */
    private function guardada(string $prefijo, int $n = 2, string $sku = '2364F'): Tarima {
        $this->discoFalso ??= Storage::fake('documentos');
        $tarima = $this->tarima($this->op());
        $this->activarPadre($this->op(), $tarima, $sku);
        foreach (range(1, $n) as $i) {
            $this->escanearNs("{$prefijo}-{$i}");
        }
        $this->post(route('escaneo.padre.cerrar'));
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $tarima))->assertOk();

        return $tarima->fresh();
    }

    private function embarque(): Embarque {
        $this->actingAs($this->sup())->post(route('embarques.store'), ['placas' => 'abc-123-x'])->assertSessionHasNoErrors();

        return Embarque::latest('id')->first();
    }

    private function cargar(Embarque $e, string $codigo, ?User $quien = null) {
        return $this->actingAs($quien ?? $this->op())->post(route('embarques.cargar', $e), ['codigo' => $codigo]);
    }

    private function salir(Embarque $e): void {
        $this->actingAs($this->sup())->post(route('embarques.despachar', $e), ['sello' => 'S-1'])->assertSessionHasNoErrors();
    }

    public function test_el_modulo_de_autorizaciones_ya_no_existe(): void {
        foreach (['index', 'create', 'store', 'show', 'validar', 'rechazar', 'revocar'] as $accion) {
            $this->assertFalse(Route::has("autorizaciones.{$accion}"), "Sigue la ruta autorizaciones.{$accion}");
        }
        $this->actingAs($this->sup())->get('/autorizaciones')->assertNotFound();
        $this->actingAs($this->sup())->get(route('wms'))->assertOk()->assertDontSee('Autorizaciones');
    }

    public function test_carga_por_qr_codigo_de_barras_y_numero_de_tarima_sin_autorizacion(): void {
        $t1 = $this->guardada('A');
        $t2 = $this->guardada('B');
        $t3 = $this->guardada('C');
        $e = $this->embarque();
        $this->assertSame('TRL-0001', $e->folio);
        $this->assertSame('ABC-123-X', $e->placas);

        $this->cargar($e, app(TarimaService::class)->urlDetalle($t1))->assertSessionHasNoErrors()->assertSessionHas('ok', 'E-001: 2 piezas cargadas.');
        // Código de barras de las etiquetas viejas de tarima (TAR-{id}|fecha): se sigue reconociendo.
        $this->cargar($e, 'TAR-'.$t2->id.'|20260101')->assertSessionHasNoErrors();
        $this->cargar($e, $t3->identificador)->assertSessionHasNoErrors();

        $this->assertSame(6, $e->cargas()->count());
        $this->assertSame([null], $e->cargas()->distinct()->pluck('autorizacion_id')->all());
        $this->assertSame($this->op()->id, $e->cargas()->value('cargada_by'));
        $this->assertNotNull($e->cargas()->value('cargada_at'));

        // Escanear de nuevo el mismo embarque no lo duplica ni es un error.
        $this->cargar($e, 'E-001')->assertSessionHasNoErrors()->assertSessionHas('warn', 'E-001: ya estaba en este tráiler. No se duplicó.');
        $this->assertSame(6, $e->cargas()->count());
    }

    public function test_respuesta_json_para_el_handheld(): void {
        $t1 = $this->guardada('A');
        $e = $this->embarque();

        $this->actingAs($this->op())->postJson(route('embarques.cargar', $e), ['codigo' => 'E-001'])
            ->assertOk()
            ->assertJson([
                'estado' => 'ok', 'tarima' => 'E-001', 'tarima_id' => $t1->id, 'piezas' => 2,
                'total' => 2, 'tarima_cargadas' => 2, 'bajar_url' => route('embarques.bajar', [$e, $t1]),
            ]);

        // Reintento (p. ej. se perdió la respuesta por mala señal): 200 y "repetido".
        $this->actingAs($this->op())->postJson(route('embarques.cargar', $e), ['codigo' => 'E-001'])
            ->assertOk()->assertJson(['estado' => 'repetido', 'total' => 2]);

        $this->actingAs($this->op())->postJson(route('embarques.cargar', $e), ['codigo' => 'NO-EXISTE'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->actingAs($this->op())->postJson(route('embarques.cargar', $e), ['codigo' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->assertSame(2, EmbarquePartida::count());
    }

    public function test_tarima_por_reimprimir_o_en_captura_no_sube(): void {
        $t1 = $this->guardada('A', 3);
        $t2 = $this->guardada('B');
        $enCaptura = $this->tarima($this->op());
        $e = $this->embarque();

        $this->cargar($e, $enCaptura->identificador)->assertSessionHasErrors('codigo');
        $this->assertStringContainsString('sigue en captura', session('errors')->first('codigo'));

        // Una pieza se movió de embarque: su etiqueta trae el número anterior y el
        // embarque destino no sube hasta reimprimirla. Las del origen siguen siendo válidas.
        $a1 = Partida::where('numero_serie', 'A-1')->sole();
        $this->actingAs($this->sup())->post(route('tarimas.partidas.mover', $t1), [
            'partidas' => [$a1->id], 'destino' => $t2->uuid, 'motivo' => 'Va con la otra',
        ])->assertSessionHasNoErrors();
        $this->cargar($e, 'E-002')->assertSessionHasErrors('codigo');
        $this->assertStringContainsString('etiqueta por reimprimir', session('errors')->first('codigo'));
        $this->cargar($e, 'A-1')->assertSessionHasErrors('codigo');
        $this->assertSame(0, EmbarquePartida::count());

        $this->cargar($e, 'E-001')->assertSessionHasNoErrors();
        $this->assertSame(2, EmbarquePartida::count());

        // Al imprimir la etiqueta nueva de la pieza movida, el destino ya sube.
        $this->actingAs($this->sup())->postJson(route('tarimas.impresa', ['tarima' => $t2, 'piezas' => $a1->numero_serie_id]))->assertOk()->assertJson(['pendientes' => 0]);
        $this->cargar($e, 'E-002')->assertSessionHasNoErrors();
    }

    /** El estado del SKU en la captura (activo o cerrado) no decide si se puede cargar. */
    public function test_sku_activo_o_cerrado_y_escaneo_por_sku(): void {
        $tarima = $this->tarima($this->op());
        $this->activarPadre($this->op(), $tarima, 'BOMBA-1');
        $this->escanearNs('S-1')->assertSessionHasNoErrors();
        $this->post(route('escaneo.padre.cerrar'));

        // Volver a escanear el SKU cerrado lo reabre en lugar de rechazarlo.
        $this->post(route('escaneo.padre'), [
            'sku' => 'BOMBA-1', 'tarima' => $tarima->uuid,
        ])->assertSessionHasNoErrors()->assertSessionHas('ok', "SKU BOMBA-1 reabierto en {$tarima->nombre} (lleva 1 N/S). Sigue con las N/S.");
        $this->escanearNs('S-2')->assertSessionHasNoErrors();
        $this->assertSame(1, $tarima->registros()->count());
        $this->assertSame(2, $tarima->registros()->sole()->numerosSerie()->count());
        $this->post(route('escaneo.padre.cerrar'));
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $tarima))->assertOk();

        $this->guardada('X', 1, 'COMUN');
        $this->guardada('Y', 1, 'COMUN');
        $e = $this->embarque();

        // SKU en un solo embarque: carga sus piezas.
        $this->cargar($e, 'BOMBA-1')->assertSessionHasNoErrors()->assertSessionHas('ok', 'SKU BOMBA-1: 2 piezas cargadas.');
        // SKU en varios embarques: se pide la N/S o el número de embarque.
        $this->cargar($e, 'COMUN')->assertSessionHasErrors('codigo');
        $this->assertStringContainsString('varios embarques (E-002, E-003)', session('errors')->first('codigo'));
        $this->assertSame(2, $e->cargas()->count());
    }

    public function test_incidencia_que_bloquea_impide_cargar(): void {
        $this->guardada('A');
        $e = $this->embarque();
        $this->actingAs($this->op())->post(route('incidencias.store'), [
            'tipo' => 'danio', 'titulo' => 'Golpe en la carcasa', 'bloquea_traslado' => '1', 'piezas' => 'A-2',
        ])->assertSessionHasNoErrors();

        $this->cargar($e, 'E-001')->assertSessionHasErrors('codigo');
        $this->assertStringContainsString('INC-0001', session('errors')->first('codigo'));

        // Una pieza suelta (N/S) sin problemas sí sube.
        $this->cargar($e, 'A-1')->assertSessionHasNoErrors()->assertSessionHas('ok', 'N/S A-1: 1 pieza cargada.');
    }

    public function test_una_pieza_solo_va_en_un_embarque_aunque_lo_escaneen_dos_operadores(): void {
        $this->guardada('A');
        $e1 = $this->embarque();
        $e2 = $this->embarque();
        $otro = $this->operador();

        $this->cargar($e1, 'E-001')->assertSessionHasNoErrors();
        // Otro operador escanea la misma tarima en el mismo tráiler: idempotente.
        $this->cargar($e1, 'E-001', $otro)->assertSessionHas('warn');
        // …o en otro tráiler: rechazada, no se mueve sola.
        $this->cargar($e2, 'E-001', $otro)->assertSessionHasErrors('codigo');
        $this->assertStringContainsString('TRL-0001', session('errors')->first('codigo'));

        $this->assertSame(2, EmbarquePartida::count());
        $this->assertSame([$this->op()->id], EmbarquePartida::distinct()->pluck('cargada_by')->all());
    }

    /** Segunda barrera ante dos cargas simultáneas: el índice único de la pieza. */
    public function test_la_base_impide_la_misma_pieza_en_dos_embarques(): void {
        $this->guardada('A', 1);
        $e1 = $this->embarque();
        $e2 = $this->embarque();
        $this->cargar($e1, 'E-001');
        $fila = EmbarquePartida::sole();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('embarque_partidas')->insert([
            'embarque_id' => $e2->id, 'partida_id' => $fila->partida_id, 'tarima_id' => $fila->tarima_id,
            'cargada_at' => now(), 'cargada_by' => $this->op()->id,
        ]);
    }

    public function test_bajar_y_lo_cargado_no_se_corrige_ni_se_anula(): void {
        $t1 = $this->guardada('A');
        $t2 = $this->guardada('B');
        $e = $this->embarque();
        $this->cargar($e, 'E-001');

        $a1 = Partida::where('numero_serie', 'A-1')->sole();
        $this->actingAs($this->sup())->post(route('tarimas.partidas.mover', $t1), [
            'partidas' => [$a1->id], 'destino' => $t2->uuid, 'motivo' => 'Va con la otra',
        ])->assertSessionHasErrors('partidas');
        $this->actingAs($this->sup())->post(route('tarimas.anular', $t1), ['motivo' => 'Se duplicó'])->assertSessionHasErrors('motivo');

        $this->actingAs($this->op())->post(route('embarques.bajar', [$e, $t1]), ['motivo' => 'Era otro tráiler'])->assertSessionHasNoErrors();
        $this->assertSame(0, $e->cargas()->count());
        $this->assertSame(1, Bitacora::where('accion', 'embarque.descarga')->count());
    }

    public function test_salida_revalida_y_registra_sello(): void {
        $this->guardada('A');
        $e = $this->embarque();
        $this->cargar($e, 'E-001');

        // Después de cargar se abre una incidencia que bloquea: no puede salir.
        $this->actingAs($this->op())->post(route('incidencias.store'), [
            'tipo' => 'danio', 'titulo' => 'Se cayó al subir', 'bloquea_traslado' => '1', 'piezas' => 'A-1',
        ]);
        $this->actingAs($this->sup())->post(route('embarques.despachar', $e), ['sello' => 'S-99'])->assertSessionHasErrors('salida');
        $this->assertSame(Embarque::EN_CARGA, $e->fresh()->estado);

        $this->actingAs($this->sup())->post(route('incidencias.resolver', Incidencia::sole()), ['tipo_resolucion' => 'descartada', 'resolucion' => 'Revisada, sin daño']);
        $this->actingAs($this->op())->post(route('embarques.despachar', $e), ['sello' => 'S-99'])->assertForbidden();
        $this->actingAs($this->sup())->post(route('embarques.despachar', $e), ['sello' => 'S-99'])->assertSessionHasNoErrors();

        $e->refresh();
        $this->assertSame(Embarque::EN_TRANSITO, $e->estado);
        $this->assertSame('S-99', $e->sello);
        $this->cargar($e, 'E-001')->assertSessionHasErrors('codigo');
    }

    public function test_recepcion_ligada_a_la_carga_y_faltante_queda_en_el_reporte(): void {
        $this->guardada('A');
        $this->guardada('B');
        $e = $this->embarque();
        $this->cargar($e, 'E-001');
        $this->cargar($e, 'E-002');
        $this->salir($e);

        $this->actingAs($this->op())->post(route('embarques.recibir', $e), ['codigo' => 'E-001'])->assertSessionHas('ok', 'E-001: 2 piezas recibidas.');
        $this->actingAs($this->op())->post(route('embarques.recibir', $e), ['codigo' => 'B-1'])
            ->assertSessionHas('ok', 'N/S B-1: 1 pieza recibida. Faltan 1 del E-002.');
        // Repetir no duplica ni cambia quién la recibió.
        $this->actingAs($this->operador())->post(route('embarques.recibir', $e), ['codigo' => 'E-001'])
            ->assertSessionHasNoErrors()->assertSessionHas('warn', 'E-001: ya se había recibido. No se duplicó.');
        $this->assertSame([$this->op()->id], $e->cargas()->whereNotNull('recibida_at')->distinct()->pluck('recibida_by')->all());

        // Carga y recepción viven en la misma fila: una por pieza.
        $this->assertSame(4, $e->cargas()->count());
        $this->assertSame(3, $e->cargas()->whereNotNull('recibida_at')->whereNotNull('cargada_at')->count());

        $this->actingAs($this->sup())->post(route('embarques.cerrar', $e))
            ->assertSessionHas('warn', 'Recepción cerrada con 1 pieza faltante: aparecen como «Faltante en recepción» en el manifiesto y el reporte final.');

        $e->refresh();
        $this->assertSame(Embarque::RECIBIDO, $e->estado);
        // Solo existen los tipos "no encontrado en inventario" y "producto dañado": el faltante no abre incidencia.
        $this->assertSame(0, Incidencia::count());
        $this->assertCount(1, Bitacora::where('accion', 'embarque.recibido')->sole()->datos['consecutivos_faltantes']);
        $situaciones = collect(app(\App\Services\EmbarqueService::class)->filasReporte($this->mudanza, $e))->pluck(11, 3);
        $this->assertSame('Faltante en recepción', $situaciones['B-2']);
    }

    public function test_recepcion_de_una_tarima_que_no_viene_en_el_embarque(): void {
        $this->guardada('A');
        $t2 = $this->guardada('B');
        $e = $this->embarque();
        $this->cargar($e, 'E-001');
        $this->salir($e);

        $this->actingAs($this->op())->postJson(route('embarques.recibir', $e), ['codigo' => 'E-002'])
            ->assertOk()->assertJson(['estado' => 'no_esperada', 'tarima' => 'E-002', 'piezas' => 0]);
        // No se asocia a nada, pero queda en la bitácora.
        $this->assertSame(0, $e->cargas()->whereNotNull('recibida_at')->count());
        $this->assertSame(0, EmbarquePartida::where('tarima_id', $t2->id)->count());
        $this->assertSame(1, Bitacora::where('accion', 'embarque.recepcion_no_esperada')->count());

        // Ese tipo de incidencia se retiró: ya no hay ruta para registrarla.
        $this->assertFalse(Route::has('embarques.no-esperada'));
        $this->assertSame(0, Incidencia::count());
    }

    public function test_la_mudanza_no_cierra_con_embarques_abiertos(): void {
        $this->guardada('A');
        $e = $this->embarque();
        $this->cargar($e, 'E-001');
        $admin = $this->sup();
        app(MudanzaService::class)->transicionar($this->mudanza, MudanzaService::INICIAR_CIERRE, $admin);

        try {
            app(MudanzaService::class)->transicionar($this->mudanza, MudanzaService::CERRAR, $admin);
            $this->fail('Cerró con embarques abiertos.');
        } catch (ValidationException $ex) {
            $this->assertStringContainsString('TRL-0001', $ex->errors()['accion'][0]);
        }
    }

    /** Lo que llega del escáner con teclado en español o con prefijo de DataWedge. */
    public function test_reconoce_codigos_alterados_por_el_escaner(): void {
        $tarimas = [$this->guardada('A', 1), $this->guardada('B', 1), $this->guardada('C', 1), $this->guardada('D', 1), $this->guardada('E', 1)];
        $e = $this->embarque();
        [$t1, $t2, $t3, , $t5] = $tarimas;
        $fecha = now()->format('Ymd');
        $url = app(TarimaService::class)->urlDetalle($t5);

        $this->cargar($e, "TAR'{$t1->id}°{$fecha}")->assertSessionHasNoErrors();      // "-" → "'" y "|" → "°"
        $this->cargar($e, "]C0TAR?{$t2->id}¬{$fecha}\r")->assertSessionHasNoErrors(); // prefijo de simbología y retorno
        $this->cargar($e, "tar-{$t3->id}|{$fecha}")->assertSessionHasNoErrors();      // minúsculas
        $this->cargar($e, "T'004")->assertSessionHasNoErrors();
        $this->cargar($e, str_replace(['/', '-'], ["'", "'"], $url))->assertSessionHasNoErrors(); // QR con "/" y "-" cambiados

        $this->assertSame(5, $e->cargas()->count());
    }

    public function test_otra_mudanza_y_codigos_desconocidos(): void {
        $this->guardada('A');
        $e = $this->embarque();

        $this->cargar($e, 'XYZ-NO-EXISTE')->assertSessionHasErrors('codigo');
        $this->assertStringContainsString('No se reconoce', session('errors')->first('codigo'));
        $this->cargar($e, 'TAR-999999|20260101')->assertSessionHasErrors('codigo');
    }

    /** Cargas anteriores al cambio conservan su autorización (histórico, sin efecto). */
    public function test_cargas_historicas_con_autorizacion_se_conservan(): void {
        $this->guardada('A', 1);
        $e = $this->embarque();
        $autorizacion = DB::table('autorizaciones')->insertGetId([
            'mudanza_id' => $this->mudanza->id, 'folio' => 'AUT-0001', 'documento' => 'OTS 1', 'fecha_documento' => now()->toDateString(),
            'autorizado_por' => 'Cliente', 'estado' => 'vigente', 'total_partidas' => 1, 'hash_alcance' => str_repeat('0', 64),
            'registrada_by' => $this->sup()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $partida = Partida::sole();
        DB::table('embarque_partidas')->insert([
            'embarque_id' => $e->id, 'partida_id' => $partida->id, 'tarima_id' => $partida->tarima_id,
            'autorizacion_id' => $autorizacion, 'cargada_at' => now(), 'cargada_by' => $this->op()->id,
        ]);

        $this->cargar($e, 'E-001')->assertSessionHas('warn');
        $this->assertSame($autorizacion, (int) EmbarquePartida::sole()->autorizacion_id);
        $this->salir($e);
        $this->assertSame(Embarque::EN_TRANSITO, $e->fresh()->estado);
    }

    public function test_reporte_final_manifiesto_y_pantallas(): void {
        $this->guardada('A');
        $this->guardada('B');
        $e = $this->embarque();
        $this->cargar($e, 'E-001');
        $this->salir($e);
        $this->actingAs($this->op())->post(route('embarques.recibir', $e), ['codigo' => 'A-1']);

        $this->actingAs($this->op())->get(route('embarques.index'))->assertOk()->assertSee('TRL-0001')->assertSee('Sin cargar')->assertDontSee('Reporte final');
        $this->actingAs($this->op())->get(route('embarques.show', $e))->assertOk()->assertSee('Recibir')->assertSee('E-001')
            ->assertSee('data-anden', false)->assertSee('js/anden.js', false)->assertDontSee('Cerrar recepción');
        $this->actingAs($this->op())->get(route('embarques.reporte'))->assertForbidden();

        $leer = function ($respuesta): array {
            $ruta = tempnam(sys_get_temp_dir(), 'rep');
            file_put_contents($ruta, $respuesta->streamedContent());
            $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getSheetByName('Piezas');
            @unlink($ruta);
            $filas = $hoja->toArray();
            $this->assertSame('Situación', $filas[0][11]);
            $this->assertNotContains('Autorización', $filas[0]);

            return collect(array_slice($filas, 1))->mapWithKeys(fn ($f) => [$f[3] => $f[11]])->all();
        };

        $this->assertSame([
            'A-1' => 'Recibida',
            'A-2' => 'En tránsito',
            'B-1' => 'Sin cargar',
            'B-2' => 'Sin cargar',
        ], $leer($this->actingAs($this->sup())->get(route('embarques.reporte'))->assertOk()));

        // Manifiesto del tráiler: solo lo que va en él, con las ocho columnas. Lo puede bajar el operador.
        $respuesta = $this->actingAs($this->op())->get(route('embarques.manifiesto', $e))->assertOk()
            ->assertDownload('MUD-0001_TRL-0001_Manifiesto_'.now()->format('Y-m-d').'.xlsx');
        $ruta = tempnam(sys_get_temp_dir(), 'man');
        file_put_contents($ruta, $respuesta->streamedContent());
        $libro = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta);
        @unlink($ruta);
        $this->assertSame(['Manifiesto'], $libro->getSheetNames());
        $this->assertSame([
            \App\Services\Exportacion\ExcelExportador::MANIFIESTO,
            [1, '2364F', 'Bomba hidráulica', 1, 'PZ', null, 0, 'A-1'],
            [2, '2364F', 'Bomba hidráulica', 1, 'PZ', null, 0, 'A-2'],
        ], $libro->getSheet(0)->toArray(null, false, false));
    }

    public function test_el_auditor_crea_embarques_y_el_operador_no(): void {
        $auditor = $this->auditor();

        $this->actingAs($auditor)->get(route('embarques.index'))->assertOk()->assertSee('Nuevo tráiler');
        $this->actingAs($auditor)->post(route('embarques.store'), ['placas' => 'AUD-001'])->assertSessionHasNoErrors();
        $this->assertSame($auditor->id, Embarque::sole()->creado_by);

        $this->actingAs($this->op())->get(route('embarques.index'))->assertOk()->assertDontSee('Nuevo tráiler');
        $this->actingAs($this->op())->post(route('embarques.store'), ['placas' => 'OPE-001'])->assertForbidden();
        $this->assertSame(1, Embarque::count());
    }

    public function test_con_varios_movimientos_el_trailer_solo_carga_tarimas_del_suyo(): void {
        $this->mudanza->movimientos()->create([
            'almacen_origen_id' => $this->almacen('5')->id,
            'almacen_destino_id' => $this->almacen('7')->id,
        ]);
        [$m58, $m5] = $this->mudanza->movimientos()->orderBy('id')->get()->all();
        $this->discoFalso ??= Storage::fake('documentos');
        foreach ([[$m58, 'A'], [$m5, 'B']] as [$movimiento, $prefijo]) {
            $tarima = $this->tarima($this->op(), $movimiento->id);
            $this->activarPadre($this->op(), $tarima, "SKU-{$prefijo}");
            $this->escanearNs("{$prefijo}-1");
            $this->post(route('escaneo.padre.cerrar'));
            $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
            $this->postJson(route('tarimas.guardar', $tarima))->assertOk();
        }

        $this->actingAs($this->sup())->post(route('embarques.store'), ['placas' => 'ABC-123'])->assertSessionHasErrors('movimiento');
        $this->actingAs($this->sup())->post(route('embarques.store'), ['placas' => 'ABC-123', 'movimiento' => $m5->id])->assertSessionHasNoErrors();
        $embarque = Embarque::sole();
        $this->assertSame($m5->id, $embarque->mudanza_movimiento_id);

        $this->cargar($embarque, 'E-001')->assertSessionHasErrors('codigo');
        $this->assertStringContainsString('es del movimiento 58 → 3', session('errors')->first('codigo'));
        $this->cargar($embarque, 'E-002')->assertSessionHasNoErrors();
        $this->assertSame(1, $embarque->cargas()->count());
    }
}
