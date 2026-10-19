<?php

namespace App\Services\Etiquetas;

final class FormatoEtiqueta {
    public readonly int $ancho;
    public readonly int $alto;
    public readonly int $margen;
    public readonly int $anchoImprimible;
    public readonly int $desfaseX;
    public readonly int $margenX;
    public readonly int $ajusteQr;
    /** ^LT en dots: mueve todo el contenido arriba (-) o abajo (+). ZPL acepta -120…120. */
    public readonly int $ajusteSuperior;

    public function __construct(
        public readonly int $dpi,
        float $anchoMm,
        float $altoMm,
        float $margenMm,
        public readonly string $media = 'gap',
        ?float $anchoCabezalMm = null,
        float $ajusteQrMm = 0,
        float $ajusteSuperiorMm = 0,
    ) {
        $this->ancho = $this->dots($anchoMm);
        $this->alto = $this->dots($altoMm);
        $this->margen = $this->dots($margenMm);
        $this->anchoImprimible = min($this->ancho, $anchoCabezalMm ? $this->dots($anchoCabezalMm) : $this->ancho);
        $this->desfaseX = intdiv($this->ancho - $this->anchoImprimible, 2);
        $this->margenX = $this->desfaseX + $this->margen;
        $this->ajusteQr = $this->dots($ajusteQrMm);
        $this->ajusteSuperior = max(-120, min(120, $this->dots($ajusteSuperiorMm)));
    }

    public static function desdeConfig(): self {
        return new self(
            config('etiquetas.dpi'),
            config('etiquetas.ancho_mm'),
            config('etiquetas.alto_mm'),
            config('etiquetas.margen_mm'),
            config('etiquetas.media'),
            config('etiquetas.ancho_cabezal_mm'),
            config('etiquetas.ajuste_qr_mm', 0),
            config('etiquetas.ajuste_superior_mm', 0),
        );
    }

    public function dots(float $mm): int {
        return (int) round($mm * $this->dotsPorMm());
    }

    public function dotsPorMm(): float {
        return $this->dpi === 203 ? 8.0 : $this->dpi / 25.4;
    }

    public function anchoUtil(): int {
        return $this->ancho - 2 * $this->margenX;
    }

    public function limiteDerecho(): int {
        return $this->ancho - $this->margenX;
    }

    public function limiteInferior(): int {
        return $this->alto - $this->margen;
    }

    public function anchoMm(): float {
        return $this->ancho / $this->dotsPorMm();
    }

    public function altoMm(): float {
        return $this->alto / $this->dotsPorMm();
    }

    public function medidas(): string {
        $mm = fn (float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');

        return "{$mm($this->anchoMm())} × {$mm($this->altoMm())} mm";
    }
}
