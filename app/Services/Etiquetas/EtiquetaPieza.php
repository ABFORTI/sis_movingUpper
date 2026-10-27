<?php

namespace App\Services\Etiquetas;

/**
 *  75 mm × 50 mm (600 × 400 dots),
 * ┌─────────────────────────────────────────────────┐ 0
 * │ MODELO                                 [DEMO A] │ 2 mm    rótulo 2.5 mm (demo: blanco sobre negro)
 * │ RF-2364F                                        │ 4.75    hasta 8 mm
 * │ EMBARQUE                                        │ 13.5
 * │ MUD-0001 · E-003                                │ 16.25   hasta 7 mm
 * │        ║║│║║│║║│║║│║║│║║│║║│║║│║║│║║            │ 25      Code 128, 17 mm de alto
 * │                 SN-000123                       │ 42.75   3.5 mm
 * └─────────────────────────────────────────────────┘ 50 mm
 */
class EtiquetaPieza {
    private const ROTULO = 2.5;
    private const Y_MODELO = 2;
    private const Y_EMBARQUE = 13.5;
    private const MODELO_MAX = 8;
    private const MODELO_CONVERTIDO_MAX = 5.25;
    private const Y_SKU_ANTERIOR = 10.5;
    private const EMBARQUE_MAX = 7;
    private const VALOR_MIN = 3.5;
    private const Y_BARRAS = 25;
    private const ALTO_BARRAS = 17;
    private const TEXTO_NS_MAX = 3.5;
    private const TEXTO_NS_MIN = 2;
    private const ZONA_BLANCA = 2.5;
    private const Y_DEMO = 1.5;
    private const ALTO_DEMO = 3;
    private const RELLENO_DEMO = 1.25;

    public function __construct(private readonly FormatoEtiqueta $formato) { }

    public static function codificable(string $numeroSerie): bool {
        return $numeroSerie !== '' && preg_match('/^[\x20-\x7E]+$/', $numeroSerie) === 1;
    }

    /** $demo: "DEMO A"… o null si no aplica. */
    public function lienzo(string $modelo, string $embarque, string $numeroSerie, ?string $skuAnterior = null, ?string $demo = null): Lienzo {
        $f = $this->formato;
        $mm = fn (float $v) => $f->dots($v);
        $l = new Lienzo($f);
        $x = $f->margenX;
        $ancho = $f->anchoUtil();

        $this->campo($l, $mm(self::Y_MODELO), 'Modelo', $modelo, $skuAnterior === null ? self::MODELO_MAX : self::MODELO_CONVERTIDO_MAX);
        if ($demo !== null) {
            // En la franja del rótulo, a la derecha: termina antes de que empiece el modelo.
            $alto = $mm(self::ROTULO);
            $caja = (int) ceil(mb_strlen($demo) * $alto * Lienzo::PROPORCION_CARACTER) + 2 * $mm(self::RELLENO_DEMO);
            $l->recuadro($x + $ancho - $caja, $mm(self::Y_DEMO), $caja, $mm(self::ALTO_DEMO));
            $l->texto($x + $ancho - $caja, $mm(self::Y_DEMO) + intdiv($mm(self::ALTO_DEMO) - $alto, 2), $caja, $alto, $demo, 'C', invertido: true);
        }
        if ($skuAnterior !== null) {
            $l->texto($x, $mm(self::Y_SKU_ANTERIOR), $ancho, $mm(self::ROTULO), "SKU anterior: {$skuAnterior}", 'L');
        }
        $this->campo($l, $mm(self::Y_EMBARQUE), 'Embarque', $embarque, self::EMBARQUE_MAX);

        $yBarras = $mm(self::Y_BARRAS);
        $altoBarras = $mm(self::ALTO_BARRAS);
        $yTexto = $yBarras + $altoBarras + $mm(0.75);
        $altoTexto = min($f->limiteInferior() - $yTexto, Lienzo::altoQueCabe($numeroSerie, $ancho, $mm(self::TEXTO_NS_MAX), $mm(self::TEXTO_NS_MIN)));

        if (self::codificable($numeroSerie)) {
            $l->codigoBarras($yBarras, $numeroSerie, $altoBarras, null, $ancho - 2 * $mm(self::ZONA_BLANCA));
            $l->texto($x, $yTexto, $ancho, $altoTexto, $numeroSerie, 'C');
        } else {
            $l->texto($x, $yBarras, $ancho, $mm(self::ROTULO), 'N/S · SIN CÓDIGO DE BARRAS', 'C');
            $l->textoEnBloque($x, $yBarras + $mm(self::ROTULO + 1), $ancho, $numeroSerie, $mm(self::MODELO_MAX), $mm(self::TEXTO_NS_MIN), 'C');
        }

        return $l;
    }

    private function campo(Lienzo $l, int $y, string $rotulo, string $valor, float $maxMm): void {
        $f = $this->formato;
        $l->texto($f->margenX, $y, $f->anchoUtil(), $f->dots(self::ROTULO), mb_strtoupper($rotulo), 'L');
        $l->textoEnBloque($f->margenX, $y + $f->dots(self::ROTULO + 0.25), $f->anchoUtil(), $valor, $f->dots($maxMm), $f->dots(self::VALOR_MIN));
    }
}
