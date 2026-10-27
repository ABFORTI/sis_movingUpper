<?php

namespace App\Services\Exportacion;

use App\Contracts\ExportadorTarimas;
use App\Models\Partida;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelExportador implements ExportadorTarimas {
    private const TIPO = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';


    public const ENCABEZADOS = [
        'Consecutivo', 'Número de parte (SKU)', 'Descripción', 'Cantidad',
        'Unidad de medida', 'Daño', 'Número de serie',
        'Embarque', 'Fecha de registro', 'Demo',
    ];


    public const ENCABEZADOS_ENTREGA = [
        'Consecutivo', 'Número de parte (SKU)', 'Descripción', 'Cantidad',
        'Unidad de medida', 'Daño', 'Número de serie',
        'Tráiler', 'Fecha de registro',
    ];


    public const MANIFIESTO = [
        'Consecutivo', 'Número de parte (SKU)', 'Descripción', 'Cantidad',
        'Unidad de medida', 'Categoría', 'Daño', 'Número de serie',
    ];


    public const COLUMNA_NS_MANIFIESTO = 8;
    public const COLUMNA_NS = 7;
    public const COLUMNA_NS_REPORTE = 4;
    public const PENDIENTE = 'pendiente_revision';
    
    private const AMARILLO = 'FFFF00';

    public const CANTIDAD = 1;
    public const UNIDAD = 'PZ';
    public const DANO = 0;

    /**
     *
     * @param  list<array>  $filas  de Manifiesto
     */
    public function manifiesto(array $filas, string $nombre): StreamedResponse {
        $libro = new Spreadsheet();
        $this->llenar($libro->getActiveSheet()->setTitle('Manifiesto'), [self::MANIFIESTO, ...$filas], [13, 22, 40, 10, 17, 18, 8, 22], self::COLUMNA_NS_MANIFIESTO);

        return $this->descargar($libro, "{$nombre}_".now()->format('Y-m-d').'.xlsx');
    }


    public function todas(Collection $tarimas, string $alcance): StreamedResponse {
        $filas = Partida::enTarima()
            ->whereIn('tarima_id', $tarimas->modelKeys())
            ->with(['tarima', 'incidenciaPendiente'])
            ->orderBy('consecutivo')
            ->get()
            ->map(fn (Partida $p) => $this->filaDePartida($p))
            ->all();

        return $this->descargar($this->libro($filas), "{$alcance}_".now()->format('Y-m-d').'.xlsx');
    }

    private function filaDePartida(Partida $p): array {
        return [
            $p->consecutivo,
            $p->sku,
            $p->descripcion_mostrada,
            self::CANTIDAD,
            self::UNIDAD,
            self::DANO,
            $p->ns_mostrada,
            $p->tarima->numero,
            $p->registrado_at?->format('Y-m-d H:i:s'),
            $p->demo->etiqueta(),
            self::PENDIENTE => $p->pendienteRevision(),
        ];
    }

    private function libro(array $filas): Spreadsheet {
        $libro = new Spreadsheet();
        $this->llenar($libro->getActiveSheet()->setTitle('Mudanza'), [self::ENCABEZADOS, ...$filas], [13, 22, 32, 10, 17, 8, 20, 9, 20, 11], self::COLUMNA_NS);

        return $libro;
    }

    private function llenar(Worksheet $hoja, array $filas, array $anchos, ?int $columnaNs = null): void {
        foreach (array_values($filas) as $r => $fila) {
            if ($columnaNs !== null && ! empty($fila[self::PENDIENTE])) {
                $hoja->getStyle([$columnaNs, $r + 1])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::AMARILLO);
            }
            unset($fila[self::PENDIENTE]);
            foreach (array_values($fila) as $c => $valor) {
                if ($valor === null) {
                    continue;
                }
                $celda = $hoja->getCell([$c + 1, $r + 1]);
                is_int($valor) ? $celda->setValue($valor) : $celda->setValueExplicit((string) $valor);
            }
        }
        foreach ($anchos as $c => $ancho) {
            $hoja->getColumnDimensionByColumn($c + 1)->setWidth($ancho);
        }
        $hoja->getStyle('A1:'.$hoja->getHighestColumn().'1')->getFont()->setBold(true);
    }

    /**
     *
     * @param  list<array{0: string, 1: string|int}>  $control
     * @param  list<array>  $filas  filas del inventario  + novedad + incidencias
     * @param  list<array>  $salieron
     */
    public function archivoEntrega(array $control, array $filas, array $salieron): string {
        $libro = new Spreadsheet();
        $this->llenar(
            $libro->getActiveSheet()->setTitle('Inventario'),
            [[...self::ENCABEZADOS_ENTREGA, 'Novedad', 'Incidencias abiertas'], ...$filas],
            [13, 22, 32, 10, 17, 8, 20, 9, 20, 18, 22],
            self::COLUMNA_NS,
        );
        $this->llenar($libro->createSheet()->setTitle('Control'), [['Dato', 'Valor'], ...$control], [26, 60]);
        $this->llenar(
            $libro->createSheet()->setTitle('Salieron'),
            [['Consecutivo', 'Número de parte (SKU)', 'Número de serie', 'Embarque anterior', 'Motivo'], ...$salieron],
            [13, 22, 20, 15, 40],
        );
        $libro->setActiveSheetIndex(0);

        $ruta = tempnam(sys_get_temp_dir(), 'entrega');
        try {
            (new Xlsx($libro))->save($ruta);

            return file_get_contents($ruta);
        } finally {
            @unlink($ruta);
        }
    }


    public function reporteFinal(string $folio, array $resumen, array $filas, string $nombre = 'Reporte_Final'): StreamedResponse {
        $libro = new Spreadsheet();
        $this->llenar(
            $libro->getActiveSheet()->setTitle('Piezas'),
            [['Consecutivo', 'Número de parte (SKU)', 'Descripción', 'Número de serie', 'Embarque',
                'Tráiler', 'Placas', 'Cargada', 'Cargó', 'Recibida', 'Recibió', 'Situación', 'Incidencias abiertas'], ...$filas],
            [13, 22, 32, 20, 9, 11, 11, 20, 18, 20, 18, 22, 20],
            self::COLUMNA_NS_REPORTE,
        );
        $this->llenar($libro->createSheet()->setTitle('Resumen'), [['Dato', 'Valor'], ...$resumen], [34, 40]);
        $libro->setActiveSheetIndex(0);

        return $this->descargar($libro, "{$folio}_{$nombre}_".now()->format('Y-m-d').'.xlsx');
    }

    private function descargar(Spreadsheet $libro, string $nombre): StreamedResponse {
        return response()->streamDownload(function () use ($libro) {
            (new Xlsx($libro))->save('php://output');
        }, $nombre, ['Content-Type' => self::TIPO]);
    }
}
