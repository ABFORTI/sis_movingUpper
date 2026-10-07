# Estándar de Integración de APIs REST de KPIs — v1.0

Especificación normativa que **todo sistema origen** (Cotizaciones, Producción,
Inventarios, …) debe cumplir para alimentar al **sistema central de KPIs**.

- **Sistema origen**: sistema que posee los datos y expone sus KPIs.
- **Sistema central**: único consumidor; consulta los KPIs de todos los sistemas.
- **Versión del estándar** (este documento, `1.0`) ≠ **versión de la API** de un
  sistema (`v1`, `v2`, …). Cada respuesta informa ambas.

Palabras clave: **DEBE / NO DEBE** = obligatorio. **PUEDE** = opcional.

Implementación de referencia (Laravel 11 + Sanctum): sistema de Cotizaciones,
directorio `app/KpiApi/`. Guía paso a paso: [IMPLEMENTACION.md](IMPLEMENTACION.md).

---

## 1. Autenticación

| Regla | Valor |
|---|---|
| Esquema | `Authorization: Bearer <token>` en **todas** las peticiones (no hay endpoints públicos) |
| Tipo de token | Token opaco de acceso personal (Laravel Sanctum). Se guarda solo su hash SHA-256 |
| Emisión | Por un administrador en el servidor: `php artisan api:token <email> --name=kpi-central` |
| Titular | Una **cuenta de servicio** dedicada (p. ej. `kpi-central@empresa.com`), nunca la de un empleado |
| Visibilidad | El token en texto plano se muestra **una sola vez** al emitirlo |
| Expiración | Sin expiración automática; se **rota** al menos una vez al año y se revoca ante sospecha de fuga |
| Revocación | Borrar el registro del token (ver IMPLEMENTACION.md §6). Efecto inmediato |
| Un token por consumidor | Cada integración (producción, pruebas, otro consumidor) tiene su propio token |

El token **NO DEBE** viajar en el query string, en cookies ni en el cuerpo.
Toda comunicación en producción **DEBE** ser HTTPS.

## 2. Versionado

- La versión va **en la URL**, inmediatamente después de `/api`: `/api/v1/…`,
  `/api/v2/…`, `/api/v3/…`. Sin subversiones (`v1.1` no existe).
- La versión **no** se negocia por header ni por parámetro.
- Cada sistema numera sus versiones de forma independiente. Un sistema nuevo
  empieza en `v1`.
- La versión pertenece a la Base URL, no al KPI: `/api/v2/kpis/top-clientes`,
  nunca `/api/kpis/v2/top-clientes` ni `/api/kpis/top-clientes-v2`.
- Reglas para crear versiones nuevas: §14.

> Caso especial: en Cotizaciones el estándar se publica en **v2**, porque `/api/v1`
> ya existía con un formato anterior y se conserva para no romper a sus consumidores.

## 3. Base URL

```
https://<host-del-sistema>/api/<version>/
```

Ejemplos: `https://cotizaciones.empresa.com/api/v2/`, `https://produccion.empresa.com/api/v1/`.

El sistema central guarda por sistema solo **dos datos**: la Base URL completa
(con versión) y el token. Todo lo demás se descubre con `GET /sistema` y `GET /kpis`.

## 4. Headers

### Petición

| Header | Obligatorio | Valor |
|---|---|---|
| `Authorization` | Sí | `Bearer <token>` |
| `Accept` | Recomendado | `application/json` (la API responde JSON aunque no se envíe) |
| `X-Request-Id` | Recomendado | ID de correlación del consumidor, `[A-Za-z0-9._:-]{1,100}`. Si falta o es inválido, el sistema genera un UUID |

### Respuesta (siempre, incluso en errores)

| Header | Valor |
|---|---|
| `Content-Type` | `application/json` |
| `X-Request-Id` | El recibido o el generado; igual a `metadatos.id_peticion` |
| `X-Api-Version` | `v1`, `v2`, … |
| `X-Source-System` | Clave del sistema origen (`cotizaciones`) |
| `Cache-Control` | `no-store, private` |
| `X-RateLimit-Limit` / `X-RateLimit-Remaining` | Cuota por minuto y restante |
| `Retry-After` | Solo en 429: segundos a esperar |
| `WWW-Authenticate` | Solo en 401: `Bearer` |
| `Allow` | Solo en 405: métodos permitidos (`GET, HEAD`) |

Los nombres de headers siguen la convención HTTP (inglés); todas las claves JSON
están en español.

