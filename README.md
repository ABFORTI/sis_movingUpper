## Acerca de movingUpper

Sistema web planeado y desarrollado para llevar un mejor control del inventario de Upper Logistics Qro, donde se puede hacer lo siguiente:

- Registro del SKU del tipo de producto y el numero de serie de cada producto correspondiente a esa SKU
- Registro unicamente de los SKUs
- Guardar registros dentro del sistema
- Generar e imprimir una etiqueta zebra del registro realizado
-  Exportar una o todas las tarimas en un excel


## Implicaciones tecnicas

El sistema esta desarrollado con php, laravel y blade.
Base de datos mysql relacional

## Acciones de seguridad

El registro queda acargo unicamente del administrador, cada usuario debera iniciar sesion, ademas de que se aplicaron principios de seguridad como:
- Principio de responsabilidad unica
- Principio de abierto/cerrado
- Principio de sustitucion de Liskov
- Principio de inversion de dependencias
- Cofidencialidad
- Integridad
- Disponibilidad

## Cómo se aplica en el código

| Principio | Dónde |
|---|---|
| Responsabilidad única | Controladores delgados; reglas en `app/Services` (`EscaneoService`, `TarimaService`), validación en `app/Http/Requests`, SKU activo en `app/Support/SkuActivo` |
| Abierto/cerrado | Formatos de etiqueta en `app/Services/Etiquetas`: un formato nuevo es otra clase que implementa `GeneradorEtiqueta` y se registra en `AppServiceProvider` |
| Sustitución de Liskov | `EtiquetaQrZpl` y `EtiquetaTablaZpl` son intercambiables detrás de `GeneradorEtiqueta` |
| Inversión de dependencias | Los controladores dependen de `ExportadorTarimas` (interfaz), no de PhpSpreadsheet |
| Confidencialidad | Login obligatorio, contraseñas con hash, bloqueo tras 5 intentos, regeneración de sesión, usuarios inactivos expulsados, solo el admin da de alta usuarios, el QR expone un UUID con URL firmada |
| Integridad | Transacciones, índices únicos en BD (SKU por tarima, N/S en todo el sistema), CSRF, escapado de Blade, bitácora `user_id` / `deleted_by` |
| Disponibilidad | Sin dependencias de CDN: códigos de barras, QR y Excel se generan en el servidor; borrado lógico para operadores |

## Mudanzas

Todo se registra dentro de una **mudanza** (folio `MUD-0001`, cliente, almacén origen y destino). Solo puede haber una en curso a la vez; la base de datos lo garantiza. El administrador la crea en borrador y la activa desde **Mudanzas**. La mudanza en curso se ve siempre en la barra superior.

| Estado | Crear tarimas y escanear | Imprimir / guardar / anular tarimas |
|---|:-:|:-:|
| Borrador | No | No |
| Activa | Sí | Sí |
| Suspendida (con motivo) | No | No |
| En cierre | No | Sí |
| Cerrada / Cancelada | No | No |

- Las tarimas se numeran **por mudanza** (`T-001`, `T-002`…), con una sola secuencia para todos los usuarios. Un número no se reutiliza aunque la tarima se anule.
- Para cerrar no deben quedar tarimas en captura; no se puede cancelar una mudanza con tarimas guardadas (se cierra). Reabrir y suspender piden motivo. Todo queda en la bitácora.
- Las tarimas registradas antes de este control quedaron en `MUD-0001` con su mismo número, así que las etiquetas ya impresas siguen siendo válidas (el código de barras sigue siendo `TAR-{id}`; junto a él ahora se lee `MUD-0001 · T-004`). El administrador debe completar cliente y almacenes de `MUD-0001`.
- Historial y Excel muestran una mudanza a la vez (la en curso por defecto); el archivo lleva el folio: `MUD-0001_Todas_Tarimas_2026-10-09.xlsx`.

## Partidas y correcciones

Al **guardar** una tarima, cada pieza se vuelve una **partida** con un consecutivo fijo por mudanza (el número que el cliente ve en el Excel). Ese número **nunca cambia**, aunque la pieza se mueva o se retire.

- **Corregir** (admin y auditor, desde el Historial), siempre con motivo: **Mover** piezas a otro embarque (guardado o en captura), **Retirar** piezas, **Eliminar N/S** registradas por error, **Agregar N/S** a un modelo del embarque (recibe el siguiente consecutivo) y **Editar N/S** (SKU o número de serie; conserva su consecutivo). En el handheld se escanea la N/S para marcar la pieza.
- Al agregar o editar una N/S aparece **Reimprimir**: abre la impresión de siempre con solo esa etiqueta (`?piezas=<id>`); las demás no se reimprimen.
- Si todas las piezas de un embarque se movieron, el Historial, Corregir y su QR dicen «Las piezas se movieron al embarque E-00X» en lugar de mostrarlo vacío.
- La etiqueta, el historial y el QR muestran el contenido físico real; las tarimas tocadas quedan con **Reimprimir** hasta que se imprime su etiqueta nueva.
- Retirar no borra: la partida queda como retirada (fuera del Excel) y su N/S se libera; si la pieza aparece de nuevo se escanea y recibe otro consecutivo.
- Cada movimiento queda en `movimientos_partida` (no admite cambios ni borrados; `detalle` guarda el antes → después de una edición) y en la bitácora.
- El Excel del historial sale de las partidas, ordenado por consecutivo, e incluye piezas movidas a una tarima que aún no se guarda.

