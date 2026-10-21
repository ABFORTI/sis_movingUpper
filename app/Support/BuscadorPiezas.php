<?php

namespace App\Support;

use App\Models\Mudanza;
use App\Models\Partida;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;


class BuscadorPiezas {
    /** @return Collection<int, Partida> */
    public static function resolver(Mudanza $mudanza, ?string $texto, string $campo = 'piezas'): Collection {
        $valores = collect(preg_split('/[\s,;]+/', (string) $texto, -1, PREG_SPLIT_NO_EMPTY))->unique()->values();
        if ($valores->isEmpty()) {
            return collect();
        }

        $encontradas = collect();
        $faltan = [];
        foreach ($valores as $valor) {
            $partida = Partida::where('mudanza_id', $mudanza->id)->where('numero_serie', $valor)->latest('id')->first()
                ?? (ctype_digit($valor) ? Partida::where('mudanza_id', $mudanza->id)->where('consecutivo', (int) $valor)->first() : null);
            $partida ? $encontradas->push($partida) : $faltan[] = $valor;
        }

        if ($faltan) {
            throw ValidationException::withMessages([
                $campo => 'No hay piezas guardadas con: '.implode(', ', $faltan).'. Usa la N/S o el consecutivo; si la pieza aún no se guarda, descríbela en el texto.',
            ]);
        }

        return $encontradas->unique('id')->values();
    }
}
