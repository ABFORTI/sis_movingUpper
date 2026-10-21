(function () {
  'use strict';

  const boton = document.querySelector('[data-captura-foto]');
  const archivo = document.querySelector('[data-foto-archivo]');
  const estado = document.querySelector('[data-foto-estado]');
  if (!boton || !archivo || !estado || !window.fetch || !window.FormData) return;

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const textoBoton = boton.querySelector('[data-foto-texto]');
  const textoInicial = textoBoton?.textContent || '';
  const campoNs = document.getElementById('numero_serie');
  const campoSku = document.querySelector('[data-sku-input]');
  const skuActivo = document.querySelector('.active-sku-code')?.textContent.trim() || null;

  const LADO_MAXIMO = 1600;
  const CLAVE_PENDIENTE = 'ocr-ns-pendiente';
  const VIGENCIA_MS = 10 * 60 * 1000;

  const sesion = {
    leer() { try { return JSON.parse(sessionStorage.getItem(CLAVE_PENDIENTE) || 'null'); } catch (e) { return null; } },
    guardar(v) { try { sessionStorage.setItem(CLAVE_PENDIENTE, JSON.stringify(v)); } catch (e) { /* sin almacenamiento */ } },
    borrar() { try { sessionStorage.removeItem(CLAVE_PENDIENTE); } catch (e) { /* sin almacenamiento */ } },
  };
  const igual = (a, b) => !!a && !!b && a.trim().toUpperCase() === b.trim().toUpperCase();

  function mostrar(tipo, texto) {
    estado.hidden = !texto;
    estado.dataset.tipo = tipo;
    estado.textContent = texto || '';
  }

  function ocupado(si) {
    boton.disabled = si;
    boton.setAttribute('aria-busy', String(si));
    if (textoBoton) textoBoton.textContent = si ? 'Leyendo etiqueta…' : textoInicial;
  }


  async function reducir(foto) {
    try {
      const imagen = await createImageBitmap(foto, { imageOrientation: 'from-image' });
      const escala = Math.min(1, LADO_MAXIMO / Math.max(imagen.width, imagen.height));
      const lienzo = document.createElement('canvas');
      lienzo.width = Math.round(imagen.width * escala);
      lienzo.height = Math.round(imagen.height * escala);
      lienzo.getContext('2d').drawImage(imagen, 0, 0, lienzo.width, lienzo.height);
      imagen.close?.();
      const blob = await new Promise(r => lienzo.toBlob(r, 'image/jpeg', 0.85));
      return blob ? new File([blob], 'etiqueta.jpg', { type: 'image/jpeg' }) : foto;
    } catch (e) {
      return foto;
    }
  }

  function enfocar(campo) {
    if (!campo || campo.disabled) return;
    campo.focus({ preventScroll: true });
    campo.scrollIntoView?.({ block: 'center' });
    try { campo.setSelectionRange(campo.value.length, campo.value.length); } catch (e) { /* tipo sin selección */ }
  }

  function aplicar(datos) {
    const avisos = (datos.avisos || []).join(' ');
    const sku = datos.sku || datos.modelo;

    if (skuActivo && campoNs && !campoNs.disabled) {
      if (datos.numero_serie) {
        campoNs.value = datos.numero_serie;
        enfocar(campoNs);
        mostrar(avisos ? 'aviso' : 'ok', `N/S leída: ${datos.numero_serie}. Revísala y toca «Agregar N/S».${avisos ? ' ' + avisos : ''}`);
      } else {
        mostrar('error', avisos || 'No se leyó la N/S.');
      }
      return;
    }

    if (campoSku && !campoSku.disabled && sku) {
      campoSku.value = sku;
      campoSku.dispatchEvent(new Event('input', { bubbles: true })); // busca en el inventario
      enfocar(campoSku);
    }
    if (datos.numero_serie) sesion.guardar({ ns: datos.numero_serie, sku, t: Date.now() });
    else sesion.borrar();

    const partes = [];
    if (sku) partes.push(`SKU leído: ${sku}. Elígelo de la lista y toca «Activar SKU».`);
    if (datos.numero_serie) partes.push(`La N/S ${datos.numero_serie} se propondrá al activarlo.`);
    if (avisos) partes.push(avisos);
    mostrar(sku ? (avisos ? 'aviso' : 'ok') : 'error', partes.join(' ') || 'No se leyó ningún dato.');
  }

  async function leer(foto) {
    ocupado(true);
    mostrar('aviso', 'Leyendo la etiqueta…');
    const cuerpo = new FormData();
    cuerpo.append('imagen', await reducir(foto));
    try {
      const respuesta = await fetch(boton.dataset.url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
        body: cuerpo,
      });
      const datos = await respuesta.json().catch(() => null);
      if (respuesta.ok && datos) aplicar(datos);
      else if (respuesta.status === 419) mostrar('error', 'Tu sesión expiró. Recarga la página e inicia sesión de nuevo.');
      else if (respuesta.status === 429 && !datos?.message?.includes('límite')) mostrar('error', 'Demasiadas fotos seguidas. Espera un minuto o escribe la N/S a mano.');
      else mostrar('error', datos?.errors?.imagen?.[0] || datos?.message || 'No se pudo leer la foto. Escribe la N/S a mano.');
    } catch (e) {
      mostrar('error', 'Sin conexión: no se pudo leer la foto. Escribe la N/S a mano.');
    } finally {
      ocupado(false);
      archivo.value = '';
    }
  }

  boton.addEventListener('click', () => archivo.click());
  archivo.addEventListener('change', () => {
    const foto = archivo.files?.[0];
    if (foto) leer(foto);
  });

  const pendiente = sesion.leer();
  if (pendiente && skuActivo) {
    sesion.borrar();
    const vigente = Date.now() - (pendiente.t || 0) < VIGENCIA_MS;
    if (vigente && pendiente.ns && campoNs && !campoNs.disabled && !campoNs.value) {
      campoNs.value = pendiente.ns;
      enfocar(campoNs);
      if (!pendiente.sku || igual(pendiente.sku, skuActivo)) {
        mostrar('ok', `N/S leída de la foto: ${pendiente.ns}. Revísala y toca «Agregar N/S».`);
      } else {
        mostrar('aviso', `N/S leída de la foto: ${pendiente.ns}. La etiqueta decía ${pendiente.sku} y el SKU activo es ${skuActivo}: confirma que es la pieza correcta antes de agregar.`);
      }
    }
  }
})();
