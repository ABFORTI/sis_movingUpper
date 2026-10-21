<?php

namespace App\Support;


final class ConversionSku {
    public static function cambio(?string $sku): ?string {
        $norm = NumeroSerieNormalizado::de($sku);
        $cambio = self::catalogo()[$norm] ?? null;

        return $cambio !== null && NumeroSerieNormalizado::de($cambio) !== $norm ? $cambio : null;
    }

    /**
     *
     * @return list<string>
     */
    public static function equivalentes(?string $sku): array {
        $norm = NumeroSerieNormalizado::de($sku);
        $formas = [$norm];
        foreach (self::catalogo() as $codigo => $cambio) {
            $cambio = NumeroSerieNormalizado::de($cambio);
            if ($codigo === $norm || $cambio === $norm) {
                array_push($formas, $codigo, $cambio);
            }
        }

        return array_values(array_unique($formas));
    }

    /** @return array<string, string> código normalizado => SKU equivalente tal como está escrito */
    private static function catalogo(): array {
        $catalogo = [];
        foreach ((array) config('conversiones_sku', []) as $codigo => $cambio) {
            $codigo = NumeroSerieNormalizado::de((string) $codigo);
            $cambio = NumeroSerieNormalizado::limpio((string) $cambio);
            if ($codigo !== '' && $cambio !== '') {
                $catalogo[$codigo] = $cambio;
            }
        }

        return $catalogo;
    }
}
