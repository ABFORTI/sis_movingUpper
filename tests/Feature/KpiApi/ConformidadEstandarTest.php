<?php

namespace Tests\Feature\KpiApi;

use App\KpiApi\KpiApi;
use App\KpiApi\KpiRegistry;
use App\KpiApi\Periodo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;


class ConformidadEstandarTest extends TestCase {
    use RefreshDatabase;

    private const VERSION = 'v3';
    private const SOBRE = ['exito', 'version_api', 'sistema', 'kpi', 'periodo', 'filtros', 'datos', 'metadatos', 'errores'];

    protected function setUp(): void {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', config('app.timezone')));
    }

    private function cuentaDeServicio(): User {
        return User::factory()->create(['email' => 'kpi-central@upperlogistics.mx']);
    }

    private function token(array $abilities = [KpiApi::ABILITY]): string {
        return $this->cuentaDeServicio()->createToken('kpi-central', $abilities)->plainTextToken;
    }

    private function api(string $ruta, ?string $token = null): TestResponse {
        return $this->withToken($token ?? $this->token())->getJson('/api/'.self::VERSION.$ruta);
    }

    private function assertSobre(TestResponse $r, bool $exito): void {
        $this->assertSame(self::SOBRE, array_keys($r->json()));
        $this->assertSame($exito, $r->json('exito'));
        $this->assertSame(self::VERSION, $r->json('version_api'));
        $this->assertSame(['clave' => 'movingupper', 'nombre' => config('kpi-api.sistema.nombre'), 'entorno' => 'testing'], $r->json('sistema'));
        $this->assertSame(['total_registros', 'paginacion', 'generado_en', 'id_peticion', 'version_estandar'], array_keys($r->json('metadatos')));
        $this->assertSame(KpiApi::VERSION_ESTANDAR, $r->json('metadatos.version_estandar'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $r->json('metadatos.generado_en'));

        $r->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-Api-Version', self::VERSION)
            ->assertHeader('X-Source-System', 'movingupper')
            ->assertHeader('X-Request-Id', $r->json('metadatos.id_peticion'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));

        if ($exito) {
            $this->assertSame([], $r->json('errores'));
            $this->assertIsArray($r->json('datos'));
        } else {
            $this->assertNull($r->json('datos'));
            $this->assertNotEmpty($r->json('errores'));
            $this->assertSame(['codigo', 'mensaje', 'campo'], array_keys($r->json('errores.0')));
        }
    }

    private function assertError(TestResponse $r, int $status, string $codigo, ?string $campo = null): void {
        $r->assertStatus($status);
        $this->assertSobre($r, false);
        $this->assertSame($codigo, $r->json('errores.0.codigo'));
        $this->assertSame($campo, $r->json('errores.0.campo'));
    }

    public function test_sistema_identifica_al_sistema_y_la_version(): void {
        $r = $this->api('/sistema')->assertOk();

        $this->assertSobre($r, true);
        $this->assertSame('movingupper', $r->json('datos.0.clave'));
        $this->assertSame(['v3'], $r->json('datos.0.versiones_disponibles'));
        $this->assertSame('America/Mexico_City', $r->json('datos.0.zona_horaria'));
        $this->assertSame(count(config('kpi-api.versiones.v3')), $r->json('datos.0.total_kpis'));
        $r->assertHeader('X-RateLimit-Limit', '60');
    }

    public function test_catalogo_describe_cada_kpi_configurado(): void {
        $r = $this->api('/kpis')->assertOk();
        $this->assertSobre($r, true);

        $claves = array_column($r->json('datos'), 'clave');
        $this->assertSame([
            'numeros-serie-escaneados', 'tarimas-registradas', 'skus-registrados', 'etiquetas-impresas', 'productividad-por-usuario',
        ], $claves);

        foreach ($r->json('datos') as $kpi) {
            $this->assertSame(['clave', 'nombre', 'descripcion', 'unidad', 'endpoint', 'agrupaciones', 'agrupacion_default', 'filtros', 'parametros', 'campos', 'paginable'], array_keys($kpi));
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $kpi['clave']);
            $this->assertSame("/api/v3/kpis/{$kpi['clave']}", $kpi['endpoint']);
            $this->assertContains($kpi['agrupacion_default'], $kpi['agrupaciones']);
            $this->assertEmpty(array_diff($kpi['agrupaciones'], Periodo::AGRUPACIONES));

            foreach ($kpi['filtros'] as $filtro) {
                $this->assertContains($filtro['nombre'], KpiApi::FILTROS_GENERALES);
            }
            foreach ($kpi['campos'] as $campo) {
                $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $campo['nombre']);
                $this->assertNotContains($campo['nombre'], ['periodo', 'periodo_inicio', 'periodo_fin'], 'Nombre reservado');
                $this->assertNotEmpty($campo['descripcion']);
            }
        }
    }

    public function test_cada_kpi_en_cada_agrupacion_entrega_serie_sin_huecos_con_los_campos_declarados(): void {
        $token = $this->token();
        $inicio = Carbon::parse('2026-01-10');
        $fin = Carbon::parse('2026-10-06 23:59:59');

        foreach (app(KpiRegistry::class)->deVersion(self::VERSION) as $kpi) {
            $campos = array_keys($kpi->campos());

            foreach ($kpi->agrupaciones() as $agrupacion) {
                $r = $this->api("/kpis/{$kpi->clave()}?fecha_inicio=2026-01-10&fecha_fin=2026-10-06&agrupacion={$agrupacion}", $token)
                    ->assertOk();
                $this->assertSobre($r, true);
                $this->assertSame(['clave' => $kpi->clave(), 'nombre' => $kpi->nombre(), 'unidad' => $kpi->unidad()], $r->json('kpi'));
                $this->assertSame(['fecha_inicio' => '2026-01-10', 'fecha_fin' => '2026-10-06', 'agrupacion' => $agrupacion, 'zona_horaria' => 'America/Mexico_City'], $r->json('periodo'));

                $datos = $r->json('datos');
                if ($agrupacion === Periodo::TOTAL) {
                    foreach ($datos as $renglon) {
                        $this->assertSame($campos, array_keys($renglon), "{$kpi->clave()} total");
                    }
                    continue;
                }

                $cubetas = array_values(Periodo::cubetas($inicio, $fin, $agrupacion));
                $this->assertCount(count($cubetas), $datos, "{$kpi->clave()} {$agrupacion}: serie con huecos");
                foreach ($datos as $i => $renglon) {
                    $this->assertSame(array_merge(['periodo', 'periodo_inicio', 'periodo_fin'], $campos), array_keys($renglon));
                    $this->assertSame($cubetas[$i]['periodo'], $renglon['periodo']);
                }
                $this->assertSame('2026-01-10', $datos[0]['periodo_inicio'], 'La primera cubeta se recorta al rango');
                $this->assertSame('2026-10-06', end($datos)['periodo_fin'], 'La última cubeta se recorta al rango');
            }
        }
    }

    public function test_periodo_por_defecto_son_los_ultimos_doce_meses(): void {
        $r = $this->api('/kpis/numeros-serie-escaneados')->assertOk();

        $this->assertSame(['fecha_inicio' => '2025-11-01', 'fecha_fin' => '2026-10-06', 'agrupacion' => 'mes', 'zona_horaria' => 'America/Mexico_City'], $r->json('periodo'));
        $this->assertCount(12, $r->json('datos'));
    }

    public function test_sin_token_responde_401_en_json_aunque_no_se_pida_json(): void {
        $r = $this->get('/api/v3/kpis');

        $this->assertError($r, 401, 'NO_AUTENTICADO');
        $r->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_token_invalido_o_revocado_responde_401(): void {
        $this->assertError($this->api('/kpis', '999|no-existe'), 401, 'NO_AUTENTICADO');

        $usuario = $this->cuentaDeServicio();
        $token = $usuario->createToken('kpi-central', [KpiApi::ABILITY]);
        $usuario->tokens()->delete();
        $this->app['auth']->forgetGuards();

        $this->assertError($this->api('/kpis', $token->plainTextToken), 401, 'NO_AUTENTICADO');
    }

    public function test_la_sesion_web_no_da_acceso_aunque_el_usuario_sea_admin(): void {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $admin = User::factory()->conRol('admin')->create();

        $this->assertError($this->actingAs($admin)->getJson('/api/v3/kpis'), 401, 'NO_AUTENTICADO');
    }

    public function test_token_sin_ability_kpis_read_responde_403(): void {
        $this->assertError($this->api('/kpis/numeros-serie-escaneados', $this->token(['otra:cosa'])), 403, 'SIN_PERMISO');
    }

    public function test_kpi_o_ruta_inexistente_responde_404(): void {
        $token = $this->token();
        $this->assertError($this->api('/kpis/no-existe', $token), 404, 'KPI_NO_ENCONTRADO', 'clave');
        $this->assertError($this->api('/otra-cosa', $token), 404, 'NO_ENCONTRADO');
    }

    public function test_metodos_de_escritura_responden_405(): void {
        $token = $this->token();

        foreach (['post', 'put', 'patch', 'delete'] as $metodo) {
            $r = $this->withToken($token)->json($metodo, '/api/v3/kpis/numeros-serie-escaneados');
            $this->assertError($r, 405, 'METODO_NO_PERMITIDO');
            $this->assertStringContainsString('GET', $r->headers->get('Allow'));
        }
    }

    public function test_validacion_estricta_de_parametros_responde_422(): void {
        $token = $this->token();
        $casos = [
            ['?fechainicio=2026-01-01', 'PARAMETRO_DESCONOCIDO', 'fechainicio'],
            ['?area=1', 'FILTRO_NO_SOPORTADO', 'area'],
            ['?fecha_inicio=2026-13-01', 'PARAMETRO_INVALIDO', 'fecha_inicio'],
            ['?fecha_inicio=01/10/2026', 'PARAMETRO_INVALIDO', 'fecha_inicio'],
            ['?agrupacion=hora', 'PARAMETRO_INVALIDO', 'agrupacion'],
            ['?usuario[]=1', 'PARAMETRO_INVALIDO', 'usuario'],
            ['?usuario=abc', 'PARAMETRO_INVALIDO', 'usuario'],
            ['?fecha_inicio=2026-10-06&fecha_fin=2026-10-01', 'RANGO_FECHAS_INVALIDO', 'fecha_inicio'],
            ['?fecha_inicio=2020-01-01&fecha_fin=2026-10-06', 'RANGO_FECHAS_INVALIDO', 'fecha_fin'],
            ['?fecha_inicio=2024-01-01&agrupacion=dia', 'RANGO_FECHAS_INVALIDO', 'agrupacion'],
        ];

        foreach ($casos as [$query, $codigo, $campo]) {
            $this->assertError($this->api('/kpis/numeros-serie-escaneados'.$query, $token), 422, $codigo, $campo);
        }

        $this->assertError($this->api('/kpis/etiquetas-impresas?producto=SKU-1', $token), 422, 'FILTRO_NO_SOPORTADO', 'producto');
        $this->assertError($this->api('/kpis/productividad-por-usuario?agrupacion=dia', $token), 422, 'PARAMETRO_INVALIDO', 'agrupacion');
        $this->assertError($this->api('/kpis/productividad-por-usuario?por_pagina=501', $token), 422, 'PARAMETRO_INVALIDO', 'por_pagina');
        $this->assertError($this->api('/kpis/numeros-serie-escaneados?pagina=2', $token), 422, 'PARAMETRO_DESCONOCIDO', 'pagina');
    }

    public function test_limite_de_peticiones_por_token_responde_429(): void {
        config(['kpi-api.limite_por_minuto' => 2]);
        $token = $this->token();

        $this->api('/sistema', $token)->assertOk();
        $this->api('/sistema', $token)->assertOk();
        $r = $this->api('/sistema', $token);

        $this->assertError($r, 429, 'LIMITE_PETICIONES');
        $this->assertNotNull($r->headers->get('Retry-After'));
    }

    public function test_respeta_el_x_request_id_valido_y_genera_uno_si_es_invalido(): void {
        $token = $this->token();

        $this->withHeader('X-Request-Id', 'central-7f3a')->api('/sistema', $token)
            ->assertHeader('X-Request-Id', 'central-7f3a')
            ->assertJsonPath('metadatos.id_peticion', 'central-7f3a');

        $r = $this->withHeader('X-Request-Id', 'no válido!')->api('/sistema', $token);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $r->json('metadatos.id_peticion'));
    }
}
