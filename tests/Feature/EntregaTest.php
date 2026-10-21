<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Entrega;
use App\Models\EntregaPartida;
use App\Models\Mudanza;
use App\Models\Partida;
use App\Models\Tarima;
use App\Models\User;
use App\Services\EntregaService;
use App\Services\IncidenciaService;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class EntregaTest extends TestCase {
    use PreparaWms;

    private ?User $capturista = null;

    private function op(): User {
        return $this->capturista ??= $this->operador();
    }

    private bool $discoFalso = false;

    private function guardada(array $series, string $sku = 'SKU-P'): Tarima {
        if (! $this->discoFalso) {
            Storage::fake('documentos');
            $this->discoFalso = true;
        }
        $tarima = $this->tarima($this->op());
        $this->activarPadre($this->op(), $tarima, $sku);
        foreach ($series as $ns) {
            $this->escanearNs($ns)->assertSessionHasNoErrors();
        }
        $this->post(route('escaneo.padre.cerrar'));
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $tarima))->assertOk();

        return $tarima->fresh();
    }

    private function libro(Entrega $entrega): Spreadsheet {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($ruta, Storage::disk('documentos')->get($entrega->archivo));
        try {
            return IOFactory::load($ruta);
        } finally {
            @unlink($ruta);
        }
    }

    private function hoja(Entrega $entrega, string $nombre): array {
        return $this->libro($entrega)->getSheetByName($nombre)->toArray(null, false, false);
    }

    private function generar(?User $quien = null): Entrega {
        $this->actingAs($quien ?? $this->auditor())
            ->post(route('entregas.store'), ['mudanza' => $this->mudanza->id, 'notas' => 'Para conciliación'])
            ->assertRedirect(route('entregas.index', ['mudanza' => $this->mudanza->id, 'fecha' => now()->format('Y-m-d')]))
            ->assertSessionHasNoErrors();

        return Entrega::latest('id')->first();
    }

    public function test_la_primera_entrega_guarda_el_archivo_con_su_huella_y_la_copia_de_cada_pieza(): void {
        $this->guardada(['NS-1', 'NS-2']);

        $entrega = $this->generar();

        $this->assertSame(1, $entrega->version);
        $this->assertSame(2, $entrega->total_partidas);
        $contenido = Storage::disk('documentos')->get($entrega->archivo);
        $this->assertSame(hash('sha256', $contenido), $entrega->sha256);
        $this->assertSame(strlen($contenido), $entrega->tamano);

        $inventario = $this->hoja($entrega, 'Inventario');
        $this->assertSame('Novedad', $inventario[0][9]);
        $this->assertSame('Incidencias abiertas', $inventario[0][10]);
        $this->assertNotContains('Categoría', $inventario[0]);
        $this->assertSame([1, 2], array_column(array_slice($inventario, 1), 0));
        $this->assertSame(['NS-1', 'NS-2'], array_column(array_slice($inventario, 1), 6));

        $control = array_column($this->hoja($entrega, 'Control'), 1, 0);
        $this->assertSame('MUD-0001', $control['Mudanza']);
        $this->assertSame(1, $control['Versión del reporte']);
        $this->assertSame(now()->format('Y-m-d'), $control['Día del reporte']);
        $this->assertSame('Primer reporte del día', $control['Compara contra']);
        $this->assertSame('Para conciliación', $control['Notas']);

        $this->assertSame(2, EntregaPartida::where('entrega_id', $entrega->id)->count());
        $this->assertSame($entrega->sha256, Bitacora::where('accion', 'entrega.generada')->sole()->datos['sha256']);
    }

    public function test_la_columna_trailer_muestra_el_folio_del_trailer_en_lugar_del_embarque(): void {
        $cargada = $this->guardada(['NS-1']);
        $this->guardada(['NS-2']);
        $this->actingAs($this->auditor())->post(route('embarques.store'), ['placas' => 'ABC-123'])->assertSessionHasNoErrors();
        $trailer = \App\Models\Embarque::latest('id')->first();
        $this->post(route('embarques.cargar', $trailer), ['codigo' => $cargada->identificador])->assertSessionHasNoErrors();

        $inventario = $this->hoja($this->generar(), 'Inventario');
        $this->assertSame('Tráiler', $inventario[0][7]);
        $this->assertNotContains('Embarque', $inventario[0]);
        // La que aún no se carga va sin folio.
        $this->assertSame(['NS-1' => 'TRL-0001', 'NS-2' => null], array_column(array_slice($inventario, 1), 7, 6));
    }

    public function test_la_siguiente_version_marca_nuevas_cambios_de_tarima_y_las_que_salieron(): void {
        $a = $this->guardada(['NS-1', 'NS-2', 'NS-3']);
        $b = $this->guardada(['NS-4'], 'SKU-B');
        $v1 = $this->generar();
        $admin = $this->admin();

        // Después de entregar v1: se mueve NS-2 a B, se retira NS-3 y llega una tarima nueva.
        $id = fn (string $ns) => Partida::where('numero_serie', $ns)->value('id');
        $this->actingAs($admin)->post(route('tarimas.partidas.mover', $a), ['partidas' => [$id('NS-2')], 'destino' => $b->uuid, 'motivo' => 'Reacomodo'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('tarimas.partidas.retirar', $a), ['partidas' => [$id('NS-3')], 'motivo' => 'No es del cliente'])->assertSessionHasNoErrors();
        $this->guardada(['NS-5'], 'SKU-C');

        $v2 = $this->generar();

        $this->assertSame(2, $v2->version);
        $this->assertSame([4, 1, 1, 1], [$v2->total_partidas, $v2->nuevas, $v2->cambiadas, $v2->salieron]);
        $novedades = array_column(array_slice($this->hoja($v2, 'Inventario'), 1), 9, 6);
        $this->assertSame(['NS-1' => null, 'NS-2' => 'Cambió de embarque', 'NS-4' => null, 'NS-5' => 'Nueva'], $novedades);
        $salieron = array_slice($this->hoja($v2, 'Salieron'), 1);
        $this->assertSame([[3, 'SKU-P', 'NS-3', 'E-001', 'Retirada']], $salieron);

        // v1 sigue intacta: mismo archivo, misma copia (NS-2 seguía en la E-001).
        $this->assertSame($v1->sha256, hash('sha256', Storage::disk('documentos')->get($v1->archivo)));
        $this->assertSame(1, EntregaPartida::where('entrega_id', $v1->id)->where('numero_serie', 'NS-2')->value('tarima_numero'));
        $this->assertSame(4, EntregaPartida::where('entrega_id', $v1->id)->count());
    }

    public function test_la_columna_de_incidencias_muestra_las_abiertas(): void {
        $this->guardada(['NS-1', 'NS-2']);
        app(IncidenciaService::class)->crear($this->mudanza, ['tipo' => 'danio', 'titulo' => 'Carcasa golpeada', 'piezas' => 'NS-2'], $this->op());

        $inventario = array_slice($this->hoja($this->generar(), 'Inventario'), 1);

        $this->assertSame([null, 'INC-0001'], array_column($inventario, 10));
    }

    public function test_descargar_comprueba_que_el_archivo_no_cambio(): void {
        $this->guardada(['NS-1']);
        $entrega = $this->generar();

        $respuesta = $this->get(route('entregas.descargar', $entrega))->assertOk();
        $this->assertSame($entrega->sha256, $respuesta->headers->get('X-Content-SHA256'));
        $this->assertStringContainsString('MUD-0001_Reporte_Diario_'.now()->format('Y-m-d').'_v1.xlsx', $respuesta->headers->get('Content-Disposition'));
        $this->assertSame($entrega->sha256, hash('sha256', $respuesta->streamedContent()));

        Storage::disk('documentos')->put($entrega->archivo, 'alterado');
        $this->get(route('entregas.descargar', $entrega))->assertStatus(409);
    }

    public function test_solo_supervision_entrega(): void {
        $this->guardada(['NS-1']);
        $operador = $this->op();

        $this->actingAs($operador)->get(route('entregas.index'))->assertForbidden();
        $this->actingAs($operador)->post(route('entregas.store'), ['mudanza' => $this->mudanza->id])->assertForbidden();
        $this->assertSame(0, Entrega::count());

        $entrega = $this->generar($this->admin());
        $this->actingAs($operador)->get(route('entregas.descargar', $entrega))->assertForbidden();
    }

    public function test_avisa_de_tarimas_en_captura_y_sin_piezas_no_entrega(): void {
        Storage::fake('documentos');
        $auditor = $this->auditor();
        $this->actingAs($auditor)->post(route('entregas.store'), ['mudanza' => $this->mudanza->id])->assertSessionHasErrors('entrega');

        $this->guardada(['NS-1']);
        $abierta = $this->tarima($this->op());
        $this->capturarPieza($this->op(), $abierta, 'CAJA');

        $this->actingAs($auditor)->get(route('entregas.index'))->assertOk()
            ->assertSee('Reporte Diario')->assertSee('Revisa antes de entregar')->assertSee($abierta->codigo)
            ->assertSee('Generar reporte del '.now()->format('d/m/Y'))->assertSee('Primer reporte de este día.');
    }

    public function test_una_entrega_no_se_modifica_ni_se_borra(): void {
        $this->guardada(['NS-1']);
        $entrega = $this->generar();

        $this->expectException(\LogicException::class);
        $entrega->update(['notas' => 'cambio']);
    }

    public function test_versiones_por_mudanza(): void {
        $this->guardada(['NS-1']);
        $this->generar();
        $this->generar();

        $this->assertSame([1, 2], Entrega::orderBy('id')->pluck('version')->all());
        $this->assertSame(2, (int) \DB::table('contadores')->where('clave', EntregaService::claveVersion($this->mudanza))->value('valor'));
    }
}
