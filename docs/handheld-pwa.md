# Handheld: app instalable (PWA) o APK

**Conclusión: no hace falta una APK.** Con el service worker y el manifiesto, la aplicación se instala en el handheld como un acceso directo que abre a pantalla completa, sin barra del navegador. Escáner e impresora funcionan igual que en Chrome, porque la PWA instalada **es** Chrome. Una APK solo se justifica en los casos de la §4.

> Convención: **[VERIFICADO]** comprobado en este repositorio o en la documentación citada. **[VERIFICAR EN EQUIPO]** depende del modelo de handheld y de su versión de Android/DataWedge; hay que probarlo en el equipo real antes de desplegar.

---

## 1. Cómo se instala

Requisitos (los cumple Hostinger con HTTPS):

| Pieza | Archivo |
|---|---|
| Manifiesto: nombre, íconos 192/512, `display: standalone`, orientación vertical, atajos Escaneo e Historial | `public/manifest.webmanifest` |
| Service worker | `public/sw.js` (se registra en `public/js/ui.js`) |
| Página sin conexión | `public/offline.html` |
| Tipo MIME del manifiesto y `sw.js` sin caché | `public/.htaccess` |

En el handheld: abrir la app en **Chrome** → menú ⋮ → **Instalar app** (o "Agregar a pantalla principal"). Queda el ícono de MovingUpper en el escritorio de Android. Con un MDM (p. ej. Zebra StageNow / SOTI) se puede dejar fijado en el escritorio de todos los equipos.

### Qué guarda el service worker y qué no

| Se guarda en el equipo | **Nunca** se guarda |
|---|---|
| CSS, JS, íconos, logos, fuentes (carga más rápida con wifi débil) | Páginas (escaneo, historial, tarimas) |
| `offline.html` | Escaneos, ZPL, Excel, cualquier POST |

Es a propósito: cada escaneo se valida en el servidor (N/S única en todo el sistema, tarima abierta, mudanza activa). **No hay captura sin conexión.** Guardar escaneos en el equipo para mandarlos después permitiría registrar la misma N/S en dos equipos sin darse cuenta, y en equipos compartidos dejaría datos de un usuario visibles para otro. Sin red, la app muestra "Sin conexión" en lugar de una pantalla vieja.

**Excepción controlada: carga y recepción en el andén** (`public/js/anden.js`). Ahí una lectura sin red **no se pierde**: queda «Pendiente de envío» en el equipo y se reenvía sola al volver la señal. Es seguro porque cargar o recibir la misma tarima dos veces no duplica nada (el servidor responde «ya estaba registrada»), y el servidor vuelve a validar todo al recibirla. La pantalla nunca dice «Cargada» o «Recibida» sin la respuesta del servidor. Ver `docs/flujo-carga-descarga.md`.

---

## 2. Escáner del handheld

**Funciona sin APK. [VERIFICADO en el diseño actual]** El escáner (DataWedge en Zebra; "Scanner" o "Keyboard wedge" en Honeywell, Urovo, Chainway, etc.) envía lo leído **como si se tecleara** en el campo que tiene el foco, seguido de **Enter**. La app ya está construida así: los campos de SKU y N/S tienen el foco y se envían con Enter.

Configuración en DataWedge (Zebra):

1. Crear un perfil, p. ej. `MovingUpper`.
2. **Aplicaciones asociadas**: agregar `com.android.chrome` **y** el paquete de la app instalada. Chrome crea para la PWA un paquete propio con nombre generado (`org.chromium.webapk.…`) que aparece en la lista de DataWedge después de instalarla. **[VERIFICAR EN EQUIPO]** Si no aparece, basta con el perfil de Chrome o con el perfil por defecto (*Profile0*), que aplica a cualquier app sin perfil.
3. **Barcode input**: habilitado, con las simbologías que use el cliente (Code 128, QR, DataMatrix…).
4. **Keystroke output**: habilitado, con **sufijo Enter** (*Basic data formatting → Send ENTER key*).
5. Intent output: deshabilitado (una página web no puede recibir Intents).

