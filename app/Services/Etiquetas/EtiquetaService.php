<?php

namespace App\Services\Etiquetas;

use App\Contracts\GeneradorEtiqueta;
use App\Models\Tarima;
use InvalidArgumentException;

class EtiquetaService {
    /** @param iterable<GeneradorEtiqueta> $generadores del más específico al más general */
    public function __construct(
        private readonly iterable $generadores,
        private readonly ZplRenderer $zpl,
        private readonly SvgRenderer $svg,
    ) { }

    /** @return list<Lienzo> */
    public function paginas(Tarima $tarima): array {
        foreach ($this->generadores as $generador) {
            if ($generador->aplicaA($tarima)) {
                return $generador->paginas($tarima);
            }
        }

        throw new InvalidArgumentException('La tarima no tiene productos para generar etiqueta.');
    }

    public function zpl(Tarima $tarima): string {
        return implode('', array_map($this->zpl->render(...), $this->paginas($tarima)));
    }

    /** @return list<string> un SVG a escala real por etiqueta. */
    public function svg(Tarima $tarima): array {
        $paginas = $this->paginas($tarima);

        return array_map(
            fn (Lienzo $l, int $i) => $this->svg->render($l, "{$tarima->nombre}, etiqueta ".($i + 1).' de '.count($paginas)),
            $paginas,
            array_keys($paginas),
        );
    }
}
