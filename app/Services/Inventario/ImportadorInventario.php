<?php

namespace App\Services\Inventario;

use App\Models\Almacen;
use App\Models\InventarioCarga;
use App\Models\InventarioItem;
use App\Models\User;
use App\Services\BitacoraService;
use App\Services\DocumentoService;
use App\Support\NumeroSerieNormalizado;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;


class ImportadorInventario {
    private const LIMITES = ['sku' => 100, 'modelo' => 150, 'descripcion' => 255, 'posicion' => 100, 'ns_fabricante' => 150, 'numero_serie' => 150, 'ns_lote' => 150];
    private const MAX_MENSAJES = 200;

    public function __construct(
        private readonly DocumentoService $documentos,
        private readonly BitacoraService $bitacora,
    ) { }

    public function importar(Almacen $almacen, UploadedFile $archivo, User $usuario, bool $reemplazar = false): InventarioCarga {
        $vigente = $almacen->inventarioVigente()->first();
        if ($vigente && ! $reemplazar) {
            throw ValidationException::withMessages([
                'archivo' => "El {$almacen->etiqueta} ya tiene un inventario cargado ({$vigente->archivo_nombre}, {$vigente->created_at->format('d/m/Y H:i')}). Marca «Reemplazar el inventario actual» para sustituirlo.",
            ]);
        }

        $lectura = $this->leer($archivo, $almacen);
        if ($lectura['items'] === []) {
            throw ValidationException::withMessages([
                'archivo' => 'El archivo no tiene filas válidas para importar. '.implode(' ', array_slice($lectura['errores'], 0, 5)),
            ]);
        }

        $contenido = $archivo->get();
        $guardado = $this->documentos->guardarContenido(
            'inventarios/'.Str::slug($almacen->codigo),
            strtolower($archivo->getClientOriginalExtension() ?: 'xlsx'),
            $contenido,
        );

        try {
            return DB::transaction(function () use ($almacen, $archivo, $usuario, $lectura, $guardado) {
                Almacen::whereKey($almacen->id)->lockForUpdate()->first();

                $anterior = InventarioCarga::where('almacen_id', $almacen->id)->where('vigente', true)->lockForUpdate()->first();
                $anterior?->update(['vigente' => false, 'reemplazada_at' => now()]);

                $carga = InventarioCarga::create([
                    'almacen_id' => $almacen->id,
                    'archivo_nombre' => Str::limit($archivo->getClientOriginalName(), 195, ''),
                    'archivo' => $guardado['archivo'],
                    'sha256' => $guardado['sha256'],
                    'filas_leidas' => $lectura['leidas'],
                    'filas_importadas' => count($lectura['items']),
                    'filas_omitidas' => $lectura['leidas'] - count($lectura['items']),
                    'observaciones' => [
                        'columnas' => $lectura['columnas'],
                        'errores' => array_slice($lectura['errores'], 0, self::MAX_MENSAJES),
                        'avisos' => array_slice($lectura['avisos'], 0, self::MAX_MENSAJES),
                        'total_errores' => count($lectura['errores']),
                        'total_avisos' => count($lectura['avisos']),
                    ],
                    'vigente' => true,
                    'cargada_by' => $usuario->id,
                ]);

                foreach (array_chunk($lectura['items'], 500) as $lote) {
                    InventarioItem::insert(array_map(fn (array $item) => $item + [
                        'inventario_carga_id' => $carga->id,
                        'almacen_id' => $almacen->id,
                    ], $lote));
                }

                $this->bitacora->registrar('inventario.cargado', $usuario, $carga, [
                    'almacen' => $almacen->codigo,
                    'archivo' => $carga->archivo_nombre,
                    'sha256' => $carga->sha256,
                    'importadas' => $carga->filas_importadas,
                    'omitidas' => $carga->filas_omitidas,
                    'reemplaza' => $anterior?->id,
                ]);

                return $carga;
            });
        } catch (UniqueConstraintViolationException) {
            $this->documentos->disco()->delete($guardado['archivo']);
            throw ValidationException::withMessages(['archivo' => "Otra persona acaba de cargar el inventario del {$almacen->etiqueta}. Revisa y vuelve a intentar."]);
        } catch (Throwable $e) {
            $this->documentos->disco()->delete($guardado['archivo']);
            throw $e;
        }
    }

