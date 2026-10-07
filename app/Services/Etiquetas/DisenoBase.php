<?php

namespace App\Services\Etiquetas;

use App\Contracts\GeneradorEtiqueta;
use App\Models\Tarima;
use App\Services\TarimaService;

abstract class DisenoBase implements GeneradorEtiqueta {
    protected const ROTULO = 2;
    protected const TEXTO_APOYO = 2.25;
    protected const TEXTO_NORMAL = 2.75;

    private const ALTO_LOGO = 4.25;
    private const ANCHO_LOGO = 21;
    private const Y_LINEA = 7;
    private const GROSOR_LINEA = 3;
    private const ALTO_BARRAS = 5;
    private const SEPARACION_PIE = 0.75;

    public function __construct(
        protected readonly TarimaService $tarimas,
        protected readonly FormatoEtiqueta $formato,
    ) { }

    protected function mm(float $mm): int {
        return $this->formato->dots($mm);
    }

    protected function nuevoLienzo(): Lienzo {
        return new Lienzo($this->formato);
    }

    protected function franjaLogos(Lienzo $lienzo): int {
        $y = $this->formato->margen;
        $alto = $this->mm(self::ALTO_LOGO);
        $ancho = $this->mm(self::ANCHO_LOGO);

        $izquierdo = LogoMonocromo::ajustado(config('etiquetas.logos.izquierdo'), $ancho, $alto);
        $derecho = LogoMonocromo::ajustado(config('etiquetas.logos.derecho'), $ancho, $alto);

        $lienzo->imagen($this->formato->margenX, $y + intdiv($alto - $izquierdo->alto, 2), $izquierdo);
        $lienzo->imagen($this->formato->limiteDerecho() - $derecho->ancho, $y + intdiv($alto - $derecho->alto, 2), $derecho);

        $lienzo->linea($this->mm(self::Y_LINEA), self::GROSOR_LINEA);

        return $this->mm(self::Y_LINEA) + self::GROSOR_LINEA + $this->mm(0.375);
    }

    protected function inicioPie(): int {
        return $this->formato->limiteInferior() - $this->mm(self::ALTO_BARRAS);
    }

    protected function finCuerpo(): int {
        return $this->inicioPie() - $this->mm(self::SEPARACION_PIE);
    }

    protected function pie(Lienzo $lienzo, Tarima $tarima): void {
        $codigo = $this->tarimas->codigoBarras($tarima);
        $x = $this->formato->margenX;
        $y = $this->inicioPie();
        $alto = $this->mm(self::ALTO_BARRAS);

        $anchoBarras = $lienzo->codigoBarras($y, $codigo, $alto, $x);

        $xTexto = $x + $anchoBarras + $this->mm(1.5);
        $anchoTexto = $this->formato->limiteDerecho() - $xTexto;
        $altoTexto = Lienzo::altoQueCabe($codigo, $anchoTexto, $this->mm(self::TEXTO_NORMAL), $this->mm(self::ROTULO));
        $lienzo->texto($xTexto, $y + intdiv($alto - $altoTexto, 2), $anchoTexto, $altoTexto, $codigo, 'R');
    }

    protected function bloqueTarima(Lienzo $lienzo, int $x, int $y, int $ancho, string $texto, float $altoMm = 5): int {
        $alto = $this->mm($altoMm);
        $altoTexto = Lienzo::altoQueCabe($texto, $ancho - $this->mm(2), $alto - $this->mm(1.25), $this->mm(self::TEXTO_APOYO));
        $lienzo->recuadro($x, $y, $ancho, $alto);
        $lienzo->texto($x + $this->mm(1), $y + intdiv($alto - $altoTexto, 2), $ancho - $this->mm(2), $altoTexto, $texto, 'L', true);

        return $alto;
    }

    protected function campo(Lienzo $lienzo, int $x, int $y, int $ancho, string $rotulo, string $valor, float $maxMm, float $minMm): int {
        $lienzo->texto($x, $y, $ancho, $this->mm(self::ROTULO), mb_strtoupper($rotulo), 'L');
        $yValor = $y + $this->mm(self::ROTULO + 0.25);
        $alto = $lienzo->textoEnBloque($x, $yValor, $ancho, $valor, $this->mm($maxMm), $this->mm($minMm));

        return $yValor + $alto - $y;
    }
}