En Android 10 o posterior, el teclado desaparece un instante en cada lectura: es el método de entrada propio de DataWedge, y es normal.

**El gatillo funciona como Enter aunque DataWedge no mande Enter** (`public/js/escaneo.js`, sección 5). Si la lectura llega en ráfaga (menos de 40 ms entre caracteres, o toda de golpe) y luego hay silencio, el campo se envía solo; un sufijo **Tab** también cuenta como Enter. Si DataWedge sí manda Enter, se usa ese y no se duplica. Aplica a los campos de N/S, andén y al de SKU del WMS. Escribir a mano nunca se envía solo: es lento, y con el teclado en pantalla (⌨ activado, o el buscador de SKU) solo cuenta la ráfaga tecla por tecla, no el texto predictivo ni las sugerencias. Activar ⌨ ya no desactiva el envío automático del gatillo. En el SKU, el Enter del escáner envía lo leído aunque la lista de opciones esté abierta; Enter elige de la lista solo si se navegó con las flechas. Aun así, lo más limpio es activar **Send ENTER key** en el perfil de DataWedge: el envío es inmediato, sin la espera de 0.12 s.

### Mejoras hechas para el escáner

- **Sin teclado en pantalla.** En equipos táctiles, los campos de escaneo no abren el teclado virtual (`inputmode="none"`), que tapaba media pantalla. El botón ⌨ a la derecha del campo lo muestra para escribir a mano, y la preferencia se recuerda.
- **N/S sin recargar.** Cada N/S se envía en segundo plano. La pantalla actualiza el contador, agrega la N/S a la tarjeta de la tarima y deshabilita "Guardar" porque hay que reimprimir. Si el gatillo dispara más rápido que la red, los escaneos se encolan y se envían en orden. Un rechazo (por ejemplo, una N/S duplicada) no detiene los siguientes, pero **su aviso se queda en pantalla**.
- **Aviso por sonido y vibración:** un tono corto si se guardó, uno grave con doble vibración si se rechazó. Es distinto del pitido del escáner, que solo confirma que leyó un código, no que el sistema lo aceptó. **[VERIFICAR EN EQUIPO]** Chrome puede bloquear el sonido hasta el primer toque en pantalla. Lo visual (campo en rojo, mensaje junto al campo) aparece siempre.
- **El foco vuelve al campo** al tocar una zona vacía o al regresar a la app.
- **Distribución para handheld:** el campo de N/S y su resultado quedan justo debajo del selector de modo. La tarjeta del SKU activo va debajo, y las tarimas con muchas N/S muestran solo las últimas 8.

No se puede desde la web (requiere APK): leer la simbología del código, controlar el gatillo, cambiar la configuración del escáner desde la app o recibir la lectura por Intent.

---

## 3. Impresora Zebra por Bluetooth

**Funciona sin APK**, con los dos caminos que ya tiene `public/js/impresion.js` (ver `docs/impresion-zebra-zq620.md`):

| Camino | En la PWA instalada | Comentario |
|---|---|---|
| **Zebra Browser Print** (app oficial de Zebra) | Sí. La página le habla en `https://localhost:9101`; da igual si es pestaña o PWA. | **Recomendado en handheld.** Recuerda la impresora predeterminada: no pide elegirla en cada etiqueta. |
| **Web Bluetooth** (BLE, sin instalar nada) | Sí. Es la misma API de Chrome. | Pide elegir la impresora en un selector. **[VERIFICAR EN EQUIPO]** Que el permiso se recuerde entre páginas depende de `navigator.bluetooth.getDevices()` en la versión de Chrome del equipo. Si no, el selector aparece en cada etiqueta. |

