// Prueba de la sección "Gatillo = Enter" de public/js/escaneo.js en jsdom.
import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const codigo = fs.readFileSync(process.argv[2], 'utf8');
const espera = ms => new Promise(r => setTimeout(r, ms));

function crear({ teclado = false, sku = false, limpia = true } = {}) {
  // sku: el buscador del WMS (sin data-escaneo). limpia: el envío vacía el campo
  // (N/S y andén por fetch); el SKU recarga la página y el campo conserva lo leído.
  const dom = new JSDOM(`<!doctype html><html><head><meta name="csrf-token" content="t"></head><body>
    <form id="f" action="/embarques/1/cargar"><div class="control"><input id="c" ${sku ? 'data-sku-input' : 'data-escaneo'}></div></form>
    ${sku ? '<form action="/escaneo/ns"><input id="ns" data-escaneo disabled></form>' : ''}
  </body></html>`, { url: 'https://movingupper.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  w.matchMedia = () => ({ matches: true }); // equipo táctil
  w.HTMLElement.prototype.scrollIntoView = () => {}; // jsdom no lo implementa
  if (teclado) w.localStorage.setItem('escaneo-teclado', '1');
  w.eval(codigo);
  const campo = w.document.getElementById('c');
  const form = w.document.getElementById('f');
  const envios = [];
  form.addEventListener('submit', e => { e.preventDefault(); envios.push(campo.value); if (limpia) campo.value = ''; });

  // Simula lo que escribe el escáner: un evento input por carácter (o de golpe).
  // pausas: {índice: ms} antes de ese carácter (el equipo se ocupó un momento).
  async function escribir(texto, { cada = 8, deGolpe = false, pausas = {}, tipo } = {}) {
    if (deGolpe) {
      campo.value += texto;
      campo.dispatchEvent(new w.InputEvent('input', { data: texto, inputType: tipo, bubbles: true }));
      return;
    }
    let i = 0;
    for (const ch of texto) {
      if (pausas[i]) await espera(pausas[i]);
      campo.value += ch;
      campo.dispatchEvent(new w.InputEvent('input', { data: ch, inputType: tipo, bubbles: true }));
      await espera(typeof cada === 'function' ? cada(i) : cada);
      i++;
    }
  }
  function tecla(key) {
    const e = new w.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
    campo.dispatchEvent(e);
    return e;
  }
  // Enter real: si nadie lo cancela, el navegador hace el envío implícito.
  function enter() {
    const e = tecla('Enter');
    if (!e.defaultPrevented) form.requestSubmit();
    return e;
  }
  return { w, campo, envios, escribir, tecla, enter };
}

// 1. Ráfaga del escáner sin Enter: se envía sola, una vez.
{
  const t = crear();
  await t.escribir('TAR-12|20261012');
  assert.deepEqual(t.envios, []);
  await espera(200);
  assert.deepEqual(t.envios, ['TAR-12|20261012']);
  await espera(200);
  assert.equal(t.envios.length, 1);
  console.log('ok 1: lectura sin Enter se envía sola');
}

// 2. DataWedge escribe todo de golpe: también se envía.
{
  const t = crear();
  await t.escribir('A1B2C3D4', { deGolpe: true });
  await espera(200);
  assert.deepEqual(t.envios, ['A1B2C3D4']);
  console.log('ok 2: lectura escrita de golpe se envía sola');
}

// 3. El escáner sí manda Enter: no hay envío automático extra.
{
  const t = crear();
  await t.escribir('NS-000123');
  const e = t.tecla('Enter');
  assert.equal(e.defaultPrevented, false, 'el Enter del escáner hace su envío normal');
  await espera(200);
  assert.deepEqual(t.envios, [], 'no se duplica (el envío normal lo hace el navegador)');
  console.log('ok 3: con Enter del escáner no se duplica');
}

// 4. Escritura a mano (lenta): no se envía sola.
{
  const t = crear();
  await t.escribir('2364F', { cada: 150 });
  await espera(200);
  assert.deepEqual(t.envios, []);
  console.log('ok 4: escribir a mano no envía solo');
}

// 5. Sufijo Tab del escáner: se envía al instante y no salta de campo.
{
  const t = crear();
  await t.escribir('T-004X');
  const e = t.tecla('Tab');
  assert.equal(e.defaultPrevented, true);
  assert.deepEqual(t.envios, ['T-004X']);
  await espera(200);
  assert.equal(t.envios.length, 1);
  console.log('ok 5: sufijo Tab se trata como Enter');
}

// 6. Teclado en pantalla activado (⌨): la ráfaga del escáner tecla por tecla sí
//    se envía; el texto predictivo (de golpe), la composición y escribir a mano no.
{
  const t = crear({ teclado: true });
  await t.escribir('TAR-12|20261012');
  await espera(200);
  assert.deepEqual(t.envios, ['TAR-12|20261012'], 'el gatillo sigue funcionando con ⌨');

  const p = crear({ teclado: true });
  await p.escribir('REFRIGERADOR ', { deGolpe: true, tipo: 'insertText' });
  await p.escribir('ABCDEF', { tipo: 'insertCompositionText' });
  await espera(200);
  assert.deepEqual(p.envios, [], 'predictivo y composición no se envían solos');

  const m = crear({ teclado: true });
  await m.escribir('2364F', { cada: 150 });
  await espera(200);
  assert.deepEqual(m.envios, []);
  console.log('ok 6: con ⌨ activado el gatillo envía; el predictivo y escribir a mano no');
}

// 7. Enter tardío después del envío automático: se ignora (es de la misma lectura).
{
  const t = crear();
  await t.escribir('NS-777777');
  await espera(200);
  const e = t.tecla('Enter');
  assert.equal(e.defaultPrevented, true);
  assert.deepEqual(t.envios, ['NS-777777']);
  console.log('ok 7: Enter tardío no duplica la lectura');
}

// 8. Dos lecturas seguidas: cada una se envía una vez.
{
  const t = crear();
  await t.escribir('T-001X');
  await espera(200);
  await espera(450);
  await t.escribir('T-002X');
  await espera(200);
  assert.deepEqual(t.envios, ['T-001X', 'T-002X']);
  console.log('ok 8: lecturas consecutivas se envían por separado');
}

// 9. Lectura nueva con su Enter justo después de un envío automático: el Enter
//    es de la lectura nueva y no se descarta (antes quedaba en el campo sin enviar).
{
  const t = crear();
  await t.escribir('NS-AAAA01');
  await espera(130);               // envío automático de la primera
  await t.escribir('NS-BBBB02', { cada: 4 });
  const e = t.enter();
  assert.equal(e.defaultPrevented, false);
  await espera(300);
  assert.deepEqual(t.envios, ['NS-AAAA01', 'NS-BBBB02']);
  assert.equal(t.campo.value, '');
  console.log('ok 9: el Enter de una lectura nueva no se pierde tras un envío automático');
}

// 10. Tropiezo de más de 40 ms (sin llegar a 120) cerca del final, sin Enter:
//     la lectura completa se envía una vez (antes quedaba en el campo).
{
  const t = crear();
  await t.escribir('SN00012345', { pausas: { 8: 60 } });
  await espera(300);
  assert.deepEqual(t.envios, ['SN00012345']);
  console.log('ok 10: un tropiezo dentro de la lectura no impide el envío');
}

// 11. El reloj del equipo se atrasa justo después de un envío automático: el
//     Enter de las lecturas siguientes no se descarta.
{
  const t = crear();
  await t.escribir('NS-CCCC03');
  await espera(130);
  const real = t.w.Date.now.bind(t.w.Date);
  t.w.Date.now = () => real() - 60000;
  await espera(600);
  await t.escribir('NS-DDDD04');
  t.enter();
  await espera(50);
  assert.deepEqual(t.envios, ['NS-CCCC03', 'NS-DDDD04']);
  console.log('ok 11: un cambio de hora del equipo no bloquea el Enter');
}

// 12. Campo que conserva lo leído al enviar (SKU, recarga la página): el Enter
//     tardío de la misma lectura no la envía dos veces.
{
  const t = crear({ sku: true, limpia: false });
  await t.escribir('REF-100');
  await espera(130);
  const e = t.enter();
  assert.equal(e.defaultPrevented, true);
  await espera(50);
  assert.deepEqual(t.envios, ['REF-100']);
  console.log('ok 12: SKU con Enter tardío no se envía dos veces');
}

// 13. SKU del WMS: la ráfaga del escáner se envía; una sugerencia insertada de
//     golpe y la escritura a mano no.
{
  const t = crear({ sku: true });
  await t.escribir('EST-200');
  await espera(200);
  assert.deepEqual(t.envios, ['EST-200']);

  const s = crear({ sku: true });
  await s.escribir('REF-100', { deGolpe: true, tipo: 'insertReplacementText' });
  await s.escribir('REFRIGERADOR', { deGolpe: true, tipo: 'insertText' });
  await s.escribir('ref', { cada: 140 });
  await espera(200);
  assert.deepEqual(s.envios, []);
  console.log('ok 13: SKU por escáner se envía; sugerencias y escritura a mano no');
}

// 14. Escritura a mano con algún par de teclas muy rápido: no se envía sola
//     (la tolerancia al tropiezo solo aplica a una lectura ya reconocida).
{
  const t = crear();
  await t.escribir('ABC123XY', { cada: i => (i % 3 === 0 ? 20 : 150) });
  await espera(200);
  assert.deepEqual(t.envios, []);
  console.log('ok 14: escribir a mano con pares rápidos no envía solo');
}

console.log('\nTodas las pruebas del gatillo pasaron.');
