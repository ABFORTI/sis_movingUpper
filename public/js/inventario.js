/*
 * Selector de SKU con búsqueda (combobox accesible).
 *
 * - Las opciones salen del inventario del almacén de origen del embarque
 *   elegida: se piden al servidor por texto (pocas a la vez), nunca se carga el
 *   inventario completo.
 * - Al elegir un SKU, la descripción se completa sola con la del inventario
 *   (solo lectura: el servidor la vuelve a tomar del inventario).
 * - Escanear el SKU + Enter elige la opción idéntica y envía. Enter solo elige
 *   de la lista si se navegó con las flechas: la lista visible puede ser de una
 *   búsqueda anterior a la lectura y no debe reemplazar lo escaneado.
 * - Respuestas tardías se descartan: solo cuenta la última consulta.
 * - Sin JavaScript se puede escribir el SKU exacto; el servidor lo valida igual.
 */
(function () {
  'use strict';

  const raiz = document.querySelector('[data-sku-selector]');
  if (!raiz || !window.fetch) return;

  const form = raiz.closest('form');
  const input = raiz.querySelector('[data-sku-input]');
  const lista = raiz.querySelector('[data-sku-lista]');
  const estado = raiz.querySelector('[data-sku-estado]');
  const abrir = raiz.querySelector('[data-sku-abrir]');
  const descripcion = form.querySelector('[data-sku-descripcion]');
  const tarima = form.querySelector('[data-sku-tarima]');
  const url = raiz.dataset.url;

  let opciones = [];
  let activa = -1;
  let consulta = 0;
  let reloj = null;
  let hayInventario = true;
  let elegido = null;
  let navegando = false; // se movió por la lista con las flechas desde lo último escrito

  function normal(texto) { return (texto || '').trim().toUpperCase(); }

  function aviso(texto, tipo) {
    estado.textContent = texto || '';
    estado.dataset.tipo = tipo || '';
  }

  function cerrar() {
    lista.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    activa = -1;
  }

  function marcar(i) {
    activa = i;
    lista.querySelectorAll('[role="option"]').forEach((li, j) => li.setAttribute('aria-selected', String(j === i)));
    const li = lista.children[i];
    if (li) { input.setAttribute('aria-activedescendant', li.id); li.scrollIntoView?.({ block: 'nearest' }); }
  }

  function elegir(opcion) {
    elegido = opcion;
    input.value = opcion.sku;
    descripcion.value = opcion.descripcion || opcion.modelo || '(sin descripción en el inventario)';
    input.classList.remove('is-invalid');
    if (opcion.alta) aviso('SKU dado de alta sin N/S: no viene en el inventario, así que sus N/S quedarán pendientes de revisión.', 'aviso');
    else aviso(`${opcion.piezas} ${opcion.piezas === 1 ? 'pieza' : 'piezas'} de este SKU en el inventario${opcion.modelo ? ' · Modelo ' + opcion.modelo : ''}.`, 'ok');
    cerrar();
  }

  function pintar() {
    lista.replaceChildren();
    opciones.forEach((o, i) => {
      const li = document.createElement('li');
      li.id = 'sku-op-' + i;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      const sku = document.createElement('span');
      sku.className = 'combo-sku mono';
      sku.textContent = o.sku;
      const texto = document.createElement('span');
      texto.className = 'combo-texto';
      texto.textContent = [o.alta ? 'Alta sin N/S' : null, o.modelo, o.descripcion].filter(Boolean).join(' · ') || '(sin descripción)';
      li.append(sku, texto);
      // mousedown: se elige antes de que el campo pierda el foco.
      li.addEventListener('mousedown', e => { e.preventDefault(); elegir(o); input.focus(); });
      lista.appendChild(li);
    });
    lista.hidden = opciones.length === 0;
    input.setAttribute('aria-expanded', String(!lista.hidden));
    if (opciones.length) marcar(0);
  }

  async function buscar(texto, { mostrarLista = true } = {}) {
    if (!tarima?.value) return;
    const mia = ++consulta;
    const params = new URLSearchParams({ tarima: tarima.value, q: texto || '' });
    let datos;
    try {
      const r = await fetch(`${url}?${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      datos = await r.json();
      if (!r.ok) throw new Error(datos?.message || r.status);
    } catch (e) {
      if (mia === consulta) aviso('No se pudo consultar el inventario. Revisa la conexión.', 'error');
      return null;
    }
    if (mia !== consulta) return null; // llegó tarde: ya hay una consulta más nueva

    hayInventario = datos.inventario;
    input.disabled = !hayInventario;
    if (!hayInventario) {
      opciones = [];
      cerrar();
      aviso(datos.mensaje, 'error');
      return datos;
    }
    opciones = datos.opciones;
    if (mostrarLista) pintar();
    if (!elegido) {
      aviso(opciones.length ? `Inventario del ${datos.almacen}.` : `Ningún SKU del ${datos.almacen} coincide con «${texto}».`, opciones.length ? '' : 'error');
    }
    return datos;
  }

  input.addEventListener('input', () => {
    elegido = null;
    navegando = false;
    descripcion.value = '';
    clearTimeout(reloj);
    reloj = setTimeout(() => buscar(input.value), 200);
  });

  input.addEventListener('focus', () => { if (!input.value) buscar(''); });
  input.addEventListener('blur', () => setTimeout(cerrar, 150));
  abrir?.addEventListener('click', () => { input.focus(); buscar(elegido ? '' : input.value); });

  input.addEventListener('keydown', async e => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      navegando = true;
      if (lista.hidden) { await buscar(input.value); return; }
      const n = opciones.length;
      if (n) marcar((activa + (e.key === 'ArrowDown' ? 1 : -1) + n) % n);
    } else if (e.key === 'Escape') {
      cerrar();
    } else if (e.key === 'Enter') {
      if (navegando && !lista.hidden && activa >= 0 && opciones[activa] && normal(input.value) !== normal(opciones[activa].sku)) {
        // Elegir de la lista sin enviar todavía.
        e.preventDefault();
        elegir(opciones[activa]);
        return;
      }
      // Lectura del escáner o SKU escrito completo: se envía; el servidor lo valida.
      const exacta = opciones.find(o => normal(o.sku) === normal(input.value));
      if (exacta) elegir(exacta);
    }
  });

  tarima?.addEventListener('change', () => {
    elegido = null;
    input.value = '';
    descripcion.value = '';
    opciones = [];
    cerrar();
    buscar('', { mostrarLista: false });
  });

  form.addEventListener('submit', e => {
    if (!hayInventario) { e.preventDefault(); input.focus(); }
  });

  // Estado inicial: ¿hay inventario para el embarque? ¿venía un SKU de un intento anterior?
  (async () => {
    const previo = input.value;
    const datos = await buscar(previo, { mostrarLista: false });
    const exacta = datos?.opciones?.find(o => normal(o.sku) === normal(previo));
    if (previo && exacta) elegir(exacta);
  })();
})();