    /**
     * @return array{columnas: array<string, string>, leidas: int, items: list<array>, errores: list<string>, avisos: list<string>}
     */
    public function leer(UploadedFile $archivo, Almacen $almacen): array {
        $hoja = $this->hoja($archivo);
        [$filaEncabezado, $columnas] = $this->encabezados($hoja);

        $ultimaFila = $hoja->getHighestDataRow();
        if ($ultimaFila - $filaEncabezado > (int) config('inventarios.filas_maximas')) {
            throw ValidationException::withMessages([
                'archivo' => 'El archivo tiene más de '.number_format((int) config('inventarios.filas_maximas')).' filas. Divídelo o pide al administrador ampliar el límite.',
            ]);
        }

        $codigoAlmacen = NumeroSerieNormalizado::de($almacen->codigo);
        $items = [];
        $errores = [];
        $avisos = [];
        $leidas = 0;
        $firmas = [];
        $series = [];
        $otrosAlmacenes = [];
        $sinSerie = [];

        for ($fila = $filaEncabezado + 1; $fila <= $ultimaFila; $fila++) {
            $valores = [];
            foreach ($columnas as $campo => $letra) {
                $valores[$campo] = $this->texto($hoja->getCell($letra.$fila));
            }
            if (implode('', $valores) === '') {
                continue;
            }
            $leidas++;

            if (isset($valores['almacen']) && $valores['almacen'] !== '' && NumeroSerieNormalizado::de($valores['almacen']) !== $codigoAlmacen) {
                $otrosAlmacenes[$valores['almacen']][] = $fila;
                continue;
            }

            if ($valores['sku'] === '') {
                $errores[] = "Fila {$fila}: no tiene SKU.";
                continue;
            }

            $tieneSerie = false;
            foreach (array_keys(InventarioItem::CAMPOS_SERIE) as $campo) {
                $tieneSerie = $tieneSerie || ($valores[$campo] ?? '') !== '';
            }
            $largo = array_filter(self::LIMITES, fn (int $max, string $campo) => mb_strlen($valores[$campo] ?? '') > $max, ARRAY_FILTER_USE_BOTH);
            if ($largo) {
                $errores[] = "Fila {$fila}: ".implode(', ', array_keys($largo)).' excede el largo permitido.';
                continue;
            }

            $item = [
                'fila' => $fila,
                'sku' => $valores['sku'],
                'sku_norm' => NumeroSerieNormalizado::de($valores['sku']),
                'modelo' => ($valores['modelo'] ?? '') ?: null,
                'descripcion' => ($valores['descripcion'] ?? '') ?: null,
                'posicion' => ($valores['posicion'] ?? '') ?: null,
            ];
            foreach (array_keys(InventarioItem::CAMPOS_SERIE) as $campo) {
                $item[$campo] = ($valores[$campo] ?? '') ?: null;
                $item[$campo.'_norm'] = $item[$campo] === null ? null : NumeroSerieNormalizado::de($item[$campo]);
            }

            if ($tieneSerie) {
                $firma = implode("\x1F", [$item['sku_norm'], $item['ns_fabricante_norm'], $item['numero_serie_norm'], $item['ns_lote_norm']]);
                if (isset($firmas[$firma])) {
                    $avisos[] = "Fila {$fila}: repite la fila {$firmas[$firma]}; se importó una sola vez.";
                    continue;
                }
                $firmas[$firma] = $fila;
            } else {
                $sinSerie[] = $fila;
            }

            foreach ([InventarioItem::NS_FABRICANTE, InventarioItem::NUMERO_SERIE] as $campo) {
                $valor = $item[$campo.'_norm'];
                if ($valor === null) {
                    continue;
                }
                if (isset($series[$valor]) && $series[$valor] !== $fila) {
                    $avisos[] = "Fila {$fila}: el número de serie {$item[$campo]} también está en la fila {$series[$valor]}. Revísalo.";
                } else {
                    $series[$valor] = $fila;
                }
            }

            $items[] = $item;
        }

        if ($otrosAlmacenes) {
            $detalle = collect($otrosAlmacenes)->map(fn (array $filas, string $codigo) => "«{$codigo}» (".count($filas).' filas, p. ej. fila '.$filas[0].')')->join(', ');
            throw ValidationException::withMessages([
                'archivo' => "El archivo trae filas de otro almacén: {$detalle}. Elegiste el {$almacen->etiqueta}; no se cargó nada.",
            ]);
        }
        if ($sinSerie) {
            $n = count($sinSerie);
            $avisos[] = ($n === 1 ? "La fila {$sinSerie[0]} no tiene" : "{$n} filas (p. ej. fila {$sinSerie[0]}) no tienen")
                .' número de serie: se importaron solo con su SKU. Al escanear sus N/S quedan pendientes de revisión.';
        }

        return [
            'columnas' => array_map(fn (string $letra) => $letra.$filaEncabezado, $columnas),
            'leidas' => $leidas,
            'items' => $items,
            'errores' => $errores,
            'avisos' => $avisos,
        ];
    }

