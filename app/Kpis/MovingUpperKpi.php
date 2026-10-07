<?php

namespace App\Kpis;

use App\KpiApi\AbstractKpi;
use App\KpiApi\Exceptions\KpiApiException;
use App\KpiApi\KpiQuery;

/**
 * Base de los KPIs de MovingUpper: traduce los filtros generales del estándar a
 * este sistema. Solo aplican `usuario` (users.id) y `producto` (SKU); no existen
 * área, cliente ni turno en el modelo de datos.
 */
abstract class MovingUpperKpi extends AbstractKpi {
    /**
     * IDs del filtro `usuario`, o null si no se envió. Un valor no numérico es un
     * error del consumidor (422), no un filtro que simplemente no encuentra datos.
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
