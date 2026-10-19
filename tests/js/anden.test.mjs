// Prueba de public/js/anden.js en un DOM simulado (jsdom): cola, reintentos,
// idempotencia, dobles lecturas y recuperación tras recargar.
import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const codigo = fs.readFileSync(process.argv[2], 'utf8');
const espera = ms => new Promise(r => setTimeout(r, ms));

const HTML = `<!doctype html><html><head><meta name="csrf-token" content="t"></head><body>
<section data-anden data-modo="cargar" data-clave="embarque-7-en_carga">
  <form method="POST" action="/embarques/7/cargar" data-anden-form>
    <input id="codigo" name="codigo" data-escaneo>
    <div data-anden-estado hidden><span data-icono></span><p data-titulo></p><p data-texto></p></div>
    <div data-anden-acciones hidden></div>
    <div data-anden-pendientes hidden><span data-texto></span><button type="button" data-reintentar>R</button></div>
    <ol data-anden-log></ol>
  </form>
</section>
<span data-anden-total>0</span><span data-anden-total-tarimas>0</span>
<div data-anden-vacio></div>
<ul data-anden-lista></ul>
<template id="tpl-fila-tarima"><li><div><b data-codigo></b><span data-piezas></span><span data-hora></span></div><form method="POST" data-motivo><input name="motivo"><button>Bajar</button></form></li></template>
</body></html>`;

