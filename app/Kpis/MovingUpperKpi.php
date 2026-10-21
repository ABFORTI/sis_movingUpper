<?php

namespace App\Kpis;

use App\KpiApi\AbstractKpi;
use App\KpiApi\Exceptions\KpiApiException;
use App\KpiApi\KpiQuery;


abstract class MovingUpperKpi extends AbstractKpi {
    /**
     *
     * @return list<int>|null
     */
    protected function usuarios(KpiQuery $consulta): ?array {
        $valores = $consulta->filtro('usuario');
        if ($valores === null) {
            return null;
        }

        foreach ($valores as $valor) {
            if (! ctype_digit($valor)) {
                throw KpiApiException::uno(422, KpiApiException::PARAMETRO_INVALIDO,
                    "El filtro usuario espera IDs numéricos separados por coma; se recibió \"{$valor}\".", 'usuario');
            }
        }

        return array_map('intval', $valores);
    }
}
