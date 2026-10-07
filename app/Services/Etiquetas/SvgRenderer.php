<?php

namespace App\Services\Etiquetas;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Picqer\Barcode\Types\TypeCode128;

class SvgRenderer {
    private const FUENTE = "'Arial Narrow','Roboto Condensed','Helvetica Neue',Arial,sans-serif";

    public function render(Lienzo $lienzo, string $titulo = 'Etiqueta'): string {
        $f = $lienzo->formato;
        $partes = [sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" class="etiqueta-svg" viewBox="0 0 %d %d" width="%smm" height="%smm" role="img" aria-label="%s" shape-rendering="crispEdges">',
            $f->ancho, $f->alto, $f->anchoMm(), $f->altoMm(), e($titulo)
        )];
        $partes[] = sprintf('<rect width="%d" height="%d" fill="#fff"/>', $f->ancho, $f->alto);

        foreach ($lienzo->elementos() as $e) {
            $partes[] = match ($e['tipo']) {
                'texto' => $this->texto($e),
                'qr' => $this->qr($e),
                'barras' => $this->barras($e),
                'linea' => sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="#000"/>', $e['x'], $e['y'], $e['ancho'], $e['grosor']),
                'recuadro' => sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="#000"/>', $e['x'], $e['y'], $e['ancho'], $e['alto']),
                'imagen' => $this->imagen($e),
            };
        }

        $partes[] = '</svg>';

        return implode('', $partes);
    }

    private function texto(array $e): string {
        [$x, $ancla] = match ($e['alineacion']) {
            'L' => [$e['x'], 'start'],
            'R' => [$e['x'] + $e['ancho'], 'end'],
            default => [$e['x'] + intdiv($e['ancho'], 2), 'middle'],
        };
        $estimado = mb_strlen($e['texto']) * $e['alto'] * Lienzo::PROPORCION_CARACTER;
        $ajuste = $estimado > $e['ancho'] ? sprintf(' textLength="%d" lengthAdjust="spacingAndGlyphs"', $e['ancho']) : '';

        return sprintf(
            '<text x="%d" y="%d" font-family="%s" font-weight="700" font-size="%d" text-anchor="%s" fill="%s" xml:space="preserve"%s>%s</text>',
            $x, $e['y'] + (int) round($e['alto'] * 0.82), e(self::FUENTE), $e['alto'], $ancla, ($e['invertido'] ?? false) ? '#fff' : '#000', $ajuste, e($e['texto'])
        );
    }

    private function qr(array $e): string {
        $matriz = Encoder::encode($e['dato'], ErrorCorrectionLevel::M(), 'ISO-8859-1', null, false)->getMatrix();
        $ruta = '';
        for ($fila = 0; $fila < $matriz->getHeight(); $fila++) {
            for ($col = 0; $col < $matriz->getWidth(); $col++) {
                if ($matriz->get($col, $fila) === 1) {
                    $ruta .= sprintf('M%d %dh%dv%dh-%dz', $e['x'] + $col * $e['modulo'], $e['y'] + $fila * $e['modulo'], $e['modulo'], $e['modulo'], $e['modulo']);
                }
            }
        }

        return '<path fill="#000" d="'.$ruta.'"/>';
    }

    private function imagen(array $e): string {
        $logo = $e['logo'];
        $ruta = '';
        for ($y = 0; $y < $logo->alto; $y++) {
            $inicio = null;
            for ($x = 0; $x <= $logo->ancho; $x++) {
                $tinta = $x < $logo->ancho && $logo->tinta($x, $y);
                if ($tinta && $inicio === null) {
                    $inicio = $x;
                } elseif (! $tinta && $inicio !== null) {
                    $ruta .= sprintf('M%d %dh%dv1h-%dz', $e['x'] + $inicio, $e['y'] + $y, $x - $inicio, $x - $inicio);
                    $inicio = null;
                }
            }
        }

        return '<path fill="#000" d="'.$ruta.'"/>';
    }

    private function barras(array $e): string {
        $x = $e['x'];
        $rects = '';
        foreach ((new TypeCode128())->getBarcode($e['dato'])->getBars() as $barra) {
            $ancho = $barra->getWidth() * $e['modulo'];
            if ($barra->isBar()) {
                $rects .= sprintf('<rect x="%d" y="%d" width="%d" height="%d"/>', $x, $e['y'], $ancho, $e['alto']);
            }
            $x += $ancho;
        }

        return '<g fill="#000">'.$rects.'</g>';
    }
}
