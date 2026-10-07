<?php

namespace App\Services\Etiquetas;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Picqer\Barcode\Types\TypeCode128;


final class Lienzo {
    public const PROPORCION_CARACTER = 0.6;
    public const ZONA_BLANCA_QR = 3;

    /** @var list<array<string, mixed>> */
    private array $elementos = [];

    public function __construct(public readonly FormatoEtiqueta $formato)
    { }

    public function texto(int $x, int $y, int $ancho, int $alto, string $texto, string $alineacion = 'C', bool $invertido = false): self {
        $this->elementos[] = [
            'tipo' => 'texto', 'x' => $x, 'y' => $y, 'ancho' => $ancho, 'alto' => $alto,
            'texto' => self::recortarAncho($texto, $ancho, $alto), 'alineacion' => $alineacion, 'invertido' => $invertido,
        ];

        return $this;
    }

    public function textoEnBloque(int $x, int $y, int $ancho, string $texto, int $max, int $min, string $alineacion = 'L'): int {
        $alto = self::altoQueCabe($texto, $ancho, $max, $min);
        $this->texto($x, $y, $ancho, $alto, $texto, $alineacion);

        return $alto;
    }

    public function textoAjustado(int $y, string $texto, int $max, int $min): int {
        return $this->textoEnBloque($this->formato->margenX, $y, $this->formato->anchoUtil(), $texto, $max, $min, 'C');
    }

    public function recuadro(int $x, int $y, int $ancho, int $alto): self {
        $this->elementos[] = ['tipo' => 'recuadro', 'x' => $x, 'y' => $y, 'ancho' => $ancho, 'alto' => $alto];

        return $this;
    }

    public function imagen(int $x, int $y, LogoMonocromo $logo): self {
        $this->elementos[] = [
            'tipo' => 'imagen', 'x' => $x, 'y' => $y, 'ancho' => $logo->ancho, 'alto' => $logo->alto, 'logo' => $logo,
        ];

        return $this;
    }

    public function qrEnCaja(int $x, int $y, int $ancho, int $alto, string $dato, int $moduloMax = 6): int {
        $modulos = self::modulosQr($dato);
        $conZona = $modulos + 2 * self::ZONA_BLANCA_QR;
        $modulo = max(3, min($moduloMax, intdiv(min($ancho, $alto), $conZona)));
        $lado = $modulos * $modulo;

        $this->elementos[] = [
            'tipo' => 'qr', 'x' => $x + max(0, intdiv($ancho - $lado, 2)), 'y' => $y + max(0, intdiv($alto - $lado, 2)),
            'dato' => $dato, 'modulo' => $modulo, 'modulos' => $modulos, 'lado' => $lado,
        ];

        return $lado;
    }

    public function codigoBarras(int $y, string $dato, int $alto, ?int $x = null, ?int $anchoMax = null): int {
        $modulos = (new TypeCode128())->getBarcode($dato)->getWidth();
        $anchoMax ??= $this->formato->anchoUtil();
        $modulo = $modulos * 2 <= $anchoMax ? 2 : 1;
        $ancho = $modulos * $modulo;

        $this->elementos[] = [
            'tipo' => 'barras', 'x' => $x ?? max(0, intdiv($this->formato->ancho - $ancho, 2)), 'y' => $y,
            'dato' => $dato, 'modulo' => $modulo, 'alto' => $alto, 'ancho' => $ancho,
        ];

        return $ancho;
    }

    public function linea(int $y, int $grosor = 2, ?int $x = null, ?int $ancho = null): void {
        $this->elementos[] = [
            'tipo' => 'linea', 'x' => $x ?? $this->formato->margenX, 'y' => $y,
            'ancho' => $ancho ?? $this->formato->anchoUtil(), 'grosor' => $grosor,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function elementos(): array {
        return $this->elementos;
    }

    public static function altoQueCabe(string $texto, int $ancho, int $max, int $min): int {
        $caracteres = max(1, mb_strlen($texto));

        return max($min, min($max, (int) floor($ancho / ($caracteres * self::PROPORCION_CARACTER))));
    }

    public static function recortarAncho(string $texto, int $ancho, int $alto): string {
        $maximo = max(1, (int) floor($ancho / ($alto * self::PROPORCION_CARACTER)));

        return mb_strlen($texto) > $maximo ? mb_substr($texto, 0, $maximo - 1).'…' : $texto;
    }

    public static function modulosQr(string $dato): int {
        return Encoder::encode($dato, ErrorCorrectionLevel::M(), 'ISO-8859-1', null, false)->getMatrix()->getWidth();
    }
}
