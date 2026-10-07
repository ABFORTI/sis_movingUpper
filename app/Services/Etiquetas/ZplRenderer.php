<?php

namespace App\Services\Etiquetas;

use InvalidArgumentException;

class ZplRenderer {
    private const MEDIA = ['gap' => 'Y', 'marca' => 'M', 'continuo' => 'N'];

    public function render(Lienzo $lienzo): string {
        $f = $lienzo->formato;
        $media = self::MEDIA[$f->media] ?? throw new InvalidArgumentException("Tipo de papel no válido: {$f->media}");
        $dx = $f->desfaseX;

        $zpl = [
            '^XA',
            '^CI28',             
            "^MN{$media}",             
            "^PW{$f->anchoImprimible}", 
            "^LL{$f->alto}",            
            '^LH0,0',
            '^PON',
        ];

        foreach ($lienzo->elementos() as $e) {
            $x = $e['x'] - $dx;
            $zpl[] = match ($e['tipo']) {
                'texto' => sprintf(
                    '^FO%d,%d^A0N,%d,%d^FB%d,1,0,%s%s^FH\\^FD%s^FS',
                    $x, $e['y'], $e['alto'], $e['alto'], $e['ancho'], $e['alineacion'], $e['invertido'] ? '^FR' : '', self::escapar($e['texto'])
                ),
                'qr' => sprintf('^FO%d,%d^BQN,2,%d^FH\\^FDMA,%s^FS', $x, max(0, $e['y'] - $f->ajusteQr), $e['modulo'], self::escapar($e['dato'])),
                'barras' => sprintf('^FO%d,%d^BY%d^BCN,%d,N,N,N,A^FH\\^FD%s^FS', $x, $e['y'], $e['modulo'], $e['alto'], self::escapar($e['dato'])),
                'linea' => sprintf('^FO%d,%d^GB%d,%d,%d^FS', $x, $e['y'], $e['ancho'], $e['grosor'], $e['grosor']),
                'recuadro' => sprintf('^FO%d,%d^GB%d,%d,%d^FS', $x, $e['y'], $e['ancho'], $e['alto'], min($e['ancho'], $e['alto'])),
                'imagen' => sprintf('^FO%d,%d%s^FS', $x, $e['y'], self::grafico($e['logo'])),
            };
        }

        $zpl[] = '^PQ1';
        $zpl[] = '^XZ';

        return implode("\n", $zpl)."\n";
    }

    public static function escapar(string $texto): string {
        return strtr($texto, ['\\' => '\\5C', '^' => '\\5E', '~' => '\\7E']);
    }

    private static function grafico(LogoMonocromo $logo): string {
        $bytes = $logo->bytesPorFila() * $logo->alto;

        return sprintf('^GFA,%d,%d,%d,%s', $bytes, $bytes, $logo->bytesPorFila(), strtoupper(bin2hex(implode('', $logo->filas))));
    }
}
