<?php

namespace App\Services\Exportacion;

use App\Contracts\ExportadorTarimas;
use App\Models\Tarima;
use App\Services\TarimaService;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelExportador implements ExportadorTarimas {
    private const TIPO = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** "Categoría" es destinos.codigo (Producto destino); el cambio de nombre solo aplica al Excel. */
    public const ENCABEZADOS = [
        'Consecutivo', 'Número de parte (SKU)', 'Descripción', 'Cantidad',
        'Unidad de medida', 'Categoría', 'Daño', 'Número de serie',
    ];

    private const CANTIDAD = 1;
    private const UNIDAD = 'PZ';
    private const DANO = 0;

    public function __construct(private readonly TarimaService $tarimas) { }

    public function tarima(Tarima $tarima): StreamedResponse {
        return $this->descargar(
            $this->libro($this->filas($tarima)),
            "Tarima_{$tarima->id}_".now()->format('Y-m-d').'.xlsx'
        );
    }

    public function todas(Collection $tarimas): StreamedResponse {
        $filas = $tarimas->flatMap(fn (Tarima $tarima) => $this->filas($tarima))->all();

        return $this->descargar($this->libro($filas), 'Mudanza_Todas_Tarimas_'.now()->format('Y-m-d').'.xlsx');
    }

    /**
     * Una fila por pieza, en el orden de TarimaService::filas(). El consecutivo se
     * lee de la tarima (consecutivo_inicial + posición); nunca se genera aquí.
     *
     * @return list<array>
     */
    private function filas(Tarima $tarima): array {
        return $this->tarimas->filas($tarima)->map(fn (array $f, int $posicion) => [
            $tarima->consecutivo($posicion),
            $f['sku'],
            $f['descripcion'],
            self::CANTIDAD,
            self::UNIDAD,
            $f['destino'],
            self::DANO,
            $f['ns'],
        ])->all();
    }

    private function libro(array $filas): Spreadsheet {
        $libro = new Spreadsheet();
        $this->llenar($libro->getActiveSheet()->setTitle('Mudanza'), [self::ENCABEZADOS, ...$filas], [13, 22, 32, 10, 17, 12, 8, 20]);

        return $libro;
    }

    private function llenar(Worksheet $hoja, array $filas, array $anchos): void {
        foreach ($filas as $r => $fila) {
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

    private function descargar(Spreadsheet $libro, string $nombre): StreamedResponse {
        return response()->streamDownload(function () use ($libro) {
            (new Xlsx($libro))->save('php://output');
        }, $nombre, ['Content-Type' => self::TIPO]);
    }
}