## 5. Estructura de endpoints

Cada versión de cada sistema expone **exactamente** estos tres endpoints, todos `GET`:

| Endpoint | Propósito |
|---|---|
| `GET /api/{v}/sistema` | Identidad del sistema, versión, zona horaria. Sirve como prueba de conectividad y del token |
| `GET /api/{v}/kpis` | Catálogo autodescriptivo: qué KPIs hay, qué filtros, parámetros y campos tiene cada uno |
| `GET /api/{v}/kpis/{clave}` | Datos de un KPI |

- `{clave}`: kebab-case, minúsculas, sin acentos: `[a-z0-9]+(-[a-z0-9]+)*`.
- No se anidan recursos (`/kpis/{clave}/detalle` no existe). Un desglose distinto es otro KPI.
- Los endpoints de recursos crudos (listados de cotizaciones, órdenes, etc.) **no**
  forman parte del estándar y el sistema central no los usa.

## 6. Parámetros

Todos van en el **query string**. No hay cuerpo de petición.

### 6.1 Parámetros generales (mismo nombre y comportamiento en todos los sistemas)

| Parámetro | Formato | Default | Regla |
|---|---|---|---|
| `fecha_inicio` | `YYYY-MM-DD` | Día 1 del mes de hace 11 meses | Inclusiva, desde las 00:00:00 |
| `fecha_fin` | `YYYY-MM-DD` | Hoy | **Inclusiva**, hasta las 23:59:59 |
| `agrupacion` | `dia` `semana` `mes` `trimestre` `anio` `total` | La que declare el KPI | Solo las que el KPI declare en el catálogo |
| `pagina` | entero ≥ 1 | `1` | Solo en KPIs `paginable: true` |
| `por_pagina` | entero 1–500 | `50` | Solo en KPIs `paginable: true` |

Periodo por defecto = los últimos 12 meses calendario, incluyendo el mes en curso
(el 2 de octubre de 2026 → `2025-11-01` a `2026-10-02`).

Límites: rango máximo **1 830 días** (~5 años); una serie no puede tener más de
**400 puntos** (p. ej. `agrupacion=dia` no admite más de ~13 meses).

Las fechas del query se interpretan en la **zona horaria del sistema origen**,
que se informa en `periodo.zona_horaria` y en `GET /sistema`.

### 6.2 Filtros generales

| Filtro | Significado |
|---|---|
| `usuario` | Usuario responsable (vendedor, operador, capturista…) |
| `area` | Área / departamento / línea |
| `producto` | Producto / SKU |
| `cliente` | Cliente |
| `turno` | Turno |

- Valor: uno o varios identificadores separados por coma: `usuario=6,9`.
  Máx. 50 valores, cada uno de 1 a 100 caracteres. Entre valores es **OR**;
  entre filtros distintos es **AND**.
- **Cada KPI declara cuáles soporta** y qué identificador espera en su sistema
  (`GET /kpis` → `filtros[].descripcion`). Ej.: en Cotizaciones `cliente` es el
  nombre exacto del cliente; en Producción podría ser una clave numérica.
- Enviar un filtro general que el KPI no soporta → **422 `FILTRO_NO_SOPORTADO`**.
  Nunca se ignora en silencio.

### 6.3 Parámetros propios de un KPI

- Solo existen si el KPI los declara en el catálogo (`parametros[]`), con `tipo`
  (`integer`, `number`, `string`, `boolean`, `fecha`), `requerido`, `default` y `descripcion`.
- Nombre en snake_case. **NO DEBEN** usar los nombres reservados de §6.1 y §6.2.
  Si el concepto es general (p. ej. un cliente), se usa el filtro general.
- `boolean` acepta `true`, `false`, `1`, `0`.

### 6.4 Validación

La validación es **estricta** y en este orden (se reportan los errores de la primera fase que falle):

1. Parámetros que el KPI no reconoce → `PARAMETRO_DESCONOCIDO` / `FILTRO_NO_SOPORTADO`.
2. Formato y valores → `PARAMETRO_INVALIDO` (un error por parámetro, con `campo`).
3. Coherencia del periodo → `RANGO_FECHAS_INVALIDO`.

Un parámetro enviado dos veces o en forma de arreglo (`usuario[]=1`) es inválido.

## 7. Formato de respuestas

### 7.1 Sobre estándar

**Todas** las respuestas, de éxito o error, tienen exactamente estas claves, en este orden:

| Clave | Tipo | Éxito | Error |
|---|---|---|---|
| `exito` | boolean | `true` | `false` |
| `version_api` | string | `"v2"` | `"v2"` |
| `sistema` | objeto | `{clave, nombre, entorno}` | igual |
| `kpi` | objeto \| null | `{clave, nombre, unidad}` en `/kpis/{clave}`; `null` en `/sistema` y `/kpis` | `null` |
| `periodo` | objeto \| null | `{fecha_inicio, fecha_fin, agrupacion, zona_horaria}` en `/kpis/{clave}` | `null` |
| `filtros` | objeto | Filtros generales aplicados, normalizados: `{"usuario": ["6","9"]}`; `{}` si ninguno | `{}` |
| `datos` | arreglo \| null | **Siempre un arreglo de objetos** (puede ser `[]`) | `null` |
| `metadatos` | objeto | `{total_registros, paginacion, generado_en, id_peticion, version_estandar}` | igual, `total_registros: 0` |
| `errores` | arreglo | `[]` | `[{codigo, mensaje, campo}]`, al menos uno |

`periodo` devuelve el periodo **efectivo** (con defaults aplicados), para que el
sistema central sepa exactamente qué se calculó.

`metadatos.paginacion` es `null` si el KPI no es paginable; si lo es:
`{"pagina": 2, "por_pagina": 50, "total_paginas": 7}`, y `total_registros` es el total sin paginar.

### 7.2 Forma de `datos`

- Cada elemento es un **renglón** (objeto plano). Nunca escalares sueltos ni objetos anidados.
- Con `agrupacion` ≠ `total`, cada renglón **empieza** con:
  `periodo` (etiqueta), `periodo_inicio`, `periodo_fin` (fechas `YYYY-MM-DD`), seguidos de los campos del KPI.
- Etiquetas de periodo: `dia` `2026-09-30` · `semana` `2026-W39` (ISO 8601) ·
  `mes` `2026-09` · `trimestre` `2026-Q3` · `anio` `2026`.
- **Series sin huecos**: hay un renglón por cada periodo del rango, aunque no haya
  datos (con `0` en conteos y `null` en promedios/porcentajes). Las cubetas de los
  extremos se recortan al rango pedido (`periodo_inicio` puede ser `2026-01-10`).
- Con `agrupacion=total`: un renglón con el valor de todo el periodo (KPIs de
  valor), o un renglón por elemento (KPIs de tabla/ranking).
- Los campos de cada renglón son **exactamente** los declarados en `GET /kpis` → `campos`, en ese orden.

### 7.3 Fechas, horas y números

| Dato | Formato |
|---|---|
| Fecha | `YYYY-MM-DD`, en la zona horaria del sistema |
| Fecha-hora | ISO 8601 en **UTC** con `Z`: `2026-10-02T20:07:13Z` |
| Conteos | entero JSON |
| Decimales | número JSON (nunca string). Los enteros conservan `.0` si el campo es decimal |
| Porcentajes | número 0–100 con 1 decimal, prefijo `porcentaje_` |
| Dinero | número con 2 decimales + campo `moneda` (ISO 4217, `MXN`) en el mismo renglón |
| No calculable | `null` (p. ej. división entre cero). **Nunca** un `0` inventado |

### 7.4 Convenciones de nombres

- Claves JSON y parámetros: `snake_case`, español, sin acentos ni ñ (`anio`, `numero`).
- Claves de KPI: `kebab-case`, sustantivo que describe la medida (`cotizaciones-creadas`, `piezas-rechazadas`).
- Identificadores: sufijo `_id`. Toda referencia a una entidad trae `<entidad>_id` **y**
  `<entidad>_nombre`, para que el central no necesite catálogos auxiliares.
- Prefijos: `total_` (conteos), `promedio_`/`_promedio` (promedios), `porcentaje_`, `fecha_`, `es_`/`tiene_` (booleanos).
- Reservados en renglones: `periodo`, `periodo_inicio`, `periodo_fin`.

### 7.5 Respuestas vacías

Si no hay datos que cumplan la consulta, la respuesta es **`200`** con
`datos: []` (o la serie completa en ceros), **nunca 404**. Una página fuera de
rango también es `200` con `datos: []`. El 404 se reserva para rutas o KPIs inexistentes.

## 8. Códigos HTTP

