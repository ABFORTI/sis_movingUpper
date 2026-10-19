<?php

namespace Tests\Feature\Concerns;

use App\Models\Almacen;
use App\Models\InventarioCarga;
use App\Models\InventarioItem;
use App\Models\Mudanza;
use App\Models\MudanzaMovimiento;
use App\Models\Registro;
use App\Models\Role;
use App\Models\Tarima;
use App\Models\User;
use App\Services\TarimaService;
use App\Support\NumeroSerieNormalizado;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

trait PreparaWms {
    use RefreshDatabase;

    protected Mudanza $mudanza;

    /** Almacenes del movimiento predeterminado de las mudanzas de prueba. */
    protected string $almacenOrigen = '58';
    protected string $almacenDestino = '3';

    protected function setUp(): void {
        parent::setUp();
        $this->seed([RoleSeeder::class]);
        $this->mudanza = $this->crearMudanza(Mudanza::ACTIVA);
    }

    /**
     * Mudanza con datos completos, sin pasar por el servicio (para preparar
     * escenarios). Por defecto con un movimiento 58 → 3.
     *
     * @param list<array{0: string, 1: string}> $movimientos
     */
    protected function crearMudanza(string $estado = Mudanza::BORRADOR, string $cliente = 'Euroquip', ?array $movimientos = null): Mudanza {
        $numero = Mudanza::count() + 1;

        $mudanza = Mudanza::create([
            'folio' => Mudanza::folioPara($numero),
            'cliente' => $cliente,
            'estado' => $estado,
        ]);
        // Como si se hubiera creado por el servicio: el siguiente folio no choca.
        \Illuminate\Support\Facades\DB::table('contadores')->upsert(
            [['clave' => Mudanza::CONTADOR_FOLIO, 'valor' => $numero]], ['clave'], ['valor'],
        );

        foreach ($movimientos ?? [[$this->almacenOrigen, $this->almacenDestino]] as [$origen, $destino]) {
            MudanzaMovimiento::create([
                'mudanza_id' => $mudanza->id,
                'almacen_origen_id' => $this->almacen($origen)->id,
                'almacen_destino_id' => $this->almacen($destino)->id,
            ]);
        }

        return $mudanza;
    }

    protected function almacen(string $codigo): Almacen {
        return Almacen::firstOrCreate(['codigo' => $codigo]);
    }

    protected function admin(): User {
        return User::factory()->conRol(Role::ADMIN)->create();
    }

    protected function operador(): User {
        return User::factory()->conRol(Role::OPERADOR)->create();
    }

    protected function auditor(): User {
        return User::factory()->conRol(Role::AUDITOR)->create();
    }

    /** Tarima en la mudanza activa, numerada por el servicio real. */
    protected function tarima(?User $creador = null, ?int $movimientoId = null): Tarima {
        return app(TarimaService::class)->crear($creador, $movimientoId);
    }

    /**
     * Renglones en el inventario vigente del almacén (se crea la carga si no
     * existe). Cada renglón: sku, descripción y los tres números de serie.
     *
     * @param list<array{sku: string, descripcion?: ?string, modelo?: ?string, ns_fabricante?: ?string, numero_serie?: ?string, ns_lote?: ?string}> $filas
     */
    protected function enInventario(array $filas, ?string $almacen = null): InventarioCarga {
        $almacen = $this->almacen($almacen ?? $this->almacenOrigen);
        $carga = InventarioCarga::firstOrCreate(
            ['almacen_id' => $almacen->id, 'vigente' => true],
            ['archivo_nombre' => 'prueba.xlsx', 'sha256' => str_repeat('0', 64)],
        );

        foreach ($filas as $i => $f) {
            $item = [
                'inventario_carga_id' => $carga->id,
                'almacen_id' => $almacen->id,
                'fila' => $i + 2,
                'sku' => $f['sku'],
                'sku_norm' => NumeroSerieNormalizado::de($f['sku']),
                'modelo' => $f['modelo'] ?? null,
                'descripcion' => array_key_exists('descripcion', $f) ? $f['descripcion'] : "Producto {$f['sku']}",
            ];
            foreach (array_keys(InventarioItem::CAMPOS_SERIE) as $campo) {
                $item[$campo] = $f[$campo] ?? null;
                $item[$campo.'_norm'] = isset($f[$campo]) ? NumeroSerieNormalizado::de($f[$campo]) : null;
            }
            InventarioItem::create($item);
        }

        return $carga;
    }

