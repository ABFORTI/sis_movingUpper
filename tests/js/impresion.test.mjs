// Prueba de public/js/impresion.js con un Web Bluetooth simulado.
import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const codigo = fs.readFileSync(process.argv[2], 'utf8');
const espera = ms => new Promise(r => setTimeout(r, ms));

const HTML = `<!doctype html><html><head><meta name="csrf-token" content="t"></head><body>
<button id="btnImprimir" data-zpl="/tarimas/u/etiqueta.zpl" data-impresa="/tarimas/u/impresion" data-uuid="11111111-1111-1111-1111-111111111111" data-diagnostico="/impresion/diagnostico" data-texto-base="Imprimir etiqueta"><span data-texto>Imprimir etiqueta</span></button>
<div id="impresionEstado"></div>
<div id="impresionProgreso" hidden><progress max="100"></progress><button id="btnCancelar">Cancelar</button></div>
<div id="impresionVerificar" hidden><button id="btnConfirmar">Sí</button><button id="btnReintentar">No</button></div>
<select id="impresoraEquipo"><option value="">—</option>__OPCIONES__</select>
<p id="impresoraActual"></p><pre id="impresorasDetectadas" hidden></pre>
<button id="btnElegir">Elegir</button><button id="btnBuscar">Buscar</button><button id="btnOlvidar" hidden>Olvidar</button>
<button id="btnNavegador">Navegador</button>
<div id="zonaImpresion"><svg class="etiqueta-svg"></svg></div>
</body></html>`;

/**
 * Impresora BLE falsa. `conexion`: 'ok' | 'colgada'.
 * `browserPrint`: false (no instalado) o { disponibles, predeterminada, rechazaWrite }.
 * `impresoras`: registradas en el sistema [{ mac, nombre, bt }]; `guardada`: MAC recordada en el equipo.
 */