## Reporte Diario (antes «Entregas al cliente»)

**Reporte Diario** (admin y auditor) se consulta y se exporta **por día**: se elige la fecha (hoy por omisión) y la pantalla y el Excel incluyen solo las piezas guardadas cuya **fecha de registro** (cuando se escaneó su N/S) es ese día. Las entregas anteriores, de la mudanza completa, se conservan y aparecen el día en que se generaron. Cada reporte es un Excel **versionado** (v1, v2… por mudanza; si se genera de nuevo el mismo día se compara contra el anterior de ese día) que:

- Se guarda tal cual en `storage/app/private/documentos` con su **SHA-256**; al descargarla se vuelve a comprobar y, si el archivo cambió, el sistema no lo entrega.
- Copia cada pieza en `entrega_partidas`, así se sabe exactamente qué recibió el cliente en cada versión aunque después se corrijan tarimas.
- Trae las columnas de siempre más **Novedad** (nueva / cambió de tarima respecto a la versión anterior) e **Incidencias abiertas**, una hoja **Control** (folio, versión, totales) y una hoja **Salieron** (piezas retiradas o de tarimas anuladas).
- Antes de generarlo avisa de embarques en captura con piezas de ese día (no se incluyen), etiquetas por reimprimir e incidencias abiertas.

El botón "Excel de trabajo" del Historial sigue existiendo para uso interno; lo que se envía al cliente es el Reporte Diario.

## Incidencias

Cualquier usuario registra hallazgos (N/S duplicada, producto sin identificación, no está en SAP, daño…) sin detener el escaneo: piezas afectadas por **N/S escaneada o consecutivo**, y fotos tomadas con el handheld. Cada una recibe folio `INC-0001` por mudanza.

- Admin y auditor asignan responsable, cambian el estado (abierta → en investigación → pendiente del cliente), resuelven o descartan. Reabrir pide motivo.
- Una resolución que es **decisión del cliente** (corregido en SAP, aceptado con diferencia, excluido del traslado) **exige evidencia adjunta**.
- Por defecto una incidencia **bloquea el traslado** de sus piezas mientras siga abierta (se aplicará al cargar el tráiler).
- La mudanza no se puede cerrar con incidencias abiertas. El historial y las evidencias no se modifican ni se borran.

## Autorizaciones del cliente (retiradas)

Desde el 2026-10-12 la carga **no** pide autorización del cliente: se retiraron las pantallas, rutas, permisos y validaciones del módulo. Las tablas `autorizaciones` y `autorizacion_partidas` y las cargas que ya tenían una autorización ligada se conservan como **histórico** (no se borró nada) y los documentos adjuntos a ellas siguen visibles solo para supervisión. Detalle y razones en `docs/flujo-carga-descarga.md`.

## Embarques: carga y recepción por escaneo

Un embarque es la entrega física de inventario de un viaje: cada pieza tiene **una sola fila** con su carga (quién, cuándo) y su recepción (quién, cuándo). No hay dos registros para la misma operación.

- **Crear** (admin o auditor): placas, remolque y chofer. Folio `EMB-0001` por mudanza.
- **Cargar** (cualquier usuario, en el andén): se escanea la etiqueta de la tarima (QR, código de barras `TAR-…` o `T-004`), la N/S de una pieza o un SKU que esté en una sola tarima. Se rechaza lo que no se puede identificar sin ambigüedad o no está listo: tarima en captura, anulada o con etiqueta por reimprimir; pieza que ya va en otro embarque o con una incidencia que bloquea su traslado. El estado del SKU en la captura (activo o cerrado) no importa.
- **Escaneo continuo en el handheld** (`public/js/anden.js`): cada lectura se envía sin recargar y muestra *Cargada / Recibida*, *Ya estaba registrada*, *No viene en este embarque*, *Rechazada* o *Pendiente de envío*, con icono, texto, sonido y vibración. Sin red, las lecturas quedan pendientes en el equipo y se reenvían solas; nunca se muestran como guardadas sin la respuesta del servidor. Repetir un escaneo no duplica nada.
- **Bajar** una tarima cargada por error, con motivo, mientras el tráiler no sale.
- **Salida** (admin o auditor): pide el sello y vuelve a revisar cada pieza (pudo retirarse o abrirse una incidencia después de cargar).
- **Recibir** (cualquier usuario, en el destino): se escanea lo que llega y queda ligado a su carga. Lo que **no viene** en el embarque no se asocia a nada: se avisa, queda en la bitácora y con un toque se abre una incidencia. Al **cerrar la recepción**, lo cargado y no escaneado abre una incidencia de **faltante**.
- **Manifiesto** (Excel del tráiler, cualquier usuario): las piezas cargadas en él. (El embarque de la captura ya no tiene botón de Manifiesto.) **Reporte final** (admin o auditor): toda la mudanza, con la situación de cada pieza (recibida, en tránsito, faltante, cargada sin salir, sin cargar).
- La mudanza no se puede cerrar con embarques sin recibir.