| Código | Cuándo |
|---|---|
| `200` | Éxito (incluye resultados vacíos) |
| `401` | Sin token, token inválido o revocado |
| `403` | Token válido sin la ability `kpis:read` |
| `404` | Ruta o KPI inexistente |
| `405` | Cualquier método distinto de `GET`/`HEAD` |
| `422` | Parámetros desconocidos, inválidos o rango de fechas inválido |
| `429` | Límite de peticiones excedido (60/min por token) |
| `500` | Error no controlado del sistema origen |
| `503` | Sistema en mantenimiento / no disponible |

No se usan `201`, `204`, `400` ni redirecciones `3xx`.

## 9. Manejo de errores

Cada error es `{"codigo": "...", "mensaje": "...", "campo": "..."|null}`.
`codigo` es estable y es lo que el consumidor debe evaluar; `mensaje` es para
humanos y puede cambiar.

| `codigo` | HTTP | `campo` |
|---|---|---|
| `NO_AUTENTICADO` | 401 | null |
| `SIN_PERMISO` | 403 | null |
| `NO_ENCONTRADO` | 404 | null |
| `KPI_NO_ENCONTRADO` | 404 | `clave` |
| `METODO_NO_PERMITIDO` | 405 | null |
| `PARAMETRO_DESCONOCIDO` | 422 | el parámetro |
| `FILTRO_NO_SOPORTADO` | 422 | el filtro |
| `PARAMETRO_INVALIDO` | 422 | el parámetro |
| `RANGO_FECHAS_INVALIDO` | 422 | `fecha_inicio`, `fecha_fin` o `agrupacion` |
| `SOLICITUD_INVALIDA` | otro 4xx | null |
| `LIMITE_PETICIONES` | 429 | null |
| `ERROR_INTERNO` | 500 | null |
| `SERVICIO_NO_DISPONIBLE` | 503 | null |

- Los errores de autenticación y autorización usan el mismo sobre que cualquier
  otro error y **nunca** redirigen a una pantalla de login, aunque el cliente no envíe `Accept`.
- Un `500` **NO DEBE** exponer mensajes de excepción, SQL ni trazas. El detalle
  queda en el log del sistema origen con el `id_peticion`, que el central reporta para correlacionar.
- Reintentos del consumidor: solo ante `429` (esperar `Retry-After`), `500` y `503`
  (backoff exponencial). Los `4xx` restantes no se reintentan.

## 10. Seguridad y permisos

1. **Solo lectura por construcción**: solo se registran rutas `GET`; cualquier otro método responde 405.
2. **Ability única**: el token del central tiene exclusivamente `kpis:read`. No existen abilities de escritura para esta API.
3. **El rol del usuario no da acceso**: el acceso depende solo de la ability del
   token. La cuenta de servicio no debe poder iniciar sesión en la web (contraseña aleatoria, no compartida).
4. **Los KPIs no escriben**: `calcular()` solo consulta. Prohibido crear, actualizar,
   borrar, encolar trabajos, enviar correos o invocar servicios que modifiquen el sistema origen.
5. **Datos eliminados**: lo que está en papelera / borrado lógico **no** se cuenta.
6. **Datos mínimos**: los KPIs devuelven agregados. No se exponen datos personales
   ni sensibles (correos, teléfonos, costos internos) salvo que el KPI lo requiera y esté aprobado.
7. **Límite de tasa**: 60 peticiones/minuto **por token**.
8. **HTTPS** obligatorio en producción. Sin CORS: es integración servidor-a-servidor.
9. **Recomendado**: conexión de base de datos de solo lectura (usuario de BD con
   permisos `SELECT`) para la API, como segunda barrera.

## 11. Ejemplos de peticiones

```http
GET /api/v2/sistema HTTP/1.1
Host: cotizaciones.empresa.com
Authorization: Bearer 7|kQ2p...
Accept: application/json
```

```bash
# Catálogo de KPIs
curl -H "Authorization: Bearer $TOKEN" https://cotizaciones.empresa.com/api/v2/kpis

# Serie mensual del tercer trimestre, dos vendedores, con id de correlación
curl -H "Authorization: Bearer $TOKEN" -H "X-Request-Id: central-7f3a" \
  "https://cotizaciones.empresa.com/api/v2/kpis/cotizaciones-creadas?fecha_inicio=2026-07-01&fecha_fin=2026-09-30&agrupacion=mes&usuario=6,9"

# KPI de tabla paginado
curl -H "Authorization: Bearer $TOKEN" \
  "https://cotizaciones.empresa.com/api/v2/kpis/cotizaciones-por-vendedor?fecha_inicio=2026-01-01&pagina=2&por_pagina=20"

# Parámetro propio del KPI
curl -H "Authorization: Bearer $TOKEN" \
  "https://cotizaciones.empresa.com/api/v2/kpis/top-clientes?fecha_inicio=2026-01-01&limite=10"
```

