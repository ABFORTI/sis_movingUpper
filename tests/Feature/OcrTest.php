<?php

namespace Tests\Feature;

use App\Models\NumeroSerie;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/**
 * "Foto del N/S": Gemini lee el SKU y la N/S de la etiqueta. Solo propone
 * valores (no guarda la foto ni registra la N/S) y funciona sin SKU activo.
 */
class OcrTest extends TestCase {
    use PreparaWms {
        setUp as prepararWms;
    }

    protected function setUp(): void {
        $this->prepararWms();
        config(['services.gemini.api_key' => 'clave-de-prueba', 'services.gemini.model' => 'gemini-3.5-flash-lite']);
    }

    private function gemini(array $lectura, int $status = 200): void {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($lectura + ['observaciones' => []])]]], 'finishReason' => 'STOP']],
        ], $status)]);
    }

    private function foto(): TestResponse {
        return $this->postJson(route('escaneo.ocr'), ['imagen' => UploadedFile::fake()->image('etiqueta.jpg', 800, 600)]);
    }

    public function test_sin_sku_activo_propone_sku_y_ns_sin_registrar_nada(): void {
        Storage::fake();
        $this->actingAs($this->operador());
        $this->gemini(['sku' => 'REF-100', 'modelo' => 'RT38', 'numero_serie' => 'SN-0001']);

        $this->foto()->assertOk()->assertExactJson([
            'sku' => 'REF-100', 'modelo' => 'RT38', 'numero_serie' => 'SN-0001', 'sku_activo' => null, 'avisos' => [],
        ]);

        Http::assertSent(fn (PeticionHttp $r) => str_contains($r->url(), 'models/gemini-3.5-flash-lite:generateContent')
            && $r->hasHeader('x-goog-api-key', 'clave-de-prueba')
            && $r['generationConfig']['temperature'] === 0
            && $r['contents'][0]['parts'][1]['inline_data']['mime_type'] === 'image/jpeg');
        $this->assertSame(0, NumeroSerie::count(), 'El OCR no registra: la N/S se confirma en el flujo de escaneo.');
        $this->assertSame([], Storage::allFiles(), 'La foto no se guarda.');
    }

    public function test_ns_igual_al_sku_se_descarta(): void {
        $this->actingAs($this->operador());
        $this->gemini(['sku' => 'REF-100', 'modelo' => null, 'numero_serie' => 'ref-100']);

        $this->foto()->assertOk()->assertJsonPath('numero_serie', null)->assertJsonPath('sku', 'REF-100')
            ->assertJsonPath('avisos.0', fn (string $a) => str_contains($a, 'parece el SKU o modelo'));
    }

    public function test_con_sku_activo_avisa_si_la_etiqueta_es_de_otro_producto(): void {
        $operador = $this->operador();
        $this->activarPadre($operador, $this->tarima($operador), 'REF-100');
        $this->gemini(['sku' => 'EST-200', 'modelo' => null, 'numero_serie' => 'SN-0002']);

        $this->foto()->assertOk()->assertJsonPath('numero_serie', 'SN-0002')->assertJsonPath('sku_activo', 'REF-100')
            ->assertJsonPath('avisos.0', fn (string $a) => str_contains($a, 'otro producto (EST-200)'));
    }

    public function test_valores_que_no_parecen_codigo_no_se_corrigen_se_descartan(): void {
        $this->actingAs($this->operador());
        $this->gemini(['sku' => 'REF 100', 'modelo' => '', 'numero_serie' => 'SN-00?1']);

        $this->foto()->assertOk()->assertJsonPath('sku', null)->assertJsonPath('numero_serie', null)
            ->assertJsonPath('avisos.0', fn (string $a) => str_contains($a, 'No se leyó ningún dato'));
    }

    public function test_errores_de_gemini_devuelven_mensaje_util(): void {
        $this->actingAs($this->operador());

        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'quota']], 429)
            ->push(['candidates' => [['finishReason' => 'SAFETY']]])]);

        $this->foto()->assertStatus(429)->assertJsonPath('message', fn (string $m) => str_contains($m, 'límite'));
        $this->foto()->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'otra foto'));

        config(['services.gemini.api_key' => null]);
        $this->foto()->assertStatus(503);
    }

    public function test_valida_el_archivo_y_la_sesion(): void {
        Http::fake();
        $this->postJson(route('escaneo.ocr'), ['imagen' => UploadedFile::fake()->image('e.jpg')])->assertUnauthorized();

        $this->actingAs($this->operador());
        $this->postJson(route('escaneo.ocr'))->assertJsonValidationErrors('imagen');
        $this->postJson(route('escaneo.ocr'), ['imagen' => UploadedFile::fake()->create('e.pdf', 10, 'application/pdf')])->assertJsonValidationErrors('imagen');
        $this->postJson(route('escaneo.ocr'), ['imagen' => UploadedFile::fake()->image('e.jpg', 50, 50)])->assertJsonValidationErrors('imagen');
        Http::assertNothingSent();
    }
}
