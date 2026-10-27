# API de KPIs — MovingUpper (WMS Upper Logistics Qro)

| Dato | Valor |
|---|---|
| Clave de sistema | `movingupper` |
| Base URL | `https://<host>/api/v3/` |
| Versión vigente | `v3` (única) |
| Zona horaria | `America/Mexico_City` (`APP_TIMEZONE`) |
| Endpoints | `GET /sistema`, `GET /kpis`, `GET /kpis/{clave}` |
| Límite | 60 peticiones/min por token (`KPI_API_LIMITE_POR_MINUTO`) |

## 1. Acceso

| Clave | Qué mide | Agrupaciones (default primero) | Filtros | Paginable |
|---|---|---|---|---|
| `numeros-serie-escaneados` | N/S escaneados | `mes` `dia` `semana` `trimestre` `anio` `total` | `usuario`, `producto` | No |
| `tarimas-registradas` | Tarimas guardadas en el historial | igual | `usuario`, `producto` | No |
| `skus-registrados` | SKUs capturados en tarimas | igual | `usuario`, `producto` | No |
| `etiquetas-impresas` | Etiquetas físicas, impresiones y reimpresiones | igual | `usuario` | No |
| `productividad-por-usuario` | Actividad de cada usuario | `total` | `usuario` | Sí |

Parámetros comunes (ESTANDAR.md §6.1): `fecha_inicio`, `fecha_fin` (`YYYY-MM-DD`,
inclusivas; default: del día 1 del mes de hace 11 meses a hoy) y `agrupacion`.
Ningún KPI tiene parámetros propios.

- **Semana**: ISO 8601, de lunes a domingo; etiqueta `2026-W41`. Una semana que
  cruza de año pertenece al año ISO de su jueves (`2026-W01` empieza el 29-dic-2025).
- **Trimestre**: Q1 ene–mar, Q2 abr–jun, Q3 jul–sep, Q4 oct–dic; la etiqueta lleva
  el año (`2025-Q4`, `2026-Q1`), así que nunca se mezclan años.
- **Día**: fecha local del evento. Los registros se guardan en hora local, de modo
  que las 23:59:59 del 30-sep cuentan en septiembre y las 00:00:00 del 1-oct en octubre.
- Las cubetas de los extremos se recortan al rango (ESTANDAR.md §7.2).

### Filtros

| Filtro | Identificador | Notas |
|---|---|---|
| `usuario` | `users.id` numérico, varios separados por coma | Un valor no numérico → 422 `PARAMETRO_INVALIDO`. Un ID que no existe no es error: devuelve ceros |
| `producto` | SKU exacto, varios separados por coma | La comparación sigue la intercalación de la BD (MySQL: sin distinguir mayúsculas) |
| `area`, `cliente`, `turno` | — | **No existen** en el modelo de datos de MovingUpper: 422 `FILTRO_NO_SOPORTADO` |

No hay almacenes ni áreas en el sistema. `destinos` (PROD-A Tornillería, …) es la
categoría del producto, no una ubicación, y no se expone como filtro.

## 3. Definición de cada KPI

Reglas comunes:

- **Solo datos vivos**
- **Comparación con el periodo anterior** 


```bash
TOKEN=...
BASE=https://movingupper.upperlogistics.mx/api/v3

curl -H "Authorization: Bearer $TOKEN" $BASE/kpis
curl -H "Authorization: Bearer $TOKEN" "$BASE/kpis/numeros-serie-escaneados?fecha_inicio=2026-08-01&fecha_fin=2026-10-06&agrupacion=mes"
curl -H "Authorization: Bearer $TOKEN" "$BASE/kpis/tarimas-registradas?fecha_inicio=2026-09-28&fecha_fin=2026-10-11&agrupacion=semana&usuario=6,9"
curl -H "Authorization: Bearer $TOKEN" "$BASE/kpis/etiquetas-impresas?fecha_inicio=2026-07-01&agrupacion=trimestre"
curl -H "Authorization: Bearer $TOKEN" "$BASE/kpis/productividad-por-usuario?fecha_inicio=2026-10-01&pagina=1&por_pagina=20"
```

**200 — serie mensual** (`numeros-serie-escaneados`, 1-ago a 6-oct):

```json
{
  "exito": true,
  "version_api": "v3",
  "sistema": { "clave": "movingupper", "nombre": "MovingUpper — WMS Upper Logistics Qro", "entorno": "production" },
  "kpi": { "clave": "numeros-serie-escaneados", "nombre": "Números de serie escaneados", "unidad": "números de serie" },
  "periodo": { "fecha_inicio": "2026-08-01", "fecha_fin": "2026-10-06", "agrupacion": "mes", "zona_horaria": "America/Mexico_City" },
  "filtros": {},
  "datos": [
    { "periodo": "2026-08", "periodo_inicio": "2026-08-01", "periodo_fin": "2026-08-31", "total_numeros_serie": 2, "total_periodo_anterior": 0, "porcentaje_variacion": null },
    { "periodo": "2026-09", "periodo_inicio": "2026-09-01", "periodo_fin": "2026-09-30", "total_numeros_serie": 4, "total_periodo_anterior": 2, "porcentaje_variacion": 100.0 },
    { "periodo": "2026-10", "periodo_inicio": "2026-10-01", "periodo_fin": "2026-10-06", "total_numeros_serie": 1, "total_periodo_anterior": 1, "porcentaje_variacion": 0.0 }
  ],
  "metadatos": { "total_registros": 3, "paginacion": null, "generado_en": "2026-10-06T18:00:00Z", "id_peticion": "1b0c5c1e-3f0e-4d7a-9a52-0f5d4f6c2a10", "version_estandar": "1.0" },
  "errores": []
}
```

**200 — total** (`etiquetas-impresas`), solo `datos`:

```json
"datos": [
  { "total_etiquetas": 4, "total_impresiones": 2, "total_reimpresiones": 1, "total_tarimas_impresas": 1, "total_impresiones_sin_conteo": 0, "total_periodo_anterior": 0, "porcentaje_variacion": null }
]
```

**200 — tabla** (`productividad-por-usuario`), solo `datos` y `metadatos.paginacion`:

```json
"datos": [
  { "usuario_id": 3, "usuario_nombre": "Ana", "total_numeros_serie": 2, "total_registros": 2, "total_tarimas": 0, "total_impresiones": 0 },
  { "usuario_id": 4, "usuario_nombre": "Beto", "total_numeros_serie": 1, "total_registros": 2, "total_tarimas": 1, "total_impresiones": 0 }
],
"metadatos": { "total_registros": 2, "paginacion": { "pagina": 1, "por_pagina": 50, "total_paginas": 1 }, "...": "..." }
```

**422** — filtro que no existe en este sistema:

```json
"errores": [ { "codigo": "FILTRO_NO_SOPORTADO", "mensaje": "El KPI numeros-serie-escaneados no soporta el filtro area.", "campo": "area" } ]
```