## 12. Ejemplos de respuestas

**200 — serie** (`cotizaciones-creadas`, jul–sep 2026):

```json
{
  "exito": true,
  "version_api": "v2",
  "sistema": { "clave": "cotizaciones", "nombre": "Sistema de Cotizaciones", "entorno": "production" },
  "kpi": { "clave": "cotizaciones-creadas", "nombre": "Cotizaciones creadas", "unidad": "cotizaciones" },
  "periodo": { "fecha_inicio": "2026-07-01", "fecha_fin": "2026-09-30", "agrupacion": "mes", "zona_horaria": "America/Mexico_City" },
  "filtros": { "usuario": ["6", "9"] },
  "datos": [
    { "periodo": "2026-07", "periodo_inicio": "2026-07-01", "periodo_fin": "2026-07-31", "total": 41 },
    { "periodo": "2026-08", "periodo_inicio": "2026-08-01", "periodo_fin": "2026-08-31", "total": 0 },
    { "periodo": "2026-09", "periodo_inicio": "2026-09-01", "periodo_fin": "2026-09-30", "total": 37 }
  ],
  "metadatos": {
    "total_registros": 3,
    "paginacion": null,
    "generado_en": "2026-10-02T20:07:13Z",
    "id_peticion": "central-7f3a",
    "version_estandar": "1.0"
  },
  "errores": []
}
```

**200 — tabla paginada** (`cotizaciones-por-vendedor`), solo lo que cambia:

```json
{
  "periodo": { "fecha_inicio": "2026-01-01", "fecha_fin": "2026-10-02", "agrupacion": "total", "zona_horaria": "America/Mexico_City" },
  "datos": [
    { "usuario_id": 9, "usuario_nombre": "Demian Madrigal", "total_cotizaciones": 58, "completadas": 51, "porcentaje_completadas": 87.9 }
  ],
  "metadatos": { "total_registros": 23, "paginacion": { "pagina": 2, "por_pagina": 20, "total_paginas": 2 }, "...": "..." }
}
```

**200 — catálogo** (`GET /kpis`), un elemento de `datos`:

```json
{
  "clave": "top-clientes",
  "nombre": "Clientes con más cotizaciones",
  "descripcion": "Ranking de clientes por número de cotizaciones creadas en el periodo.",
  "unidad": "cotizaciones",
  "endpoint": "/api/v2/kpis/top-clientes",
  "agrupaciones": ["total"],
  "agrupacion_default": "total",
  "filtros": [ { "nombre": "usuario", "descripcion": "ID del vendedor que creó la cotización (users.id)." } ],
  "parametros": [ { "nombre": "limite", "tipo": "integer", "requerido": false, "default": 5, "descripcion": "Número de clientes del ranking (1 a 100)." } ],
  "campos": [
    { "nombre": "posicion", "tipo": "integer", "descripcion": "Lugar en el ranking, empezando en 1." },
    { "nombre": "cliente", "tipo": "string", "descripcion": "Nombre del cliente." },
    { "nombre": "total_cotizaciones", "tipo": "integer", "descripcion": "Cotizaciones creadas en el periodo." }
  ],
  "paginable": false
}
```

**422 — parámetro desconocido** (typo `fechainicio`):

```json
{
  "exito": false,
  "version_api": "v2",
  "sistema": { "clave": "cotizaciones", "nombre": "Sistema de Cotizaciones", "entorno": "production" },
  "kpi": null,
  "periodo": null,
  "filtros": {},
  "datos": null,
  "metadatos": { "total_registros": 0, "paginacion": null, "generado_en": "2026-10-02T20:07:13Z", "id_peticion": "ec2ce0f7-caed-4a02-ab91-f04944173c43", "version_estandar": "1.0" },
  "errores": [
    { "codigo": "PARAMETRO_DESCONOCIDO", "mensaje": "El parámetro fechainicio no existe para el KPI top-clientes.", "campo": "fechainicio" }
  ]
}
```

**401 / 403 / 405 / 429** — mismo sobre; cambia `errores`:

