<?php

namespace Tests\Feature;

use App\Enums\VisibilidadManual;
use App\Models\Manual;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class ManualesTest extends TestCase {
    use PreparaWms {
        setUp as prepararWms;
    }

    protected function setUp(): void {
        $this->prepararWms();
        Storage::fake(Manual::DISCO);
    }

    private function pdf(string $nombre = 'manual.pdf'): UploadedFile {
        return UploadedFile::fake()->createWithContent($nombre, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    private function subir(User $admin, string $nombre, VisibilidadManual $visibilidad): Manual {
        $this->actingAs($admin)->post(route('manuales.store'), [
            'nombre' => $nombre,
            'visible_para' => $visibilidad->value,
            'archivo' => $this->pdf(),
        ])->assertRedirect(route('manuales.index'))->assertSessionHasNoErrors();

        return Manual::where('nombre', $nombre)->firstOrFail();
    }

    /** @return array{0: Manual, 1: Manual, 2: Manual} A: ambos, B: solo operador, C: solo auditor */
    private function tresManuales(): array {
        $admin = $this->admin();

        return [
            $this->subir($admin, 'Manual A', VisibilidadManual::OperadorYAuditor),
            $this->subir($admin, 'Manual B', VisibilidadManual::Operador),
            $this->subir($admin, 'Manual C', VisibilidadManual::Auditor),
        ];
    }

    // ---------- Visibilidad por rol ----------

    public function test_cada_rol_ve_solo_sus_manuales_en_el_listado(): void {
        $this->tresManuales();
        $this->flushSession();

        $this->actingAs($this->operador())->get(route('manuales.index'))->assertOk()
            ->assertSee('Manual A')->assertSee('Manual B')->assertDontSee('Manual C');

        $this->actingAs($this->auditor())->get(route('manuales.index'))->assertOk()
            ->assertSee('Manual A')->assertDontSee('Manual B')->assertSee('Manual C');

        $this->actingAs($this->admin())->get(route('manuales.index'))->assertOk()
            ->assertSee('Manual A')->assertSee('Manual B')->assertSee('Manual C');
    }

    public function test_operador_no_abre_un_manual_solo_de_auditor_por_url(): void {
        [$a, $b, $c] = $this->tresManuales();
        $operador = $this->operador();

        $this->actingAs($operador)->get(route('manuales.ver', $c))->assertForbidden();
        $this->actingAs($operador)->get(route('manuales.descargar', $c))->assertForbidden();

        $this->actingAs($operador)->get(route('manuales.ver', $a))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($operador)->get(route('manuales.ver', $b))->assertOk();
    }

    public function test_auditor_no_abre_un_manual_solo_de_operador_por_url(): void {
        [, $b, $c] = $this->tresManuales();
        $auditor = $this->auditor();

        $this->actingAs($auditor)->get(route('manuales.ver', $b))->assertForbidden();
        $this->actingAs($auditor)->get(route('manuales.descargar', $b))->assertForbidden();
        $this->actingAs($auditor)->get(route('manuales.ver', $c))->assertOk();
    }

    public function test_ver_es_inline_y_descargar_es_adjunto_con_cabeceras_seguras(): void {
        [$a] = $this->tresManuales();
        $admin = $this->admin();

        $ver = $this->actingAs($admin)->get(route('manuales.ver', $a))->assertOk();
        $this->assertStringStartsWith('inline', $ver->headers->get('Content-Disposition'));
        $ver->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $ver->headers->get('Cache-Control'));

        $descarga = $this->actingAs($admin)->get(route('manuales.descargar', $a))->assertOk();
        $this->assertStringContainsString('attachment; filename=manual-a.pdf', $descarga->headers->get('Content-Disposition'));
    }

    public function test_sin_sesion_no_se_accede_a_manuales(): void {
        [$a] = $this->tresManuales();
        auth()->logout();

        $this->get(route('manuales.index'))->assertRedirect(route('login'));
        $this->get(route('manuales.ver', $a))->assertRedirect(route('login'));
    }

    // ---------- Administración ----------

    public function test_solo_admin_administra_manuales(): void {
        [$a] = $this->tresManuales();

        foreach ([$this->operador(), $this->auditor()] as $usuario) {
            $this->actingAs($usuario)->get(route('manuales.create'))->assertForbidden();
            $this->actingAs($usuario)->post(route('manuales.store'), [
                'nombre' => 'Intruso', 'visible_para' => 'operador', 'archivo' => $this->pdf(),
            ])->assertForbidden();
            $this->actingAs($usuario)->get(route('manuales.edit', $a))->assertForbidden();
            $this->actingAs($usuario)->put(route('manuales.update', $a), ['nombre' => 'X', 'visible_para' => 'operador'])->assertForbidden();
            $this->actingAs($usuario)->delete(route('manuales.destroy', $a))->assertForbidden();
        }

        $this->assertSame(3, Manual::count());
        $this->assertSame('Manual A', $a->fresh()->nombre);
    }

    public function test_el_pdf_se_guarda_en_disco_privado_con_nombre_generado(): void {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('manuales.store'), [
            'nombre' => '  <b>Manual</b>   de   escáner ',
            'visible_para' => VisibilidadManual::Operador->value,
            'archivo' => $this->pdf('../../public/shell.pdf'),
        ])->assertSessionHasNoErrors();

        $manual = Manual::firstOrFail();
        $this->assertSame('Manual de escáner', $manual->nombre);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.pdf$/', $manual->archivo);
        $this->assertSame($admin->id, $manual->subido_by);
        Storage::disk(Manual::DISCO)->assertExists($manual->archivo);
        $this->assertNull(config('filesystems.disks.manuales.url'));
    }

    public function test_editar_datos_y_cambiar_visibilidad_sin_reemplazar_pdf(): void {
        [, $b] = $this->tresManuales();
        $archivo = $b->archivo;

        $this->actingAs($this->admin())->put(route('manuales.update', $b), [
            'nombre' => 'Manual B revisado',
            'visible_para' => VisibilidadManual::Auditor->value,
        ])->assertRedirect(route('manuales.index'))->assertSessionHasNoErrors();

        $b->refresh();
        $this->assertSame('Manual B revisado', $b->nombre);
        $this->assertSame(VisibilidadManual::Auditor, $b->visible_para);
        $this->assertSame($archivo, $b->archivo);

        // El cambio de visibilidad se aplica de inmediato.
        $this->actingAs($this->operador())->get(route('manuales.ver', $b))->assertForbidden();
        $this->actingAs($this->auditor())->get(route('manuales.ver', $b))->assertOk();
    }

    public function test_reemplazar_pdf_borra_el_anterior(): void {
        [$a] = $this->tresManuales();
        $anterior = $a->archivo;

        $this->actingAs($this->admin())->put(route('manuales.update', $a), [
            'nombre' => $a->nombre,
            'visible_para' => $a->visible_para->value,
            'archivo' => $this->pdf('nuevo.pdf'),
        ])->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertNotSame($anterior, $a->archivo);
        Storage::disk(Manual::DISCO)->assertMissing($anterior);
        Storage::disk(Manual::DISCO)->assertExists($a->archivo);
    }

    public function test_eliminar_borra_registro_y_archivo(): void {
        [$a] = $this->tresManuales();

        $this->actingAs($this->admin())->delete(route('manuales.destroy', $a))->assertRedirect(route('manuales.index'));

        $this->assertModelMissing($a);
        Storage::disk(Manual::DISCO)->assertMissing($a->archivo);
    }

    // ---------- Validación del archivo ----------

    public function test_rechaza_archivos_que_no_son_pdf_aunque_digan_pdf(): void {
        $admin = $this->admin();
        $casos = [
            'php renombrado' => UploadedFile::fake()->createWithContent('malo.pdf', "<?php system(\$_GET['c']); ?>"),
            'html renombrado' => UploadedFile::fake()->createWithContent('malo.pdf', '<html><script>alert(1)</script></html>'),
            'pdf con otra extensión' => UploadedFile::fake()->createWithContent('manual.php', "%PDF-1.4\n%%EOF\n"),
            'imagen' => UploadedFile::fake()->image('foto.png'),
        ];

        foreach ($casos as $caso => $archivo) {
            $this->actingAs($admin)->post(route('manuales.store'), [
                'nombre' => "Prueba {$caso}", 'visible_para' => 'operador', 'archivo' => $archivo,
            ])->assertSessionHasErrors('archivo');
        }

        $this->assertSame(0, Manual::count());
        $this->assertSame([], Storage::disk(Manual::DISCO)->allFiles());
    }

    public function test_rechaza_pdf_mayor_al_limite_y_campos_invalidos(): void {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('manuales.store'), [
            'nombre' => 'Muy pesado', 'visible_para' => 'operador',
            'archivo' => UploadedFile::fake()->create('grande.pdf', Manual::TAMANO_MAXIMO_KB + 1, 'application/pdf'),
        ])->assertSessionHasErrors('archivo');

        $this->actingAs($admin)->post(route('manuales.store'), [
            'nombre' => '', 'visible_para' => 'admin', 'archivo' => $this->pdf(),
        ])->assertSessionHasErrors(['nombre', 'visible_para']);

        $this->actingAs($admin)->post(route('manuales.store'), [
            'nombre' => 'Sin archivo', 'visible_para' => 'operador',
        ])->assertSessionHasErrors('archivo');

        $this->assertSame(0, Manual::count());
    }

    public function test_el_menu_muestra_manuales_a_todos_los_roles(): void {
        foreach ([$this->admin(), $this->auditor(), $this->operador()] as $usuario) {
            $this->actingAs($usuario)->get(route('wms'))->assertOk()->assertSee(route('manuales.index'));
        }
    }
}