    /** El SKU existe en el inventario (con un renglón por cada N/S indicada). */
    protected function skuEnInventario(string $sku, array $series = [], ?string $almacen = null): void {
        $carga = $this->enInventario([], $almacen);
        if ($series === [] && ! InventarioItem::where('inventario_carga_id', $carga->id)->where('sku_norm', NumeroSerieNormalizado::de($sku))->exists()) {
            $this->enInventario([['sku' => $sku, 'descripcion' => 'Bomba hidráulica', 'ns_lote' => 'LOTE-'.$sku]], $almacen);
        }
        foreach ($series as $ns) {
            $this->enInventario([['sku' => $sku, 'descripcion' => 'Bomba hidráulica', 'numero_serie' => $ns]], $almacen);
        }
    }

    protected function activarPadre(User $usuario, Tarima $tarima, string $sku = '2364F'): void {
        $this->skuEnInventario($sku, almacen: $tarima->movimiento?->origen?->codigo);
        $this->actingAs($usuario)->post(route('escaneo.padre'), [
            'sku' => $sku,
            'tarima' => $tarima->uuid,
        ])->assertSessionHasNoErrors();
    }

    /**
     * Registra una N/S en el SKU activo como lo haría el escáner. Si la N/S no
     * está en el inventario, primero se agrega al inventario del almacén de la
     * tarima para ese SKU (salvo $agregarAlInventario = false).
     */
    protected function escanearNs(string $ns, bool $agregarAlInventario = true, array $extra = []): TestResponse {
        $padre = Registro::with('tarima.movimiento.origen')->find(session('wms.sku_activo'));
        if ($padre && $agregarAlInventario) {
            $this->skuEnInventario($padre->sku, [$ns], $padre->tarima->movimiento?->origen?->codigo);
        }

        return $this->post(route('escaneo.ns'), ['numero_serie' => $ns] + $extra);
    }

    /**
     * Registro histórico sin N/S (como los del modo "Solo SKUs", ya retirado).
     * Solo para preparar escenarios con datos anteriores: hoy no se pueden crear.
     */
    protected function registroHistoricoSinNs(User $usuario, Tarima $tarima, string $sku, ?string $descripcion = null): Registro {
        $registro = $tarima->registros()->create([
            'tipo' => \App\Enums\TipoRegistro::Simple,
            'sku' => $sku,
            'descripcion' => $descripcion,
            'user_id' => $usuario->id,
        ]);
        app(TarimaService::class)->invalidarImpresion($tarima);
        $this->actingAs($usuario);

        return $registro;
    }

    /** Igual que escanearNs, como lo envía el handheld (JSON). */
    protected function escanearNsJson(string $ns, bool $agregarAlInventario = true, array $extra = []): TestResponse {
        $padre = Registro::with('tarima.movimiento.origen')->find(session('wms.sku_activo'));
        if ($padre && $agregarAlInventario) {
            $this->skuEnInventario($padre->sku, [$ns], $padre->tarima->movimiento?->origen?->codigo);
        }

        return $this->postJson(route('escaneo.ns'), ['numero_serie' => $ns] + $extra);
    }

    /** SKU + N/S en un solo paso (lo que antes era el modo "Solo SKUs" para preparar escenarios). */
    protected function capturarPieza(User $usuario, Tarima $tarima, string $sku, ?string $ns = null): void {
        $this->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($usuario, $tarima, $sku);
        $this->escanearNs($ns ?? "NS-{$sku}-{$tarima->numero}")->assertSessionHasNoErrors();
        $this->post(route('escaneo.padre.cerrar'));
    }
}
