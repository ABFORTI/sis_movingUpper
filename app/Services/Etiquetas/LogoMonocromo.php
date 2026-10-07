<?php

namespace App\Services\Etiquetas;

use Illuminate\Support\Facades\Cache;
use RuntimeException;


final class LogoMonocromo {
    private const UMBRAL = 150;

    /** @var array<string, self> */
    private static array $cache = [];

    /**
     * @param  list<string>  $filas  (MSB = píxel izquierdo)
     */
    private function __construct(
        public readonly int $ancho,
        public readonly int $alto,
        public readonly array $filas,
    ) { }

    public static function ajustado(string $ruta, int $anchoMax, int $altoMax): self {
        $clave = $ruta.'|'.@filemtime($ruta)."|{$anchoMax}x{$altoMax}";

        return self::$cache[$clave] ??= self::desdeHex(Cache::rememberForever(
            'etiquetas.logo.'.md5($clave),
            fn () => self::crear($ruta, $anchoMax, $altoMax)->aHex(),
        ));
    }

    /** @return array{0: int, 1: int, 2: string} */
    private function aHex(): array {
        return [$this->ancho, $this->alto, bin2hex(implode('', $this->filas))];
    }

    private static function desdeHex(array $datos): self {
        [$ancho, $alto, $hex] = $datos;

        return new self($ancho, $alto, str_split(hex2bin($hex), intdiv($ancho + 7, 8)));
    }

    public function bytesPorFila(): int {
        return intdiv($this->ancho + 7, 8);
    }

    public function tinta(int $x, int $y): bool {
        return (ord($this->filas[$y][intdiv($x, 8)]) >> (7 - $x % 8) & 1) === 1;
    }

    private static function crear(string $ruta, int $anchoMax, int $altoMax): self {
        $origen = is_file($ruta) ? @imagecreatefrompng($ruta) : false;
        if (! $origen) {
            throw new RuntimeException("No se pudo leer el logo: {$ruta}");
        }

        [$w, $h] = [imagesx($origen), imagesy($origen)];
        $plano = imagecreatetruecolor($w, $h);
        imagefill($plano, 0, 0, imagecolorallocate($plano, 255, 255, 255));
        imagecopy($plano, $origen, 0, 0, 0, 0, $w, $h);

        [$x0, $y0, $bw, $bh] = self::cajaDeTinta($plano, $w, $h);

        $escala = min($anchoMax / $bw, $altoMax / $bh);
        $ancho = max(1, (int) floor($bw * $escala));
        $alto = max(1, (int) floor($bh * $escala));

        $destino = imagecreatetruecolor($ancho, $alto);
        imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
        imagecopyresampled($destino, $plano, 0, 0, $x0, $y0, $ancho, $alto, $bw, $bh);

        $filas = [];
        for ($y = 0; $y < $alto; $y++) {
            $fila = '';
            for ($byte = 0; $byte < intdiv($ancho + 7, 8); $byte++) {
                $valor = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $byte * 8 + $bit;
                    if ($x < $ancho && self::esTinta(imagecolorat($destino, $x, $y))) {
                        $valor |= 0x80 >> $bit;
                    }
                }
                $fila .= chr($valor);
            }
            $filas[] = $fila;
        }

        return new self($ancho, $alto, $filas);
    }

    private static function esTinta(int $color): bool {
        return min(($color >> 16) & 255, ($color >> 8) & 255, $color & 255) < self::UMBRAL;
    }

    private static function cajaDeTinta($imagen, int $w, int $h): array {
        [$minX, $minY, $maxX, $maxY] = [$w, $h, -1, -1];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (self::esTinta(imagecolorat($imagen, $x, $y))) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }

        return $maxX < 0 ? [0, 0, $w, $h] : [$minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1];
    }
}