    private function hoja(UploadedFile $archivo): Worksheet {
        try {
            $lector = IOFactory::createReaderForFile($archivo->getRealPath());
            $lector->setReadDataOnly(true);
            $libro = $lector->load($archivo->getRealPath());
        } catch (Throwable) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el archivo. Verifica que sea un Excel (.xlsx, .xls) o CSV válido y que no esté protegido.']);
        }

        return $libro->getSheet(0);
    }

    /**
     *
     * @return array{0: int, 1: array<string, string>} fila y campo => letra de columna
     */
    private function encabezados(Worksheet $hoja): array {
        $alias = [];
        foreach ((array) config('inventarios.columnas') as $campo => $nombres) {
            foreach ($nombres as $nombre) {
                $alias[self::normalizarEncabezado($nombre)] = $campo;
            }
        }

        $limite = min((int) config('inventarios.filas_encabezado'), $hoja->getHighestDataRow());
        $ultimaColumna = $hoja->getHighestDataColumn();
        for ($fila = 1; $fila <= $limite; $fila++) {
            $columnas = [];
            $repetidas = [];
            foreach ($hoja->getRowIterator($fila, $fila)->current()->getCellIterator('A', $ultimaColumna) as $celda) {
                $campo = $alias[self::normalizarEncabezado($this->texto($celda))] ?? null;
                if ($campo === null) {
                    continue;
                }
                isset($columnas[$campo]) ? $repetidas[] = $this->texto($celda) : $columnas[$campo] = $celda->getColumn();
            }

            if (isset($columnas['sku'])) {
                if ($repetidas) {
                    throw ValidationException::withMessages([
                        'archivo' => "La fila {$fila} tiene columnas repetidas o ambiguas: ".implode(', ', $repetidas).'. Deja una sola columna por dato.',
                    ]);
                }

                return [$fila, $columnas];
            }
        }

        throw ValidationException::withMessages([
            'archivo' => 'No se encontraron los encabezados del inventario. El archivo debe tener una columna «SKU» (y, opcionalmente, «Número de serie del fabricante», «Número de serie», «Número de serie de lote», «Modelo» y «Descripción»).',
        ]);
    }

    private function texto(Cell $celda): string {
        $valor = $celda->isFormula() ? ($celda->getOldCalculatedValue() ?? $celda->getCalculatedValue()) : $celda->getValue();

        if (is_float($valor) && floor($valor) === $valor && abs($valor) < 1e15) {
            $valor = sprintf('%.0f', $valor);
        } elseif ($valor instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $valor = $valor->getPlainText();
        }

        return NumeroSerieNormalizado::limpio(is_scalar($valor) ? (string) $valor : '');
    }

    public static function normalizarEncabezado(string $texto): string {
        $texto = mb_strtolower(Str::ascii(NumeroSerieNormalizado::limpio($texto)));
        $texto = (string) preg_replace('/[^a-z0-9\/]+/', ' ', $texto);

        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }
}