## Editar números de serie antes de guardar

En la tarjeta de cada embarque en captura cada modelo (SKU) muestra sus últimas 3 lecturas; el resto queda plegado en **Editar números de serie**. Al abrirlo se puede **marcar o desmarcar dañado** (siempre, hasta guardar) y, solo mientras no se haya impreso ninguna etiqueta del embarque, **agregar**, **editar** (SKU o N/S, con las mismas validaciones del escaneo) y **eliminar** N/S. El escaneo continuo con el gatillo no cambia.

Para marcar daños se eligen las N/S (o se escanea la dañada), con descripción opcional. Marcar no detiene el escaneo ni obliga a reimprimir. Antes de guardar, el botón Guardar muestra las N/S marcadas; al guardar, **cada N/S marcada abre su incidencia** (tipo «Daño o anomalía», ligada a su pieza y a quien la reportó, sin bloquear el traslado). Una N/S de otra tarima no se puede marcar. Volver a marcar no duplica y una descripción nueva se agrega a la anterior.

## Roles

Todos los usuarios ven todas las tarimas de la mudanza (lectura); cada operador trabaja solo las que creó.

- **Administrador**: todo lo del operador, alta/edición de usuarios, impresoras y manuales, administración de mudanzas y **anular** cualquier tarima (también guardadas) indicando el motivo. Siempre debe quedar al menos un administrador activo.
- **Auditor**: Escaneo, Historial (con Corregir), Incidencias, Tráileres, Inventarios y Reporte Diario de todos los usuarios; consulta los manuales publicados y crea y activa mudanzas. Un administrador que se cambia a auditor conserva sus datos y registros, pero pierde la administración (verificado en el servidor, no solo ocultando botones).
- **Operador**: escanear, crear tarimas, imprimir, exportar, vaciar tarimas con **borrado lógico** (queda en papelera con quién y cuándo, y las N/S se liberan para volver a escanearse) y anular sus tarimas vacías.

**Anular** un embarque **en captura** lo elimina (nunca recibió consecutivos): la bitácora guarda quién, cuándo, el motivo y sus N/S, y su número solo se vuelve a asignar si era el último de la mudanza; si ya hay embarques posteriores queda el hueco (nunca se renumera ni se duplica). Un embarque **guardado** no se borra: al anularlo conserva su número, su contenido y sus consecutivos, sale de los Excel, y su QR muestra "TARIMA ANULADA". Las anulaciones, los vaciados y cada Excel descargado quedan en la tabla `bitacora`, que no admite cambios ni borrados.

## Instalación

```bash
composer install
cp .env.example .env        # configurar DB_*, APP_URL
php artisan key:generate
php artisan migrate --seed  # roles, destinos PROD-A..D y usuarios iniciales
```

Usuarios iniciales (cambiar las contraseñas al entrar): `desarrollo@ab-forti.com` (admin), `auditor@upperlogistics.mx` (auditor) y `prueba@upperlogistics.mx` (operador).

Pruebas: `php artisan test`.

## Despliegue en Hostinger

1. El dominio debe apuntar a la carpeta `public/`.
2. En `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tu-dominio` (con https).
3. **No cambies `APP_KEY` ni `APP_URL` después de imprimir etiquetas**: la firma de la URL del QR depende de ambos; si cambian, los QR ya impresos dejan de abrir.
4. MySQL/MariaDB con soporte de columnas generadas (MySQL 5.7+ / MariaDB 10.2+; Hostinger ya lo cumple).
5. `php artisan migrate --force --seed` y `php artisan optimize`.
6. Manuales: PHP debe aceptar subidas de al menos 20 MB (`upload_max_filesize` y `post_max_size` ≥ 20M). La carpeta `storage/app/private/manuales` debe poder escribirse y **no** debe quedar dentro de `public/`.
7. Entregas y evidencias: `storage/app/private/documentos` debe poder escribirse, fuera de `public/`, e incluirse en el respaldo del servidor (son la prueba de lo entregado). Para subir varias fotos a la vez, `post_max_size` ≥ 100M.