function crear(storage = {}) {
  const dom = new JSDOM(HTML, { url: 'https://movingupper.test/embarques/7', runScripts: 'outside-only' });
  const w = dom.window;
  for (const [k, v] of Object.entries(storage)) w.localStorage.setItem(k, v);
  w.CSS = { escape: s => String(s).replace(/["\\]/g, '\\$&') };
  const avisos = [];
  w.MovingUpperAviso = { ok: () => avisos.push('ok'), repetido: () => avisos.push('repetido'), error: () => avisos.push('error') };
  const llamadas = [];
  let respuestas = [];
  w.fetch = async (url, op) => {
    llamadas.push({ url, codigo: op.body.get('codigo') });
    const r = respuestas.shift();
    if (!r) throw new w.TypeError('Failed to fetch');
    if (r === 'red') throw new w.TypeError('Failed to fetch');
    return { ok: r.status === 200, status: r.status, json: async () => r.body };
  };
  // location.reload no se usa en estos escenarios (jsdom lo marca como no implementado).
  return {
    w, avisos, llamadas,
    responder: (...rs) => { respuestas.push(...rs); },
    cargar: () => w.eval(codigo),
    escanear: valor => {
      const campo = w.document.getElementById('codigo');
      campo.value = valor;
      w.document.querySelector('[data-anden-form]').dispatchEvent(new w.Event('submit', { cancelable: true, bubbles: true }));
    },
    log: () => [...w.document.querySelectorAll('[data-anden-log] li')].map(li => [li.querySelector('.anden-log-codigo').textContent, li.dataset.tipo]),
    estado: () => w.document.querySelector('[data-anden-estado]').dataset.tipo,
    cola: () => JSON.parse(w.localStorage.getItem('anden-cola:embarque-7-en_carga') || '[]'),
  };
}

const ok = (codigo, extra = {}) => ({ status: 200, body: { estado: 'ok', mensaje: `${codigo}: 2 piezas cargadas.`, tarima: codigo, tarima_id: 1, piezas: 2, total: 2, tarima_cargadas: 2, recibidas: 0, tarima_recibidas: 0, bajar_url: '/embarques/7/tarimas/u/bajar', ...extra } });

// 1. Lectura aceptada: estado, fila nueva en la lista y total.
{
  const t = crear();
  t.cargar();
  t.responder(ok('E-001'));
  t.escanear('E-001');
  await espera(20);
  assert.deepEqual(t.log(), [['E-001', 'ok']]);
  assert.equal(t.estado(), 'ok');
  assert.deepEqual(t.avisos, ['ok']);
  assert.equal(t.w.document.querySelector('[data-anden-total]').textContent, '2');
  const fila = t.w.document.querySelector('[data-anden-lista] li');
  assert.equal(fila.querySelector('[data-codigo]').textContent, 'E-001');
  assert.equal(fila.querySelector('form').getAttribute('action'), '/embarques/7/tarimas/u/bajar');
  assert.equal(t.cola().length, 0);

  // 2. Doble lectura del gatillo (< 1.5 s): se ignora, no se envía.
  t.escanear('E-001');
  await espera(20);
  assert.equal(t.llamadas.length, 1);
  console.log('ok 1-2: lectura aceptada y doble lectura ignorada');
}

// 3. Sin red: queda pendiente (y guardada en el equipo); al volver la red se
//    reenvía; "repetido" en un reintento se muestra como guardado.
{
  const t = crear();
  t.cargar();
  t.responder('red');
  t.escanear('E-002');
  await espera(20);
  assert.deepEqual(t.log(), [['E-002', 'pendiente']]);
  assert.equal(t.estado(), 'pendiente');
  assert.equal(t.cola().length, 1);
  assert.equal(t.cola()[0].codigo, 'E-002');
  assert.equal(t.w.document.querySelector('[data-anden-pendientes]').hidden, false);
  assert.match(t.w.document.querySelector('[data-anden-estado] [data-texto]').textContent, /NO se ha guardado/);

  // Mientras no hay red, otra lectura se encola detrás (orden preservado).
  t.escanear('E-003');
  await espera(20);
  assert.deepEqual(t.cola().map(i => i.codigo), ['E-002', 'E-003']);

  t.responder({ status: 200, body: { ...ok('E-002').body, estado: 'repetido', mensaje: 'E-002: ya estaba en este tráiler. No se duplicó.' } }, ok('E-003', { tarima_id: 3 }));
  t.w.dispatchEvent(new t.w.Event('online'));
  await espera(30);
  assert.deepEqual(t.log(), [['E-003', 'ok'], ['E-002', 'ok']]);
  assert.match(t.w.document.querySelector('[data-id] .anden-log-texto').textContent + t.log().join(), /E-003/);
  assert.equal(t.cola().length, 0);
  assert.equal(t.w.document.querySelector('[data-anden-pendientes]').hidden, true);
  // Una lectura nueva sin red reintenta la cabeza de la cola; E-003 nunca se
  // envía antes de que E-002 quede confirmada.
  const orden = t.llamadas.map(l => l.codigo);
  assert.equal(orden.at(-1), 'E-003');
  assert.ok(orden.slice(0, -1).every(c => c === 'E-002'));
  console.log('ok 3: sin red queda pendiente, se reenvía en orden y no se duplica');
}

// 4. Rechazo (422) no detiene la siguiente lectura; 5. "No viene" ofrece incidencia.
{
  const t = crear();
  t.cargar();
  t.responder(
    { status: 422, body: { message: 'x', errors: { codigo: ['No se reconoce «ZZZ».'] } } },
    ok('E-004', { tarima_id: 4 }),
  );
  t.escanear('ZZZ');
  t.escanear('E-004');
  await espera(30);
  assert.deepEqual(t.log(), [['E-004', 'ok'], ['ZZZ', 'error']]);
  assert.equal(t.cola().length, 0);
  assert.deepEqual(t.avisos, ['error', 'ok']);
  console.log('ok 4: un rechazo no detiene las siguientes lecturas');

  t.responder({ status: 200, body: { estado: 'no_esperada', mensaje: 'E-009: NO viene en el tráiler TRL-0001.', tarima: 'E-009', tarima_id: 9, piezas: 0, total: 2, tarima_cargadas: 0 } });
  t.escanear('E-009');
  await espera(20);
  assert.equal(t.estado(), 'no_esperada');
  assert.equal(t.w.document.querySelector('[data-anden-acciones] button'), null, 'ya no ofrece registrar incidencia');
  assert.equal(t.w.document.querySelectorAll('[data-anden-lista] li').length, 1, 'no se agrega a la lista');
  console.log('ok 5: "no viene" no se asocia y solo se avisa');
}

// 6. Sesión expirada: queda pendiente sin reintentar solo; 7. Recargar la página reenvía lo pendiente.
{
  const t = crear();
  t.cargar();
  t.responder({ status: 419, body: { message: 'CSRF token mismatch.' } });
  t.escanear('E-005');
  await espera(20);
  assert.equal(t.estado(), 'pendiente');
  assert.match(t.w.document.querySelector('[data-anden-pendientes] [data-texto]').textContent, /sesión expiró/);
  const guardado = t.w.localStorage.getItem('anden-cola:embarque-7-en_carga');

  const t2 = crear({ 'anden-cola:embarque-7-en_carga': guardado });
  t2.responder(ok('E-005', { tarima_id: 5 }));
  t2.cargar();
  await espera(30);
  assert.deepEqual(t2.log(), [['E-005', 'ok']]);
  assert.equal(t2.cola().length, 0);
  console.log('ok 6-7: sesión expirada queda pendiente y al recargar se reenvía');
}

// 8. Lecturas pendientes de otra etapa (el tráiler ya salió): se avisan, no se envían.
{
  const viejo = JSON.stringify([{ id: 'a', codigo: 'E-008', t: 1 }]);
  const t = crear({ 'anden-cola:embarque-7-en_transito': viejo, 'anden-cola:embarque-77-en_carga': viejo });
  t.cargar();
  await espera(10);
  const alertas = [...t.w.document.querySelectorAll('.alert-danger')];
  assert.equal(alertas.length, 1, 'solo la del mismo embarque');
  assert.match(alertas[0].textContent, /E-008/);
  assert.equal(t.llamadas.length, 0);
  alertas[0].querySelector('button').click();
  assert.equal(t.w.localStorage.getItem('anden-cola:embarque-7-en_transito'), null);
  console.log('ok 8: lo pendiente de otra etapa se avisa y no se envía a otra operación');
}

console.log('\nTodas las pruebas de anden.js pasaron.');
