# Implementación de mejoras en la PWA logística

## Rol y objetivo
Actúa como desarrollador senior especializado en Laravel, arquitectura de software, logística, inventarios y UX/UI para PWA utilizadas en handhelds.

Analiza la estructura y la lógica existente antes de programar. Implementa únicamente los cambios solicitados, reutiliza el código existente, elimina código obsoleto relacionado con las funciones retiradas y aplica buenas prácticas. No alteres módulos, flujos ni funcionalidades ajenos al alcance.

## 1. Roles y permisos
Configura el rol **Auditor** con acceso a:
- Escaneo, Historial, Incidencias, Trailers, Inventarios y Entregas.
- Consulta de los manuales publicados por los administradores.
- Creación y activación de nuevas mudanzas.

Actualmente existen usuarios registrados como administradores. Al cambiar uno a Auditor, conserva sus datos, registros y relaciones existentes, pero aplica exclusivamente los permisos del nuevo rol. Debe perder el acceso a la administración de usuarios y a las demás funciones reservadas a administradores.

Implementa los permisos mediante el mecanismo de autorización existente, tanto en la interfaz como en el backend. No basta con ocultar botones.

## 2. Módulo de Escaneo

### 2.1 Embarques escaneados
Optimiza la sección para handhelds: muestra los modelos/SKUs activados sin desplegar permanentemente todos sus números de serie (N/S).

Sustituye el botón «Revisar N/S y marcar productos dañados» por **«Editar números de serie»**. Al desplegarlo, muestra los N/S del modelo y permite:
- Marcar un producto como dañado o quitarle la marca.
- Eliminar un N/S.
- Agregar un N/S al modelo.
- Editar un N/S, incluyendo su SKU o número de serie.

Las operaciones de eliminar, agregar y editar N/S solo estarán disponibles mientras no se hayan impreso las etiquetas del embarque. Respeta las validaciones y relaciones existentes.

### 2.2 Acciones del embarque
- **Vaciar:** conservar su funcionamiento actual.
- **Anular:** conservar la opción, pero hacer que elimine el embarque creado. El número de embarque solo podrá reutilizarse si corresponde al último número asignado; nunca renumeres embarques existentes ni generes duplicados.
- **Etiquetas:** conservar íntegramente su implementación y funcionamiento actual. No modificar el flujo de impresión, sus conexiones ni sus componentes funcionales.
- **Manifiesto:** eliminar el botón y el código exclusivamente asociado a esta funcionalidad, siempre que no tenga dependencias necesarias para otros módulos.

## 3. Módulo de Historial

En la sección **Corregir**, conserva las opciones existentes:
- Retirar seleccionadas.
- Mover seleccionadas.

Agrega las siguientes opciones:
- Eliminar N/S.
- Agregar N/S a este modelo.
- Editar N/S, permitiendo modificar tanto el SKU como el número de serie.

### Reglas de corrección
- Si todas las piezas de un embarque se trasladan a otro, no lo describas como vacío. Indica que sus piezas fueron trasladadas al embarque de destino, por ejemplo: «Las piezas se movieron al embarque E-000X».
- Al agregar o editar un N/S, muestra un botón **Reimprimir** que dirija al flujo de impresión existente y genere únicamente la etiqueta correspondiente al N/S agregado o actualizado, sin reimprimir las demás etiquetas.
- No alteres el funcionamiento general de la impresión de etiquetas.
- Mantén la trazabilidad de las correcciones y las relaciones entre embarques, modelos y números de serie.

Las correcciones deben reflejar fielmente el estado físico de la mercancía. Evita eliminar o modificar registros relacionados de manera que se pierda el historial necesario para conocer qué ocurrió.

## 4. Módulo de Entregas de inventario → Reporte Diario

Renombra el módulo **Entregas de inventario** a **Reporte Diario** y adapta su consulta y exportación para trabajar por día.

Requerimientos:
- Incorporar un filtro de fecha para seleccionar el día que se desea consultar.
- Mostrar las entregas o actividades correspondientes exclusivamente al día seleccionado, respetando los registros y la lógica de negocio existentes.
- Generar un archivo Excel con únicamente la información trabajada durante ese día.
- Aplicar el filtro tanto a los datos mostrados como a la exportación, utilizando el criterio de fecha correcto según el flujo actual.

No elimines históricos ni modifiques otros reportes o procesos de inventario que no estén relacionados.

## 5. UX/UI para handhelds
Mejora el diseño de las secciones modificadas priorizando:
- Interfaz compacta, clara y adaptable a pantallas pequeñas.
- Botones táctiles fáciles de identificar y utilizar.
- Menos desplazamiento y espacio desperdiciado.
- Escaneo continuo, conservando el comportamiento actual del lector y del gatillo físico.
- Confirmaciones claras para operaciones destructivas y mensajes de error útiles.
- Estados visuales consistentes y prevención de acciones inválidas.

Mantén la identidad visual y los componentes existentes. No rediseñes toda la aplicación.

## 6. Reglas de implementación
1. Antes de modificar código, identifica las rutas, controladores, modelos, vistas, servicios, permisos, migraciones y dependencias involucradas.
2. Reutiliza componentes, métodos y servicios existentes. No dupliques lógica.
3. Elimina únicamente el código que quede obsoleto por los cambios solicitados.
4. Implementa validaciones en el servidor, protección contra duplicados y consistencia transaccional cuando corresponda.
5. Conserva los datos históricos y evita inconsistencias entre el estado del sistema y la mercancía física.
6. No modifiques el módulo de impresión de etiquetas fuera de la integración estrictamente necesaria para la reimpresión individual.
7. No agregues funcionalidades, dependencias ni refactorizaciones fuera del alcance.
8. Si detectas una dependencia que impide cumplir un requerimiento sin afectar otra funcionalidad, documenta el problema y aplica la solución de menor impacto.

## 7. Pruebas y entrega
Verifica, como mínimo:
- Cambio de Administrador a Auditor, conservación de datos y restricción efectiva de permisos.
- Agregado, edición, eliminación y marcado de N/S según las restricciones de impresión.
- Anulación y reutilización segura del último número de embarque.
- Traslado de todas las piezas y visualización correcta del embarque de destino.
- Reimpresión exclusiva de la etiqueta agregada o modificada.
- Filtro diario y exportación Excel limitada a la fecha seleccionada.
- Funcionamiento en handhelds y ausencia de regresiones en escaneo e impresión.

Al finalizar, informa qué archivos modificaste, qué código eliminaste o reutilizaste, qué validaciones implementaste y qué pruebas ejecutaste. Señala las pruebas pendientes; no declares funcionalidades verificadas si no las probaste.