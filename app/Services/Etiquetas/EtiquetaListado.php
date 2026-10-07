<?php

namespace App\Services\Etiquetas;

use App\Models\Tarima;
use App\Services\TarimaService;


class EtiquetaListado extends DisenoBase {
    private const ALTO_RENGLON = 2.875;
    private const ALTO_TEXTO = 2.5;
    private const Y_ENCABEZADO_TABLA = 13.75;
    private const INICIO_RENGLONES = 16.5;
    private const ANCHO_BLOQUE = 28;

    public function __construct(
        TarimaService $tarimas,
        FormatoEtiqueta $formato,
        private readonly int $filasPorEtiqueta,
    ) {
        parent::__construct($tarimas, $formato);
    }

    public function aplicaA(Tarima $tarima): bool {
        return $tarima->cargarDetalle()->registros->isNotEmpty();
    }

    public function capacidad(): int {
        $disponible = $this->finCuerpo() - $this->mm(self::INICIO_RENGLONES) - $this->mm(self::ALTO_TEXTO);

        return intdiv($disponible, $this->mm(self::ALTO_RENGLON)) + 1;
    }

    public function paginas(Tarima $tarima): array {
        $filas = $this->tarimas->filas($tarima);
        $hojas = $filas->chunk(max(1, min($this->filasPorEtiqueta, $this->capacidad())));
        $m = $this->formato->margenX;
        $separacion = $this->mm(0.75);

        $anchos = ['#' => $this->mm(4.5), 'SKU' => $this->mm(17.5), 'N/S' => $this->mm(18.75)];
        $columnas = [];
        $x = $m;
        foreach ($anchos as $col => $ancho) {
            $columnas[$col] = [$x, $ancho];
            $x += $ancho + $separacion;
        }
        $columnas['Descripción'] = [$x, $this->formato->limiteDerecho() - $x];

        $paginas = [];
        foreach ($hojas->values() as $i => $hoja) {
            $titulo = 'TARIMA '.$tarima->id.($hojas->count() > 1 ? ' ('.($i + 1).'/'.$hojas->count().')' : '');

            $lienzo = $this->nuevoLienzo();
            $y = $this->franjaLogos($lienzo);
            $this->encabezado($lienzo, $tarima, $y, $titulo, $filas->count());

            foreach ($columnas as $encabezado => [$xCol, $ancho]) {
                $lienzo->texto($xCol, $this->mm(self::Y_ENCABEZADO_TABLA), $ancho, $this->mm(self::ROTULO), mb_strtoupper($encabezado), 'L');
            }
            $lienzo->linea($this->mm(self::Y_ENCABEZADO_TABLA + self::ROTULO + 0.125), 2);

            $y = $this->mm(self::INICIO_RENGLONES);
            foreach ($hoja as $n => $fila) {
                $valores = ['#' => (string) ($n + 1), 'SKU' => $fila['sku'], 'N/S' => $fila['ns'], 'Descripción' => $fila['descripcion']];
                foreach ($columnas as $col => [$xCol, $ancho]) {
                    $lienzo->texto($xCol, $y, $ancho, $this->mm(self::ALTO_TEXTO), $valores[$col], 'L');
                }
                $y += $this->mm(self::ALTO_RENGLON);
            }

            $this->pie($lienzo, $tarima);
            $paginas[] = $lienzo;
        }

        return $paginas;
    }

    private function encabezado(Lienzo $lienzo, Tarima $tarima, int $y, string $titulo, int $piezas): void {
        $m = $this->formato->margenX;
        $anchoBloque = $this->mm(self::ANCHO_BLOQUE);
        $this->bloqueTarima($lienzo, $m, $y, $anchoBloque, $titulo);

        $x = $m + $anchoBloque + $this->mm(1.5);
        $disponible = $this->formato->limiteDerecho() - $x;
        $campos = [
            ['Destino', $this->tarimas->destino($tarima), 0.34],
            ['Fecha', $this->tarimas->fechaEtiqueta($tarima)->format('Y-m-d'), 0.40],
            ['Piezas', (string) $piezas, 0.26],
        ];
        foreach ($campos as [$rotulo, $valor, $proporcion]) {
            $ancho = (int) floor($disponible * $proporcion);
            $this->campo($lienzo, $x, $y, $ancho - $this->mm(0.75), $rotulo, $valor, self::TEXTO_NORMAL, self::TEXTO_APOYO);
            $x += $ancho;
        }
    }
}