**Bluetooth Classic (SPP), el que usa el emparejamiento normal de Android, no está disponible para ninguna página web**, esté instalada o no. Por eso existen Browser Print y Web Bluetooth. Una APK con el SDK Link-OS de Zebra sí lo usaría, e imprimiría sin selector y sin la app de Browser Print.

---

## 4. Cuándo sí convendría una APK

| Necesidad | PWA | APK |
|---|:-:|:-:|
| Instalar con ícono y abrir a pantalla completa | ✔ | ✔ |
| Escanear con el gatillo (teclado + Enter) | ✔ | ✔ |
| Imprimir en Zebra por Bluetooth | ✔ (Browser Print o BLE) | ✔ (SDK, sin apps extra) |
| Actualizaciones sin reinstalar | ✔ (al publicar en Hostinger) | ✘ (distribuir cada versión) |
| Capturar **sin conexión** y sincronizar después | ✘ (decisión de diseño, §1) | Posible, con manejo de conflictos de N/S |
| Recibir lecturas por Intent, simbología, control del gatillo | ✘ | ✔ |
| Imprimir por Bluetooth Classic sin Browser Print | ✘ | ✔ |
| Modo kiosco (bloquear el equipo a una sola app) | Parcial (lo hace el MDM con Chrome) | ✔ |
| iPhone / iPad | ✘ para imprimir | Otra app iOS aparte |

**Punto intermedio:** si TI exige distribuir "una APK" por MDM, una **TWA** (*Trusted Web Activity*, generada con Bubblewrap) empaqueta esta misma PWA como APK sin reescribir nada. Tiene exactamente las mismas capacidades de la PWA.

**Recomendación:** PWA + DataWedge en modo teclado + Zebra Browser Print. Se reconsidera una APK solo si la operación necesita capturar sin internet en el almacén.

---

## 5. Pruebas en el equipo real

| # | Prueba | Esperado |
|---|---|---|
| 1 | Chrome → ⋮ → Instalar app | Ícono MovingUpper en el escritorio; abre sin barra del navegador |
| 2 | Escanear SKU padre con el gatillo | Se activa sin abrir el teclado en pantalla |
| 3 | Escanear 10 N/S seguidas, rápido | Las 10 en la tarjeta de la tarima; contador en 10; sin recargas |
| 4 | Escanear una N/S repetida | Tono grave + vibración; mensaje rojo junto al campo con la tarima donde ya está |
| 5 | Tocar ⌨ y escribir una N/S a mano | Aparece el teclado; Enter la agrega |
| 6 | Apagar el wifi y escanear | Mensaje "Sin conexión: la N/S … NO se guardó" |
| 7 | Abrir la app sin wifi | Pantalla "Sin conexión" con "Reintentar" |
| 8 | Imprimir etiqueta desde la app instalada (Browser Print) | Imprime sin selector |
| 9 | Imprimir sin Browser Print (Web Bluetooth) | Selector la primera vez; anotar si lo vuelve a pedir en la siguiente etiqueta |
| 10 | Anular una tarima vacía | Diálogo con campo de motivo, legible en la pantalla del handheld |

## Fuentes

- [DataWedge — Getting Started (perfiles y asociación de apps)](https://techdocs.zebra.com/datawedge/14-3/guide/gettingstarted)
- [DataWedge — Keystroke Output](https://techdocs.zebra.com/datawedge/11-4/guide/output/keystroke)
- [Web Bluetooth — estado de implementación](https://github.com/WebBluetoothCG/web-bluetooth/blob/158ebcbda139497849a41d826d5191fa4d9e61e7/implementation-status.md)
- [Web Bluetooth — permisos persistentes en Chrome](https://lists.w3.org/Archives/Public/public-web-bluetooth/2020Jul/0000.html)
- [Chrome — `getDevices()` y `forget()`](https://chromestatus.com/release-notes/159)
- `docs/impresion-zebra-zq620.md` (Browser Print, Web Bluetooth y restricciones de la ZQ620)
