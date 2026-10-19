// Prueba de la sección "Gatillo = Enter" de public/js/escaneo.js en jsdom.
import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const codigo = fs.readFileSync(process.argv[2], 'utf8');
const espera = ms => new Promise(r => setTimeout(r, ms));

function crear({ teclado = false } = {}) {
  const dom = new JSDOM(`<!doctype html><html><head><meta name="csrf-token" content="t"></head><body>
    <form id="f" action="/embarques/1/cargar"><div class="control"><input id="c" data-escaneo></div></form>
  </body></html>`, { url: 'https://movingupper.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  w.matchMedia = () => ({ matches: true }); // equipo táctil
  w.HTMLElement.prototype.scrollIntoView = () => {}; // jsdom no lo implementa
  if (teclado) w.localStorage.setItem('escaneo-teclado', '1');
  w.eval(codigo);
  const campo = w.document.getElementById('c');
  const envios = [];
  w.document.getElementById('f').addEventListener('submit', e => { e.preventDefault(); envios.push(campo.value); campo.value = ''; });

  // Simula lo que escribe el escáner: un evento input por carácter (o de golpe).
  async function escribir(texto, { cada = 8, deGolpe = false } = {}) {
    if (deGolpe) {
      campo.value += texto;
      campo.dispatchEvent(new w.InputEvent('input', { data: texto, bubbles: true }));
      return;
    }
    for (const ch of texto) {
      campo.value += ch;
      campo.dispatchEvent(new w.InputEvent('input', { data: ch, bubbles: true }));
      await espera(cada);
    }
  }
  function tecla(key) {
    const e = new w.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
    campo.dispatchEvent(e);
    return e;
  }
  return { w, campo, envios, escribir, tecla };
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

// 6. Teclado en pantalla activado (captura manual): nada se envía solo.
{
  const t = crear({ teclado: true });
  await t.escribir('TAR-12|20261012');
  await espera(200);
  assert.deepEqual(t.envios, []);
  console.log('ok 6: con teclado manual activado no envía solo');
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

console.log('\nTodas las pruebas del gatillo pasaron.');
