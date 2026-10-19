<?php

namespace App\Services\Etiquetas;

/**
 * Etiqueta de prueba para verificar la impresora sin tocar ninguna tarima.
 *
 * Dibuja un marco en el borde exacto del área imprimible, marcas "ARRIBA" y
 * "ABAJO", una regla cada 5 mm, un código de barras y un QR. Al verla impresa:
 * - Los cuatro bordes completos y "ARRIBA" pegado al borde superior: el papel
 *   está bien calibrado.
 * - Falta el borde inferior o "ARRIBA" sale bajo: la impresora no reconoce el
 *   inicio de la etiqueta (calibrar) o hay un ^LT viejo (lo corrige el ZPL).
 * - Sale cortada en dos etiquetas: el largo del papel no coincide (calibrar).
 *
 * Usa el mismo Lienzo que las etiquetas reales: la vista previa en pantalla y
 * lo que se imprime salen del mismo diseño.
 */
class EtiquetaPrueba {
    private const BORDE = 4;

    public function __construct(
        private readonly FormatoEtiqueta $formato,
        private readonly ZplRenderer $zpl,
        private readonly SvgRenderer $svg,
    ) { }

    public function lienzo(?string $hora = null): Lienzo {
        $f = $this->formato;
        $l = new Lienzo($f);
        $x0 = $f->desfaseX;
        $ancho = $f->anchoImprimible;
        $mm = fn (float $v) => $f->dots($v);

        // Marco en el borde del área imprimible.
        $l->linea(0, self::BORDE, $x0, $ancho);
        $l->linea($f->alto - self::BORDE, self::BORDE, $x0, $ancho);
        $l->linea(0, $f->alto, $x0, self::BORDE);
        $l->linea(0, $f->alto, $x0 + $ancho - self::BORDE, self::BORDE);

        // Regla en el borde izquierdo: marca cada 5 mm, larga cada 10 mm.
        for ($v = 5; $v * $f->dotsPorMm() < $f->alto - self::BORDE; $v += 5) {
            $y = $mm($v);
            $largo = $v % 10 === 0 ? $mm(4) : $mm(2);
            $l->linea($y, 2, $x0, $largo);
            if ($v % 10 === 0) {
                $l->texto($x0 + $mm(4.5), $y - $mm(1.25), $mm(6), $mm(2.5), (string) $v, 'L');
            }
        }

        $izquierda = $x0 + $mm(11);
        $util = $ancho - $mm(13);
        $l->texto($izquierda, $mm(1.5), $util, $mm(3.5), '== ARRIBA ==', 'C');
        $l->texto($izquierda, $mm(6.5), $util, $mm(5), 'PRUEBA DE IMPRESIÓN', 'C');
        $l->texto($izquierda, $mm(12.5), $util, $mm(3), "{$f->medidas()} · {$f->dpi} dpi · papel {$f->media}", 'C');
        $l->texto($izquierda, $mm(16.5), $util, $mm(2.75), 'Bordes completos = papel bien calibrado', 'C');

        $l->qrEnCaja($izquierda, $mm(20.5), $mm(16), $mm(16), 'PRUEBA-ZQ620', 4);
        $l->texto($izquierda + $mm(17), $mm(22), $util - $mm(17), $mm(3), 'Texto pequeño legible?', 'L');
        $l->texto($izquierda + $mm(17), $mm(26.5), $util - $mm(17), $mm(2.5), 'abcdefghijklmnñopqrstuvwxyz 0123456789', 'L');
        $l->texto($izquierda + $mm(17), $mm(30.5), $util - $mm(17), $mm(2.5), $hora ?? now()->format('d/m/Y H:i'), 'L');

        $l->codigoBarras($mm(38), 'PRUEBA-ZQ620', $mm(6), $izquierda);
        $l->texto($izquierda, $f->alto - self::BORDE - $mm(3.75), $util, $mm(3), '== ABAJO ==', 'R');

        return $l;
    }

    public function zpl(): string {
        return $this->zpl->render($this->lienzo());
    }

    public function svg(): string {
        return $this->svg->render($this->lienzo(), 'Etiqueta de prueba');
    }

    /**
     * Ajustes para la ZQ620 que se guardan en la impresora (^JUS) y calibración
     * del sensor de papel (~JC, avanza 1 o 2 etiquetas). Solo se envía cuando el
     * operador lo pide, desde la página de prueba.
     */
    public function configuracion(): string {
        $f = $this->formato;
        $media = ['gap' => 'Y', 'marca' => 'M', 'continuo' => 'N'][$f->media] ?? 'Y';

        return implode("\n", [
            '^XA',
            '^MTD',
            "^MN{$media}",
            "^PW{$f->anchoImprimible}",
            "^LL{$f->alto}",
            '^LH0,0',
            '^LT0',
            '^LS0',
            '^JUS',
            '^XZ',
            '~JC',
        ])."\n";
    }
}
