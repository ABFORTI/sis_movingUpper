<?php

namespace App\Services\Exportacion;

use App\Models\InventarioCarga;
use App\Models\InventarioItem;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;


class InventarioExcel {
    private const TIPO = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const ENCABEZADOS = [
        'SKU', 'Modelo', 'Descripción', 'Número de serie del fabricante', 'Número de serie', 'Número de serie de lote',
        'Registrado', 'N/S registrada', 'Embarque', 'Fila en el archivo original', 'Posición de ubicación',
    ];

    public function descargar(InventarioCarga $carga): StreamedResponse {
        $carga->loadMissing(['almacen', 'cargadaPor']);
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Inventario');

        $this->fila($hoja, 1, self::ENCABEZADOS);
        $hoja->getStyle('A1:K1')->getFont()->setBold(true);
        $hoja->freezePane('A2');

        $r = 2;
        InventarioItem::query()
            ->where('inventario_items.inventario_carga_id', $carga->id)
            ->leftJoin('numeros_serie as ns', fn ($j) => $j->on('ns.inventario_item_id', '=', 'inventario_items.id')->whereNull('ns.deleted_at'))
            ->leftJoin('registros as r', 'r.id', '=', 'ns.registro_id')
            ->leftJoin('tarimas as t', 't.id', '=', 'r.tarima_id')
            ->leftJoin('mudanzas as m', 'm.id', '=', 't.mudanza_id')
            ->orderBy('inventario_items.sku_norm')
            ->orderBy('inventario_items.id')
            ->select('inventario_items.*', 'ns.numero_serie as ns_registrada', 't.numero as tarima_numero', 'm.folio as mudanza_folio')
            ->chunk(1000, function ($items) use ($hoja, &$r) {
                foreach ($items as $i) {
                    $tarima = $i->tarima_numero ? $i->mudanza_folio.' · E-'.str_pad((string) $i->tarima_numero, 3, '0', STR_PAD_LEFT) : null;
                    $this->fila($hoja, $r++, [
                        $i->sku, $i->modelo, $i->descripcion, $i->ns_fabricante, $i->numero_serie, $i->ns_lote,
                        $i->ns_registrada ? 'Sí' : 'No', $i->ns_registrada, $tarima, $i->fila, $i->posicion,
                    ]);
                }
            });

        foreach ([18, 18, 34, 26, 22, 22, 11, 22, 18, 12, 20] as $c => $ancho) {
            $hoja->getColumnDimensionByColumn($c + 1)->setWidth($ancho);
        }

        $datos = $libro->createSheet()->setTitle('Almacén');
        foreach ([
            ['Almacén', $carga->almacen->etiqueta],
            ['Archivo cargado', $carga->archivo_nombre],
            ['Cargado', $carga->created_at->format('Y-m-d H:i:s').' · '.($carga->cargadaPor?->name ?? '—')],
            ['Productos', $carga->filas_importadas],
            ['Generado', now()->format('Y-m-d H:i:s')],
        ] as $i => $fila) {
            $this->fila($datos, $i + 1, $fila);
        }
        $datos->getColumnDimension('A')->setWidth(20);
        $datos->getColumnDimension('B')->setWidth(60);
        $libro->setActiveSheetIndex(0);

        $nombre = 'Inventario_Almacen-'.Str::slug($carga->almacen->codigo).'_'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($libro) {
            (new Xlsx($libro))->save('php://output');
        }, $nombre, ['Content-Type' => self::TIPO]);
    }


    private function fila(Worksheet $hoja, int $r, array $valores): void {
        foreach (array_values($valores) as $c => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }
            $celda = $hoja->getCell([$c + 1, $r]);
            is_int($valor) ? $celda->setValue($valor) : $celda->setValueExplicit((string) $valor);
        }
    }
}