```json
"errores": [ { "codigo": "NO_AUTENTICADO", "mensaje": "Token ausente, inválido o revocado. Envíe el encabezado Authorization: Bearer <token>.", "campo": null } ]
"errores": [ { "codigo": "SIN_PERMISO", "mensaje": "El token no tiene la ability requerida (kpis:read).", "campo": null } ]
"errores": [ { "codigo": "METODO_NO_PERMITIDO", "mensaje": "La API es de solo lectura: únicamente se permite GET.", "campo": null } ]
"errores": [ { "codigo": "LIMITE_PETICIONES", "mensaje": "Se excedió el límite de peticiones. Reintente después de Retry-After segundos.", "campo": null } ]
```

## 13. Reglas para agregar nuevos KPIs

Agregar un KPI es un cambio **compatible**: se hace en la versión vigente, sin crear versión nueva.

1. Crear una clase que extienda `App\KpiApi\AbstractKpi` en `app/Kpis/`.
2. Declarar: `clave`, `nombre`, `descripcion`, `unidad`, `agrupaciones`,
   `filtros` (solo generales, con la descripción del identificador), `parametros`
   propios, `campos` (todos, con tipo y descripción) y `paginable`.
3. Implementar `calcular()` solo con consultas de lectura. Para series, usar
   `agruparPorPeriodo()` (garantiza series sin huecos y el formato de `periodo`).
4. Registrar la clase en `config/kpi-api.php` → `versiones.<vigente>`.
5. Reutilizar las reglas de negocio existentes del sistema (calculadoras,
   servicios) en lugar de reescribirlas, para que el KPI coincida con lo que el sistema muestra en pantalla.
6. Agregar pruebas con valores concretos. La prueba de conformidad valida el contrato automáticamente.
7. Documentar el KPI en `docs/kpi-api/sistemas/<sistema>.md` y avisar al equipo del sistema central.

**Cambios compatibles** (en la misma versión): nuevo KPI; nuevo campo al **final**
de los renglones; nuevo filtro general soportado; nuevo parámetro propio
**opcional**; nueva agrupación soportada; corrección de un bug de cálculo
(se avisa al central).

**Cambios incompatibles** (requieren versión nueva, §14): renombrar o quitar un
KPI, campo, filtro o parámetro; cambiar el tipo, unidad o significado de un campo;
volver obligatorio un parámetro; cambiar el default de periodo o agrupación de un KPI.

## 14. Reglas para agregar nuevas versiones de API

1. Solo se crea `v{n+1}` ante un cambio incompatible (§13) en el **sistema** o
   cuando se adopta una nueva versión mayor del **estándar**.
2. En `config/kpi-api.php` se agrega la clave `v{n+1}` con su lista de KPIs. Los
   KPIs sin cambios se reutilizan (misma clase); los que cambian se crean como clase
   nueva (p. ej. `app/Kpis/V3/TopClientes.php`), sin modificar la anterior.
3. La versión anterior se mantiene **al menos 6 meses** después de que el sistema
   central migre, y se anuncia su retiro con fecha.
4. Una versión retirada se quita de `config/kpi-api.php`; sus rutas desaparecen
   y responden 404.
5. `GET /sistema` → `versiones_disponibles` siempre lista las versiones vigentes.

---

## Anexo A — Qué es común y qué varía

| Categoría | Elementos | Dónde vive |
|---|---|---|
| **Idéntico en todos los sistemas** (no se toca) | Esquema Bearer + Sanctum; ability `kpis:read`; solo `GET`; los 3 endpoints; nombres y reglas de parámetros generales; filtros generales; agrupaciones y etiquetas de periodo; sobre JSON; formatos de fecha/número; códigos HTTP y de error; headers; validación estricta; límites (60/min, 1 830 días, 400 puntos, 500 por página); prueba de conformidad | `app/KpiApi/`, `tests/Feature/KpiApi/ConformidadEstandarTest.php`, `app/Console/Commands/IssueApiToken.php` |
| **Parametrizado** (mismo mecanismo, valor por sistema) | Clave y nombre del sistema; versiones publicadas; host de la Base URL; zona horaria (`APP_TIMEZONE`); cuenta de servicio y token | `config/kpi-api.php`, `.env` |
| **Varía por sistema** | Catálogo de KPIs; qué filtros generales tienen sentido y qué identificador usan; tablas/modelos fuente; reglas de negocio compartidas (calendario hábil, estados, etc.); clase base de sus KPIs | `app/Kpis/` |
| **Varía por KPI** | Clave, nombre, unidad, descripción; agrupaciones soportadas y default; filtros soportados; parámetros propios; campos de los renglones; si es paginable; la consulta y el cálculo | Cada clase de `app/Kpis/` |