## Roles y manuales

| Funcionalidad | Administrador | Auditor | Operador |
|---|:-:|:-:|:-:|
| Crear y trabajar tarimas | Sí | Sí | Sí |
| Ver tarimas e historial de otros usuarios | Sí | Sí | No (solo las suyas) |
| Anular tarimas con contenido o guardadas | Sí | No | No |
| Anular tarimas vacías en captura | Sí | Sí | Sí (propias) |
| Gestionar usuarios | Sí | No | No |
| Gestionar manuales | Sí | No | No |
| Ver manuales | Todos | Los de "Operador y Auditor" y "Solo Auditor" | Los de "Operador y Auditor" y "Solo Operador" |

La visibilidad se valida en el servidor (`TarimaPolicy`, `ManualPolicy` y los scopes `visiblesPara`), no solo en las vistas.

**Archivos de manuales**: solo PDF, **máximo 20 MB** (`Manual::TAMANO_MAXIMO_KB`). Se valida la extensión, el MIME detectado por el contenido y la firma `%PDF-`. Se guardan en el disco privado `manuales` (`storage/app/private/manuales`) con un nombre generado (UUID), y solo se entregan por `/manuales/{id}/ver` o `/descargar` después de comprobar el rol.

## Identidad visual

- Colores: `#1C156D` principal (navegación, botones, foco), `#F16522` acento (una llamada a la acción por pantalla, sección activa, SKU padre activo, avisos) y `#6DBC45` éxito (confirmaciones e indicadores). Las reglas y los tokens están al inicio de `public/css/wms.css`.
- Recursos: `public/images/upper_logo1.png` (empresa), `icon.png` (sistema) y `Euroquip4.png` (cliente, solo en etiquetas). Las etiquetas Zebra usan esos originales: **no los edites**.
- La interfaz usa recortes de esos logos en `public/images/brand/` (sin margen sobrante y con la proporción intacta) a través del componente `<x-brand>`. `favicon.ico`, `favicon-32.png` y `apple-touch-icon.png` salen de `icon.png`.

## API de KPIs

API REST de solo lectura para el sistema central de KPIs, bajo `/api/v3/` (`/sistema`, `/kpis`, `/kpis/{clave}`): N/S escaneados, tarimas registradas, SKUs registrados, etiquetas impresas y productividad por usuario, por día, semana, mes, trimestre o año.

- Acceso solo con token Bearer con la ability `kpis:read`: `php artisan api:token kpi-central@upperlogistics.mx --crear`. La sesión web no da acceso.
- Cada confirmación de impresión queda en la tabla `impresiones` (etiquetas y reimpresiones).
- Contrato común: [docs/kpi-api/ESTANDAR.md](docs/kpi-api/ESTANDAR.md). Catálogo, fórmulas y restricciones: **[docs/kpi-api/sistemas/movingupper.md](docs/kpi-api/sistemas/movingupper.md)**.

## Impresión Zebra ZQ620 (etiqueta horizontal 75 × 50 mm)

El servidor genera el ZPL (`/tarimas/{uuid}/etiqueta.zpl`), pero como está en Hostinger **no puede llegar a la impresora**. El navegador del dispositivo lo entrega a la ZQ620:

| Dispositivo | Cómo imprime | Instalar |
|---|---|---|
| Android / handheld | Zebra Browser Print (app de Zebra) → Bluetooth | App Browser Print |
| Computadora Windows/macOS | Chrome o Edge → Bluetooth LE (Web Bluetooth) | Nada |
| Computadora por USB | Zebra Browser Print → USB | Browser Print |
| iPhone / iPad | No compatible desde el navegador | — |

El diseño de la etiqueta (`app/Services/Etiquetas`) produce a la vez el ZPL y la vista previa SVG a escala real. Medidas en `config/etiquetas.php` (`ZEBRA_*` en `.env`).

Cada etiqueta lleva su propia configuración de papel y origen (`^MTD ^MNY ^PW ^LL ^LH0,0 ^LT ^LS0`), así no depende de lo que la impresora tenga guardado. **Prueba de impresión** (`/impresion/prueba`): elige la impresora, imprime una etiqueta con marco en los cuatro bordes y regla, y permite **calibrar el papel** de la ZQ620. Si después de calibrar la etiqueta sigue un poco alta o baja, se ajusta con `ZEBRA_AJUSTE_SUPERIOR_MM`.

Arquitectura, compatibilidad verificada, instalación por dispositivo y lista de pruebas: **[docs/impresion-zebra-zq620.md](docs/impresion-zebra-zq620.md)**. Guía para el almacén (handheld, emparejamiento, carga, recepción, anomalías, fallas): **[docs/flujo-carga-descarga.md](docs/flujo-carga-descarga.md)**. Los errores técnicos de impresión quedan en `storage/logs/impresion-*.log`.

