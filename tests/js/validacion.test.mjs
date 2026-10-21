// Prueba en jsdom del flujo de validación de N/S (public/js/escaneo.js) y del
// selector de SKU (public/js/inventario.js).
//   node validacion.test.mjs <proyecto>/public/js/escaneo.js <proyecto>/public/js/inventario.js
import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const escaneo = fs.readFileSync(process.argv[2], 'utf8');
const inventario = fs.readFileSync(process.argv[3], 'utf8');
const espera = ms => new Promise(r => setTimeout(r, ms));

function pagina(respuestas) {
  const dom = new JSDOM(`<!doctype html><html><head><meta name="csrf-token" content="t"></head><body>
    <article class="pallet" data-tarima="u1"><div data-registro="7"><span data-total-registro>0</span><p data-sin-ns></p><div class="chips" data-chips hidden></div></div>
      <ul data-anomalias-lista></ul><span data-piezas>0</span>
      <a class="btn btn-secondary" data-etiquetas-boton>Etiquetas <span data-etiquetas-pendientes hidden>0 sin imprimir</span></a></article>
    <form method="POST" action="/escaneo/ns" data-escaneo-ns><div class="field"><div class="control"><input id="numero_serie" name="numero_serie" data-escaneo autofocus></div></div></form>
    <dialog id="dlgSerie"><div data-serie-icono></div><h2 data-serie-titulo></h2><p data-serie-ns></p><p data-serie-texto></p></dialog>
  </body></html>`, { url: 'https://movingupper.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  w.matchMedia = () => ({ matches: false });
  w.HTMLElement.prototype.scrollIntoView = () => {};
  w.CSS = { escape: s => String(s).replace(/["\\]/g, '\\$&') }; // jsdom no trae CSS.escape
  // Si algo intentara preguntar (confirm o modal), la prueba lo detecta.
  const preguntas = [];
  w.confirm = texto => { preguntas.push(texto); return true; };
  if (w.HTMLDialogElement) w.HTMLDialogElement.prototype.showModal = function () { preguntas.push('showModal'); this.open = true; };
  const llamadas = [];
  w.fetch = async (url, op) => {
    llamadas.push({ url, cuerpo: Object.fromEntries(op.body.entries()) });
    const r = respuestas.shift();
    return { ok: r.status < 400, status: r.status, json: async () => r.body };
  };
  w.eval(escaneo);
  const campo = w.document.getElementById('numero_serie');
  const enviar = valor => { campo.value = valor; w.document.querySelector('[data-escaneo-ns]').dispatchEvent(new w.Event('submit', { cancelable: true })); };
  return { w, llamadas, preguntas, enviar, estado: () => w.document.querySelector('.scan-estado')?.textContent };
}

const registrada = (ns, extra = {}) => ({ status: 200, body: {
  estado: 'registrada', mensaje: `N/S ${ns} encontrada`, numero_serie: ns, numero_serie_id: 1, registro: 7, tarima: 'u1',
  total_registro: 1, piezas_tarima: 1, etiquetas_pendientes: 1, coincidencia: 'Número de serie', incidencia: null, ...extra,
} });
const sinCoincidencia = ns => registrada(ns, {
  mensaje: `N/S ${ns} no está en el inventario: se registró y quedó pendiente de revisión (incidencia INC-0001). 1 en total.`,
  coincidencia: null, incidencia: { folio: 'INC-0001', url: '/incidencias/1' },
});

// 1. Coincidencia: se registra sin preguntar.
{
  const t = pagina([registrada('NS-1')]);
  t.enviar('NS-1');
  await espera(20);
  assert.equal(t.llamadas.length, 1);
  assert.equal(t.preguntas.length, 0);
  assert.equal(t.w.document.querySelector('[data-chips] .chip').textContent, 'NS-1');
  // La pieza nueva necesita su etiqueta: el contador del embarque se actualiza sin recargar.
  const pendientes = t.w.document.querySelector('[data-etiquetas-pendientes]');
  assert.equal(pendientes.hidden, false);
  assert.equal(pendientes.textContent, '1 sin imprimir');
  assert.ok(t.w.document.querySelector('[data-etiquetas-boton]').classList.contains('btn-accent'));
  console.log('ok 1: con coincidencia se registra sin preguntar y cuenta la etiqueta pendiente');
}

// 2. Sin coincidencia: el servidor ya la registró con su incidencia. No hay modal
//    ni confirm(), una sola solicitud, la N/S queda señalada y el campo sigue listo.
{
  const t = pagina([sinCoincidencia('NO-2')]);
  t.enviar('NO-2');
  await espera(20);
  assert.equal(t.preguntas.length, 0, 'no se pregunta nada');
  assert.equal(t.llamadas.length, 1, 'una sola solicitud');
  assert.equal(t.llamadas[0].cuerpo.confirmar_incidencia, undefined);
  assert.equal(t.w.document.getElementById('dlgSerie').open, false, 'sin modal');
  const chip = t.w.document.querySelector('[data-chips] .chip');
  assert.ok(chip.classList.contains('chip-revision'));
  assert.match(chip.textContent, /⚠ NO-2revisar/);
  assert.match(t.estado(), /pendiente de revisión \(incidencia INC-0001\)/);
  assert.equal(t.w.document.activeElement, t.w.document.getElementById('numero_serie'), 'el foco sigue en el campo');
  console.log('ok 2: sin coincidencia se registra sola, sin modal, y queda señalada');
}

// 3. Escaneo continuo: lecturas seguidas (con y sin coincidencia) se envían todas, en orden, sin detenerse.
{
  const t = pagina([sinCoincidencia('NO-3'), registrada('NS-4'), sinCoincidencia('NO-5')]);
  t.enviar('NO-3');
  t.enviar('NS-4');
  t.enviar('NO-5');
  await espera(40);
  assert.deepEqual(t.llamadas.map(l => l.cuerpo.numero_serie), ['NO-3', 'NS-4', 'NO-5']);
  assert.equal(t.preguntas.length, 0);
  assert.equal(t.w.document.querySelectorAll('[data-chips] .chip-revision').length, 2);
  console.log('ok 3: el escaneo continuo no se interrumpe');
}

// ---------- Selector de SKU ----------
function selector(respuestas) {
  const dom = new JSDOM(`<!doctype html><html><body><form>
    <select data-sku-tarima><option value="u1" selected>T-001</option><option value="u2">T-002</option></select>
    <div data-sku-selector data-url="/escaneo/skus"><input data-sku-input><button type="button" data-sku-abrir></button>
      <ul data-sku-lista hidden></ul><p data-sku-estado></p></div>
    <input data-sku-descripcion readonly></form></body></html>`, { url: 'https://movingupper.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  const urls = [];
  w.fetch = async url => {
    urls.push(url);
    const { body, retraso = 0 } = respuestas.shift();
    await espera(retraso);
    return { ok: true, status: 200, json: async () => body };
  };
  w.eval(inventario);
  const input = w.document.querySelector('[data-sku-input]');
  const escribir = valor => { input.value = valor; input.dispatchEvent(new w.Event('input')); };
  return { w, urls, input, escribir, desc: w.document.querySelector('[data-sku-descripcion]') };
}

const opciones = lista => ({ body: { inventario: true, almacen: 'Almacén 58', opciones: lista } });
const REF = { sku: 'REF-100', modelo: 'RF', descripcion: 'Refrigerador', piezas: 2 };
const EST = { sku: 'EST-200', modelo: null, descripcion: 'Estufa', piezas: 1 };

// 5. Buscar por texto de la tarima elegida y completar la descripción al elegir.
{
  const t = selector([opciones([REF, EST]), opciones([REF])]);
  await espera(20);
  t.escribir('ref');
  await espera(260);
  assert.match(t.urls.at(-1), /tarima=u1&q=ref/);
  const li = t.w.document.querySelector('[role="option"]');
  li.dispatchEvent(new t.w.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
  assert.equal(t.input.value, 'REF-100');
  assert.equal(t.desc.value, 'Refrigerador');
  console.log('ok 5: el selector filtra y completa la descripción del inventario');
}

// 6. Respuestas tardías no pisan la última búsqueda; sin inventario se avisa y se desactiva.
{
  const t = selector([opciones([REF, EST]), { ...opciones([EST]), retraso: 120 }, opciones([REF])]);
  await espera(20);
  t.escribir('e');
  await espera(230);   // sale la consulta "e" (tardará)
  t.escribir('re');
  await espera(400);
  const visibles = [...t.w.document.querySelectorAll('[role="option"] .combo-sku')].map(n => n.textContent);
  assert.deepEqual(visibles, ['REF-100']);

  const sin = selector([{ body: { inventario: false, almacen: 'Almacén 58', mensaje: 'No hay inventario cargado para el Almacén 58.', opciones: [] } }]);
  await espera(20);
  assert.equal(sin.input.disabled, true);
  assert.match(sin.w.document.querySelector('[data-sku-estado]').textContent, /No hay inventario/);
  console.log('ok 6: respuestas tardías se descartan y sin inventario se avisa');
}

// 7. Lista abierta (de una búsqueda anterior): el Enter del escáner envía lo leído
//    y no la opción resaltada; Enter elige de la lista solo tras navegar con flechas.
{
  const t = selector([opciones([EST, REF]), opciones([EST, REF]), opciones([EST, REF])]);
  await espera(20);
  t.escribir('e');
  await espera(260);              // lista abierta con EST-200 resaltada
  const enter = () => {
    const e = new t.w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    t.input.dispatchEvent(e);
    return e;
  };
  t.escribir('REF-100');          // lectura del escáner, sin esperar la búsqueda
  let e = enter();
  assert.equal(e.defaultPrevented, false, 'el Enter del escáner envía');
  assert.equal(t.input.value, 'REF-100');

  t.escribir('E');
  await espera(260);
  t.input.dispatchEvent(new t.w.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
  e = enter();
  assert.equal(e.defaultPrevented, true, 'tras las flechas, Enter elige sin enviar');
  assert.equal(t.input.value, 'REF-100');
  console.log('ok 7: con la lista abierta, Enter envía lo escaneado; con flechas, elige');
}

console.log('\nTodas las pruebas de validación de N/S y selector de SKU pasaron.');
