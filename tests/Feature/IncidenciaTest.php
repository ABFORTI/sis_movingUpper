<?php

namespace Tests\Feature;

use App\Models\Evidencia;
use App\Models\Incidencia;
use App\Models\IncidenciaEvento;
use App\Models\Partida;
use App\Models\Tarima;
use App\Models\User;
use App\Services\MudanzaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class IncidenciaTest extends TestCase {
    use PreparaWms;

    private ?User $capturista = null;

    private function op(): User {
        return $this->capturista ??= $this->operador();
    }

    /** Tarima guardada con N/S NS-1…NS-n (consecutivos 1…n). */
    private function guardada(int $n = 3): Tarima {
        Storage::fake('documentos');
        $tarima = $this->tarima($this->op());
        $this->activarPadre($this->op(), $tarima);
        foreach (range(1, $n) as $i) {
            $this->escanearNs("NS-{$i}");
        }
        $this->post(route('escaneo.padre.cerrar'));
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $tarima))->assertOk();

        return $tarima->fresh();
    }

    private function crear(array $datos = [], array $archivos = []): Incidencia {
        $this->actingAs($this->op())->post(route('incidencias.store'), $datos + [
            'tipo' => Incidencia::DANIO,
            'titulo' => 'Carcasa golpeada',
            'bloquea_traslado' => '1',
            'archivos' => $archivos,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Incidencia::latest('id')->first();
    }

    public function test_solo_existen_los_tipos_y_estados_operativos(): void {
        $this->assertSame(['no_en_inventario' => 'No encontrado en inventario', 'danio' => 'Producto dañado'], Incidencia::TIPOS);
        $this->assertSame(['abierta' => 'Abierta', 'resuelta' => 'Resuelta'], Incidencia::ESTADOS);

        // Un tipo retirado se rechaza en el servidor aunque se mande a mano.
        foreach (['no_en_sap', 'faltante', 'otro', 'etiqueta'] as $tipo) {
            $this->actingAs($this->op())->post(route('incidencias.store'), ['tipo' => $tipo, 'titulo' => 'Tipo retirado'])->assertSessionHasErrors('tipo');
        }
        $this->assertSame(0, Incidencia::count());
        $this->assertFalse(Route::has('incidencias.descartar'));
        $this->assertFalse(Route::has('incidencias.estado'));

        $this->get(route('incidencias.create'))->assertOk()
            ->assertSee('No encontrado en inventario')->assertSee('Producto dañado')
            ->assertDontSee('Escaneado pero no está en SAP')->assertDontSee('Etiqueta dañada');
        $this->get(route('incidencias.index'))->assertOk()
            ->assertDontSee('En investigación')->assertDontSee('Pendiente del cliente')->assertDontSee('Descartada');
    }

    public function test_el_operador_registra_un_producto_danado_con_piezas_escaneadas_y_foto(): void {
        $this->guardada();
        $foto = UploadedFile::fake()->image('pasillo-b.jpg', 640, 480);

        // Una N/S escaneada y un consecutivo escrito a mano, en renglones distintos.
        $incidencia = $this->crear(['piezas' => "NS-2\n3"], [$foto]);

        $this->assertSame('INC-0001', $incidencia->folio);
        $this->assertSame(Incidencia::DANIO, $incidencia->tipo);
        $this->assertSame(Incidencia::ABIERTA, $incidencia->estado);
        $this->assertTrue($incidencia->bloquea_traslado);
        $this->assertSame(['NS-2', 'NS-3'], $incidencia->partidas->pluck('numero_serie')->all());
        $this->assertSame([2, 3], Partida::bloqueadas()->orderBy('consecutivo')->pluck('consecutivo')->all());

        $evidencia = $incidencia->evidencias()->sole();
        $this->assertSame('pasillo-b.jpg', $evidencia->nombre_original);
        $this->assertSame(hash('sha256', Storage::disk('documentos')->get($evidencia->archivo)), $evidencia->sha256);
        $this->assertSame(
            [IncidenciaEvento::CREADA, IncidenciaEvento::PARTIDAS, IncidenciaEvento::EVIDENCIA],
            $incidencia->eventos->pluck('tipo')->all()
        );

        $this->get(route('incidencias.show', $incidencia))->assertOk()->assertSee('INC-0001')->assertSee('NS-2')->assertSee('pasillo-b.jpg');
        $this->get(route('wms'))->assertSee('nav-contador', false);
    }

    public function test_no_encontrado_manual_se_liga_a_una_sola_pieza_con_ns(): void {
        $this->guardada();

        $this->actingAs($this->op())->post(route('incidencias.store'), [
            'tipo' => Incidencia::NO_EN_INVENTARIO, 'titulo' => 'No está en el inventario', 'piezas' => "NS-1\nNS-2",
        ])->assertSessionHasErrors('piezas');

        $incidencia = $this->crear(['tipo' => Incidencia::NO_EN_INVENTARIO, 'titulo' => 'No está en el inventario', 'piezas' => 'NS-1', 'bloquea_traslado' => '0']);
        $this->assertSame(['NS-1', '2364F', '58'], [$incidencia->numero_serie, $incidencia->sku, $incidencia->almacen->codigo]);
        $this->assertTrue(Partida::where('numero_serie', 'NS-1')->sole()->pendienteRevision());

        // La misma N/S no tiene dos incidencias abiertas de este tipo.
        $this->actingAs($this->op())->post(route('incidencias.store'), [
            'tipo' => Incidencia::NO_EN_INVENTARIO, 'titulo' => 'Otra vez', 'piezas' => 'NS-1',
        ])->assertSessionHasErrors('piezas');
        $this->assertSame(1, Incidencia::count());
    }

    public function test_una_pieza_que_no_existe_no_crea_nada(): void {
        $this->guardada();

        $this->actingAs($this->op())->post(route('incidencias.store'), [
            'tipo' => Incidencia::DANIO, 'titulo' => 'Pieza rara', 'piezas' => "NS-1\nNO-EXISTE",
        ])->assertSessionHasErrors('piezas');

        $this->assertSame(0, Incidencia::count());
    }

    public function test_danio_sin_piezas_tambien_se_registra(): void {
        $incidencia = $this->crear(['titulo' => 'Montacargas golpeó un rack']);

        $this->assertSame(0, $incidencia->partidas()->count());
    }

    public function test_el_operador_comenta_pero_no_gestiona(): void {
        $this->guardada();
        $incidencia = $this->crear(['piezas' => 'NS-1']);
        $operador = $this->op();

        $this->actingAs($operador)->post(route('incidencias.comentar', $incidencia), ['comentario' => 'La encontré en el pasillo C'])
            ->assertRedirect(route('incidencias.show', $incidencia));
        $this->assertSame('La encontré en el pasillo C', $incidencia->eventos()->where('tipo', IncidenciaEvento::COMENTARIO)->value('detalle'));

        $this->actingAs($operador)->post(route('incidencias.resolver', $incidencia), ['tipo_resolucion' => 'localizado', 'resolucion' => 'Ya apareció'])->assertForbidden();
        $this->actingAs($operador)->post(route('incidencias.reabrir', $incidencia), ['nota' => 'Quiero reabrir'])->assertForbidden();
        $this->actingAs($operador)->post(route('incidencias.vincular', $incidencia), ['piezas' => 'NS-2'])->assertForbidden();
        $this->actingAs($operador)->post(route('incidencias.asignar', $incidencia), ['responsable_id' => $operador->id])->assertForbidden();
        $this->assertSame(Incidencia::ABIERTA, $incidencia->fresh()->estado);
    }

    public function test_una_decision_del_cliente_exige_evidencia_y_al_resolver_deja_de_bloquear(): void {
        $this->guardada();
        $incidencia = $this->crear(['piezas' => 'NS-1']);
        $auditor = $this->auditor();

        $this->actingAs($auditor)->post(route('incidencias.asignar', $incidencia), ['responsable_id' => $auditor->id])->assertSessionHasNoErrors();

        $this->actingAs($auditor)->post(route('incidencias.resolver', $incidencia), [
            'tipo_resolucion' => 'aceptado_cliente', 'resolucion' => 'El cliente acepta trasladarla',
        ])->assertSessionHasErrors('archivos');
        $this->assertSame(1, Partida::bloqueadas()->count());

        $correo = UploadedFile::fake()->create('respuesta-cliente.pdf', 120, 'application/pdf');
        $this->actingAs($auditor)->post(route('incidencias.resolver', $incidencia), [
            'tipo_resolucion' => 'aceptado_cliente', 'resolucion' => 'El cliente acepta trasladarla', 'archivos' => [$correo],
        ])->assertSessionHasNoErrors();

        $incidencia->refresh();
        $this->assertSame(Incidencia::RESUELTA, $incidencia->estado);
        $this->assertSame($auditor->id, $incidencia->resuelta_by);
        $this->assertSame($auditor->id, $incidencia->responsable_id);
        $this->assertSame(0, Partida::bloqueadas()->count());
    }

    public function test_resolver_y_reabrir_con_motivo(): void {
        $incidencia = $this->crear(['titulo' => 'Caja aplastada']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('incidencias.reabrir', $incidencia), ['nota' => 'No aplica'])->assertSessionHasErrors('nota');
        $this->actingAs($admin)->post(route('incidencias.resolver', $incidencia), ['tipo_resolucion' => 'descartada', 'resolucion' => 'Revisada: el producto está bien'])->assertSessionHasNoErrors();
        $this->assertSame(Incidencia::RESUELTA, $incidencia->fresh()->estado);

        $this->actingAs($admin)->post(route('incidencias.reabrir', $incidencia), ['nota' => ''])->assertSessionHasErrors('nota');
        $this->actingAs($admin)->post(route('incidencias.reabrir', $incidencia), ['nota' => 'Se encontró otro golpe'])->assertSessionHasNoErrors();

        $incidencia->refresh();
        $this->assertSame(Incidencia::ABIERTA, $incidencia->estado);
        $this->assertNull($incidencia->resolucion);
        $this->assertStringContainsString('Reabierta: Se encontró otro golpe', $incidencia->eventos()->reorder('id', 'desc')->value('detalle'));
    }

    public function test_la_mudanza_no_se_cierra_con_incidencias_abiertas(): void {
        $incidencia = $this->crear();
        $admin = $this->admin();
        app(MudanzaService::class)->transicionar($this->mudanza, MudanzaService::INICIAR_CIERRE, $admin);

        try {
            app(MudanzaService::class)->transicionar($this->mudanza, MudanzaService::CERRAR, $admin);
            $this->fail('Se cerró con incidencias abiertas.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('INC-0001', $e->errors()['accion'][0]);
        }

        $this->actingAs($admin)->post(route('incidencias.resolver', $incidencia), ['tipo_resolucion' => 'descartada', 'resolucion' => 'Duplicada de otra']);
        app(MudanzaService::class)->transicionar($this->mudanza, MudanzaService::CERRAR, $admin);
        $this->assertSame('cerrada', $this->mudanza->fresh()->estado);
    }

    public function test_la_evidencia_se_entrega_solo_si_no_fue_alterada(): void {
        Storage::fake('documentos');
        $incidencia = $this->crear([], [UploadedFile::fake()->image('dano.png')]);
        $evidencia = $incidencia->evidencias()->sole();

        $this->get(route('evidencias.ver', $evidencia))->assertOk()->assertHeader('X-Content-SHA256', $evidencia->sha256);

        Storage::disk('documentos')->put($evidencia->archivo, 'otra cosa');
        $this->get(route('evidencias.ver', $evidencia))->assertStatus(409);
    }

    public function test_archivos_no_permitidos_se_rechazan(): void {
        Storage::fake('documentos');
        $this->actingAs($this->op())->post(route('incidencias.store'), [
            'tipo' => Incidencia::DANIO, 'titulo' => 'Con archivo raro', 'archivos' => [UploadedFile::fake()->create('script.php', 1)],
        ])->assertSessionHasErrors('archivos.0');

        $this->assertSame(0, Incidencia::count());
        $this->assertSame(0, Evidencia::count());
    }

    public function test_el_historial_y_las_evidencias_no_se_borran(): void {
        $incidencia = $this->crear();

        $this->expectException(\LogicException::class);
        $incidencia->eventos()->first()->delete();
    }

    public function test_listado_por_estado_y_tipos_historicos(): void {
        $abierta = $this->crear(['titulo' => 'Abierta uno']);
        $resuelta = $this->crear(['titulo' => 'Resuelta dos']);
        $this->actingAs($this->admin())->post(route('incidencias.resolver', $resuelta), ['tipo_resolucion' => 'descartada', 'resolucion' => 'No procede']);
        // Una incidencia anterior a la simplificación conserva su tipo y se muestra como histórica.
        $vieja = Incidencia::forceCreate([
            'mudanza_id' => $this->mudanza->id, 'folio' => 'INC-0099', 'tipo' => 'no_en_sap', 'estado' => Incidencia::ABIERTA,
            'titulo' => 'Vieja de SAP', 'estado_legado' => 'pendiente_cliente',
        ]);

        $this->get(route('incidencias.index'))->assertOk()->assertSee('Abierta uno')->assertDontSee('Resuelta dos')
            ->assertSee('Escaneado pero no está en SAP (histórico)')->assertSee('antes: pendiente del cliente');
        $this->get(route('incidencias.index', ['estado' => Incidencia::RESUELTA]))->assertSee('Resuelta dos')->assertDontSee('Abierta uno');
        $this->get(route('incidencias.index', ['estado' => 'todas']))->assertSee('Abierta uno')->assertSee('Resuelta dos');
        $this->get(route('incidencias.index', ['tipo' => 'historicos']))->assertSee('Vieja de SAP')->assertDontSee('Abierta uno');
        $this->assertSame('INC-0002', $resuelta->folio);
        $this->assertTrue($abierta->fresh()->estaVigente());
        $this->assertTrue($vieja->estaVigente());
    }
}