function crear({ browserPrint = false, conexion = 'ok', escrituraMs = 0, impresoras = [], guardada = null, respuestaImpresa = { impresa: true, pendientes: 0 } } = {}) {
  const opciones = impresoras.map(i => `<option value="${i.mac}" data-nombre="${i.nombre}" data-nombre-bluetooth="${i.bt || ''}">${i.nombre}</option>`).join('');
  const dom = new JSDOM(HTML.replace('__OPCIONES__', opciones), { url: 'https://movingupper.test/tarimas/u/etiqueta', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  // Los tiempos de espera del script se aceleran 1000× para la prueba.
  const st = w.setTimeout.bind(w);
  w.setTimeout = (fn, ms, ...a) => st(fn, Math.max(0, (ms || 0) / 1000), ...a);

  if (guardada) w.localStorage.setItem('impresora-mac', guardada);
  const reg = { enToque: false, selectorEnToque: [], filtros: [], desconexiones: 0, escrito: '', fetch: [], diagnosticos: [], enviadoA: [] };
  const caracteristica = {
    writeValueWithResponse: async bytes => { if (escrituraMs) await espera(escrituraMs); reg.escrito += Buffer.from(bytes).toString('utf8'); },
  };
  const dispositivo = {
    id: 'zq620', name: 'XXZKJ-ZQ620',
    addEventListener() {},
    gatt: {
      connected: false,
      connect() {
        if (conexion === 'colgada') return new Promise(() => {}); // nunca responde
        this.connected = true;
        return Promise.resolve({ getPrimaryService: async () => ({ getCharacteristic: async () => caracteristica }) });
      },
      disconnect() { reg.desconexiones++; this.connected = false; },
    },
  };
  w.TextEncoder = TextEncoder; // jsdom no lo trae; todos los navegadores sí
  Object.defineProperty(w, 'isSecureContext', { value: true });
  Object.defineProperty(w.navigator, 'bluetooth', {
    value: {
      requestDevice: filtro => { reg.selectorEnToque.push(reg.enToque); reg.filtros.push(filtro); return Promise.resolve(dispositivo); },
      getDevices: async () => [],
    },
  });
  w.fetch = async (url, op = {}) => {
    reg.fetch.push(String(url));
    if (String(url).includes('localhost:')) {
      if (!browserPrint) throw new w.TypeError('Failed to fetch');
      if (String(url).includes('/default')) return { ok: true, text: async () => browserPrint.predeterminada ? JSON.stringify(browserPrint.predeterminada) : '' };
      if (String(url).includes('/available')) return { ok: true, text: async () => JSON.stringify({ printer: browserPrint.disponibles || [] }) };
      if (String(url).includes('/write')) {
        const { device } = JSON.parse(op.body);
        reg.enviadoA.push(device);
        const conocida = [...(browserPrint.disponibles || []), browserPrint.predeterminada].some(d => d && d.uid === device.uid);
        if (browserPrint.rechazaWrite && !conocida) return { ok: false, status: 500, text: async () => 'Device not found' };
        return { ok: true, text: async () => '' };
      }
    }
    if (String(url).endsWith('.zpl')) return { ok: true, text: async () => '^XA^FO0,0^FDhola^FS' + 'x'.repeat(5000) + '^XZ' };
    if (String(url).includes('diagnostico')) { reg.diagnosticos.push(JSON.parse(op.body)); return { ok: true }; }
    if (String(url).includes('/impresion')) return { ok: true, status: 200, json: async () => respuestaImpresa };
    throw new Error('url inesperada ' + url);
  };
  w.confirm = () => true;
  w.eval(codigo);
  const $ = id => w.document.getElementById(id);
  const tocar = id => { reg.enToque = true; $(id).click(); reg.enToque = false; };
  return { w, $, reg, tocar, dispositivo, texto: () => $('impresionEstado').textContent };
}

// 1. Sin Browser Print: "Elegir" abre el selector dentro del toque y prueba la conexión.
{
  const t = crear();
  await espera(30); // termina la búsqueda de Browser Print al cargar
  assert.match(t.$('impresoraActual').textContent, /Browser Print no responde/);
  t.tocar('btnElegir');
  assert.deepEqual(t.reg.selectorEnToque, [true], 'el selector se abre en el mismo toque');
  await espera(30);
  assert.match(t.texto(), /Impresora lista: XXZKJ-ZQ620/);
  assert.equal(t.$('btnImprimir').disabled, false);
  assert.match(t.$('impresoraActual').textContent, /Bluetooth directo: XXZKJ-ZQ620/);
  console.log('ok 1: el selector se abre dentro del toque y la impresora queda lista');

  // 2. Imprimir: envía el ZPL completo, muestra progreso y pide verificar.
  t.tocar('btnImprimir');
  assert.equal(t.$('btnElegir').disabled, true, 'una operación a la vez');
  await espera(50);
  assert.ok(t.reg.escrito.startsWith('^XA') && t.reg.escrito.endsWith('^XZ'));
  assert.equal(t.$('impresionVerificar').hidden, false);
  assert.equal(t.$('impresionProgreso').hidden, true);
  assert.equal(t.reg.selectorEnToque.length, 1, 'no vuelve a pedir la impresora');
  console.log('ok 2: imprime por BLE con progreso, sin volver a pedir la impresora');
}

// 3. Conexión que nunca responde: se aborta con disconnect() y la pantalla se libera.
{
  const t = crear({ conexion: 'colgada' });
  await espera(30);
  t.tocar('btnElegir');
  assert.equal(t.$('impresionProgreso').hidden, true, 'el selector todavía no resolvió');
  await espera(5);
  assert.equal(t.$('impresionProgreso').hidden, false, 'progreso con Cancelar mientras conecta');
  await espera(60); // 12 s acelerados
  assert.ok(t.reg.desconexiones >= 1, 'se abortó la conexión pendiente');
  assert.match(t.texto(), /no respondió a tiempo/);
  assert.equal(t.$('btnElegir').disabled, false);
  assert.equal(t.$('btnImprimir').disabled, false);
  console.log('ok 3: conexión colgada se aborta y la interfaz no se congela');
}

// 4. Cancelar a media transmisión; el siguiente envío limpia el formato a medias (~JA).
{
  const t = crear({ escrituraMs: 3 });
  await espera(30);
  t.tocar('btnElegir');
  await espera(30);
  t.tocar('btnImprimir');
  await espera(25);
  t.$('btnCancelar').click();
  await espera(60);
  assert.match(t.texto(), /Cancelado/);
  assert.equal(t.$('btnImprimir').disabled, false);
  const parcial = t.reg.escrito;
  assert.ok(parcial.length > 0 && !parcial.endsWith('^XZ'), 'se cortó a la mitad');
  t.reg.escrito = '';
  t.tocar('btnImprimir');
  for (let i = 0; i < 150 && !/enviada|no terminó|no respondió/.test(t.texto()); i++) await espera(20);
  assert.ok(t.reg.escrito.startsWith('~JA\n^XA'), 'el reintento empieza borrando lo incompleto');
  assert.ok(t.reg.escrito.endsWith('^XZ'));
  console.log('ok 4: cancelar funciona y el reintento limpia la etiqueta a medias');
}

// 5. Con Browser Print: imprime por ahí, sin selector de Bluetooth.
{
  const t = crear({ browserPrint: { predeterminada: { name: 'ZQ620-BP', connection: 'bluetooth', uid: 'X' } } });
  await espera(30);
  assert.match(t.$('impresoraActual').textContent, /Browser Print → ZQ620-BP/);
  t.tocar('btnImprimir');
  await espera(30);
  assert.equal(t.reg.selectorEnToque.length, 0);
  assert.ok(t.reg.fetch.some(u => u.includes('localhost:9101/write')));
  assert.match(t.texto(), /ZQ620-BP/);
  console.log('ok 5: con Browser Print no se abre el selector');
}

// 6. Imprimir sin impresora elegida y sin Browser Print: el selector se abre en el toque.
{
  const t = crear();
  await espera(30);
  t.tocar('btnImprimir');
  assert.deepEqual(t.reg.selectorEnToque, [true]);
  await espera(50);
  assert.ok(t.reg.escrito.endsWith('^XZ'));
  console.log('ok 6: Imprimir abre el selector dentro del toque si no hay impresora');
}

// 7. Imprimir con el navegador: copia solo la etiqueta colgada del <body>.
{
  const t = crear();
  let raizDuranteImpresion = null;
  t.w.print = () => { raizDuranteImpresion = t.w.document.querySelector('body > #raizImpresion'); };
  t.$('btnNavegador').click();
  assert.ok(raizDuranteImpresion, 'la etiqueta va directo en el body');
  assert.equal(raizDuranteImpresion.querySelectorAll('.hoja-impresion svg').length, 1);
  assert.ok(t.w.document.documentElement.classList.contains('imprimiendo'));
  t.w.dispatchEvent(new t.w.Event('afterprint'));
  assert.equal(t.w.document.getElementById('raizImpresion'), null);
  console.log('ok 7: impresión del navegador aislada y limpiada al terminar');
}

// 8. MAC registrada: Browser Print imprime en la impresora con esa MAC aunque la
//    predeterminada sea otra, y aunque la MAC venga sin separadores.
{
  const zq = { name: 'XXZKJ-ANDEN1', connection: 'bluetooth', uid: 'ac3fa4cdc515', deviceType: 'printer' };
  const otra = { name: 'OTRA', connection: 'bluetooth', uid: '00:07:4D:00:00:01' };
  const t = crear({
    impresoras: [{ mac: 'AC:3F:A4:CD:C5:15', nombre: 'ZQ620 Andén 1' }, { mac: '00:11:22:33:44:55', nombre: 'ZQ620 Andén 2' }],
    guardada: 'AC:3F:A4:CD:C5:15',
    browserPrint: { disponibles: [otra, zq], predeterminada: otra },
  });
  await espera(30);
  assert.equal(t.$('impresoraEquipo').value, 'AC:3F:A4:CD:C5:15', 'se recuerda la impresora del equipo');
  assert.match(t.$('impresoraActual').textContent, /ZQ620 Andén 1 \(AC:3F:A4:CD:C5:15\).*Lista/);
  t.tocar('btnImprimir');
  await espera(30);
  assert.deepEqual(t.reg.enviadoA.map(d => d.uid), ['ac3fa4cdc515']);
  assert.equal(t.reg.selectorEnToque.length, 0);
  assert.match(t.texto(), /enviada a ZQ620 Andén 1/);
  console.log('ok 8: imprime en la impresora cuya MAC está registrada, no en la predeterminada');
}

// 9. Browser Print abierto pero sin esa MAC en su lista: intenta con la MAC; si la rechaza, lo explica.
{
  const t = crear({
    impresoras: [{ mac: 'AC:3F:A4:CD:C5:15', nombre: 'ZQ620 Andén 1' }],
    browserPrint: { disponibles: [], predeterminada: null, rechazaWrite: true },
  });
  await espera(30);
  assert.equal(t.$('impresoraEquipo').value, 'AC:3F:A4:CD:C5:15', 'una sola registrada: se elige sola');
  assert.match(t.$('impresoraActual').textContent, /no tiene la impresora ZQ620 Andén 1/);
  t.tocar('btnImprimir');
  await espera(30);
  assert.deepEqual(t.reg.enviadoA, [{ name: 'ZQ620 Andén 1', uid: 'AC:3F:A4:CD:C5:15', connection: 'bluetooth', deviceType: 'printer', manufacturer: 'Zebra Technologies' }]);
  assert.match(t.texto(), /no reconoce la impresora ZQ620 Andén 1 \(AC:3F:A4:CD:C5:15\).*Discover Printers/);
  assert.equal(t.reg.diagnosticos.at(-1).etapa, 'mac-desconocida');
  assert.equal(t.$('btnImprimir').disabled, false);
  console.log('ok 9: MAC que Browser Print no conoce: intento directo y mensaje claro');
}

// 10. Sin Browser Print y con nombre Bluetooth registrado: el selector de Chrome solo muestra esa impresora.
{
  const t = crear({ impresoras: [{ mac: 'AC:3F:A4:CD:C5:15', nombre: 'ZQ620 Andén 1', bt: 'XXZKJ-ANDEN1' }] });
  await espera(30);
  t.tocar('btnElegir');
  assert.equal(JSON.stringify(t.reg.filtros[0].filters), JSON.stringify([{ name: 'XXZKJ-ANDEN1' }]));
  assert.equal(t.reg.filtros[0].acceptAllDevices, undefined);
  console.log('ok 10: el selector de Chrome se filtra por el nombre Bluetooth registrado');
}

// 11. Diagnóstico: lista lo que ve Browser Print y marca la impresora del equipo.
{
  const t = crear({
    impresoras: [{ mac: 'AC:3F:A4:CD:C5:15', nombre: 'ZQ620 Andén 1' }],
    browserPrint: { disponibles: [{ name: 'XXZKJ-ANDEN1', connection: 'bluetooth', uid: 'AC:3F:A4:CD:C5:15' }, { name: 'ZD420', connection: 'network', uid: '10.0.0.5' }] },
  });
  await espera(30);
  t.$('btnBuscar').click();
  await espera(30);
  const lista = t.$('impresorasDetectadas');
  assert.equal(lista.hidden, false);
  assert.match(lista.textContent, /XXZKJ-ANDEN1 · Bluetooth · MAC AC:3F:A4:CD:C5:15  ← impresora de este equipo/);
  assert.match(lista.textContent, /ZD420 · red · MAC no indicada/);
  assert.match(t.texto(), /ve la impresora ZQ620 Andén 1/);
  assert.equal(t.reg.diagnosticos.at(-1).etapa, 'inventario');
  console.log('ok 11: el diagnóstico muestra las impresoras de Browser Print y su MAC');
}

// 12. Etiquetas por pieza: al confirmar, si faltan piezas del embarque sin
//     etiqueta se avisa y el botón Guardar de la captura no se habilita.
for (const [pendientes, esperado, habilita] of [[2, /Faltan 2 piezas de este embarque sin etiqueta/, false], [0, /ya puedes guardar el embarque/, true]]) {
  const t = crear({ browserPrint: { predeterminada: { name: 'ZQ620', connection: 'bluetooth', uid: 'AC:3F:A4:CD:C5:15' } }, respuestaImpresa: { impresa: true, pendientes } });
  const mensajes = [];
  t.w.BroadcastChannel = class { postMessage(m) { mensajes.push(m); } close() {} };
  await espera(30);
  t.tocar('btnImprimir');
  await espera(50);
  t.$('btnConfirmar').click();
  await espera(30);
  assert.match(t.texto(), esperado);
  assert.equal(t.w.sessionStorage.getItem('tarima-impresa:11111111-1111-1111-1111-111111111111') === '1', habilita);
  assert.equal(mensajes.length, habilita ? 1 : 0);
}
console.log('ok 12: con piezas sin etiqueta avisa cuántas faltan y no habilita Guardar');

console.log('\nTodas las pruebas de impresion.js pasaron.');
