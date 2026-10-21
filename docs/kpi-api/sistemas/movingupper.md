# API de KPIs — MovingUpper (WMS Upper Logistics Qro)

Catálogo y reglas de cálculo de los KPIs que MovingUpper publica para el sistema
central. El contrato común (autenticación, sobre JSON, errores, parámetros) es el
de [ESTANDAR.md](../ESTANDAR.md) v1.0; aquí solo está lo propio de este sistema.

| Dato | Valor |
|---|---|
| Clave de sistema | `movingupper` |
| Base URL | `https://<host>/api/v3/` |
| Versión vigente | `v3` (única) |
| Zona horaria | `America/Mexico_City` (`APP_TIMEZONE`) |
| Endpoints | `GET /sistema`, `GET /kpis`, `GET /kpis/{clave}` |
| Límite | 60 peticiones/min por token (`KPI_API_LIMITE_POR_MINUTO`) |

> **Por qué `v3` y no `v1`.** ESTANDAR.md §2 indica que un sistema nuevo empieza
> en `v1`. MovingUpper publica directamente en `v3` por decisión del proyecto,
> para alinear la URL con el sistema central. No existen `v1` ni `v2` en este
> sistema: responden 404.

## 1. Acceso

Solo con `Authorization: Bearer <token>` cuyo token tenga la ability `kpis:read`.
**El rol del usuario no da acceso**: una sesión web, aunque sea de administrador,
recibe 401 (`config/sanctum.php` → `guard` vacío, para que la sesión no entre como
token comodín).

```bash
# Crea la cuenta de servicio (sin rol, contraseña aleatoria) y emite el token
php artisan api:token desarrollo@ab-forti.com --name=kpi-central --crear
# Tokens adicionales para la misma cuenta (otro consumidor, pruebas)
php artisan api:token kpi-central@upperlogistics.mx --name=kpi-central-pruebas
# Revocar
php artisan tinker --execute="\Laravel\Sanctum\PersonalAccessToken::where('name','kpi-central')->delete();"
```

La cuenta de servicio no tiene rol: aunque alguien conociera su contraseña, no
vería tarimas ni historial en la web.

## 2. Catálogo

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

- **Solo datos vivos**: nada en papelera (`deleted_at` no nulo) cuenta (ESTANDAR.md §10.5).
  Las tarimas eliminadas por el administrador y los vaciados definitivos se borran
  físicamente y tampoco cuentan.
- **Comparación con el periodo anterior** (todos los KPIs de serie). Cada renglón
  termina con `total_periodo_anterior` y `porcentaje_var1iacion`, sobre el campo
  principal del KPI:
  - Cubeta completa → cubeta anterior completa (sep-2026 contra ago-2026; 2026-Q1 contra 2025-Q4).
  - Cubeta recortada → el mismo tramo de la cubeta anterior (1–6 oct contra 1–6 sep;
    10–31 ene contra 10–31 dic). Así el mes en curso se compara contra el mismo avance del mes pasado.
  - `agrupacion=total` → el mismo número de días justo antes de `fecha_inicio`
    (sep-2026, 30 días → 2-ago a 31-ago).
  - `porcentaje_variacion = (actual − anterior) / anterior × 100`, 1 decimal.
    Es `null` si `anterior = 0`. **Puede ser negativo o mayor a 100**: es una
    variación, no una proporción 0–100 como describe ESTANDAR.md §7.3.



## 4. Ejemplos

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

## 5. Códigos HTTP

Los de ESTANDAR.md §8, sin excepciones. Casos de este sistema:

| Caso | HTTP | `codigo` |
|---|---|---|
| Sin token, token inválido o revocado, o solo sesión web | 401 | `NO_AUTENTICADO` |
| Token sin `kpis:read` | 403 | `SIN_PERMISO` |
| `/kpis/no-existe` | 404 | `KPI_NO_ENCONTRADO` |
| `POST`/`PUT`/`PATCH`/`DELETE` | 405 | `METODO_NO_PERMITIDO` |
| `fecha_inicio=2026-13-01`, `agrupacion=hora`, `usuario=abc`, `usuario[]=1` | 422 | `PARAMETRO_INVALIDO` |
| `fecha_inicio` > `fecha_fin`, más de 1 830 días, más de 400 puntos | 422 | `RANGO_FECHAS_INVALIDO` |
| `area`, `cliente`, `turno`; `producto` en `etiquetas-impresas` | 422 | `FILTRO_NO_SOPORTADO` |
| Periodo sin datos o `usuario` inexistente | 200 | — (serie en ceros / `datos: []`) |

El estándar no usa 400: todo error de parámetros es 422.

## 6. Rendimiento

- Las series se agregan **por día en SQL** (`GROUP BY DATE(columna)`). PHP solo
  suma esos renglones en semanas, meses, trimestres y años (`SerieDiariaKpi`).
  Nunca se cargan registros individuales.
  - Como máximo llega un renglón por día del rango pedido más el periodo de comparación.
  - Los KPIs con conteos distintos (`total_skus_distintos`, `total_tarimas_impresas`)
    traen un renglón por día y SKU, o por día y tarima.
- Una sola consulta por KPI de serie, incluida la comparación con el periodo anterior.
  `productividad-por-usuario` hace 4 `GROUP BY user_id` (uno por tabla) más una
  consulta de nombres, sin importar cuántos usuarios haya.
- `DATE()`, `COUNT`, `SUM(CASE …)` y `COALESCE` funcionan igual en MySQL y en
  SQLite (pruebas), así que no hay SQL por motor.
- Índices: `numeros_serie(deleted_at, created_at)` y `registros(deleted_at, created_at)`
  (migración `2026_10_06_000003_add_kpi_indexes`); `tarimas.guardada_at` ya estaba indexado;
  `impresiones.created_at` nace indexado.

## 7. Restricciones conocidas y supuestos

1. **Los conteos de periodos pasados pueden bajar.** No hay bitácora de eventos de
   escaneo. Si después se vacía una tarima o se elimina, sus N/S y SKUs dejan de
   contar también en los días en que se escanearon.
2. **Tarimas temporales.** Sus N/S y SKUs cuentan en `numeros-serie-escaneados` y
   `skus-registrados` (es actividad de escaneo); la tarima cuenta en
   `tarimas-registradas` solo cuando se guarda.
3. **Etiquetas históricas**: ver §3.4.
4. **Sin almacén, área ni turno**: el modelo de datos no los tiene.
5. **Piezas por tarima**: no se publican. `Tarima::piezas()` cuenta 0 para un SKU
   padre sin N/S y `TarimaService::filas()` cuenta 1 (que es lo que usan el Excel
   y el consecutivo). Hay que unificar el criterio antes de publicar un KPI de piezas.
6. Producción usa MySQL y las pruebas automáticas usan SQLite. Las consultas solo
   usan funciones comunes a ambos, pero se recomienda una prueba de humo contra
   MySQL al desplegar.
