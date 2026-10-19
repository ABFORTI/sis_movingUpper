
@php $textoBoton ??= 'Imprimir etiqueta'; @endphp
<button type="button" class="btn btn-primary btn-lg btn-block" id="btnImprimir"
        data-zpl="{{ $zpl }}"
        @isset($impresa) data-impresa="{{ $impresa }}" @endisset
        @isset($uuid) data-uuid="{{ $uuid }}" @endisset
        @isset($configuracion) data-configuracion="{{ $configuracion }}" @endisset
        data-diagnostico="{{ route('impresion.diagnostico') }}"
        data-texto-base="{{ $textoBoton }}">
  <span class="spinner" aria-hidden="true"></span>
  <x-icon name="printer" class="btn-icon" /> <span data-texto>{{ $textoBoton }}</span>
</button>

<div id="impresionEstado" class="status-box" role="status" aria-live="polite"></div>
<div id="impresionProgreso" class="impresion-progreso" hidden>
  <progress max="100" value="0" aria-label="Avance del envío"></progress>
  <button type="button" class="btn btn-secondary btn-sm" id="btnCancelar"><x-icon name="x" /> Cancelar</button>
</div>

<div id="impresionVerificar" class="verify-box" hidden>
  @isset($impresa)
    <p class="verify-title">¿Las etiquetas salieron completas y legibles?</p>
    <p class="verify-text">Revisa que el código de barras no esté cortado ni borroso, que el modelo y el número de embarque se lean y que el contenido empiece arriba.</p>
    <div class="btn-group">
      <button type="button" class="btn btn-success btn-sm" id="btnConfirmar"><x-icon name="check" /> Sí, se imprimió bien</button>
      <button type="button" class="btn btn-secondary btn-sm" id="btnReintentar">No, imprimir de nuevo</button>
    </div>
    <p class="verify-text">¿Sale cortada o corrida hacia abajo? Haz la <a href="{{ route('impresion.prueba') }}">etiqueta de prueba</a> y calibra el papel.</p>
  @else
    <p class="verify-title">Revisa la etiqueta de prueba</p>
    <ul class="verify-lista">
      <li>Se ven los <b>cuatro bordes</b> completos.</li>
      <li>«ARRIBA» está pegado al borde de arriba y «ABAJO» al de abajo.</li>
      <li>Salió en <b>una sola</b> etiqueta, sin pasar a la siguiente.</li>
      <li>Se leen el texto pequeño, el código de barras y el QR.</li>
    </ul>
    <p class="verify-text">Si algo falla, usa «Calibrar papel» abajo y vuelve a imprimir la prueba.</p>
    <div class="btn-group">
      <button type="button" class="btn btn-secondary btn-sm" id="btnReintentar">Imprimir otra prueba</button>
    </div>
  @endisset
</div>

<div class="divider">Impresora</div>

@php $registradas = \App\Models\Impresora::activas()->get(['nombre', 'mac', 'nombre_bluetooth', 'ubicacion']); @endphp
<div class="field" style="margin-top:0;">
  <label for="impresoraEquipo" class="label">Impresora de este equipo</label>
  <select id="impresoraEquipo" class="input">
    <option value="">— Elige la impresora por su MAC —</option>
    @foreach ($registradas as $r)
      <option value="{{ $r->mac }}" data-nombre="{{ $r->nombre }}" data-nombre-bluetooth="{{ $r->nombre_bluetooth }}">
        {{ $r->nombre }} · {{ $r->mac }}{{ $r->ubicacion ? " · {$r->ubicacion}" : '' }}
      </option>
    @endforeach
  </select>
  <p class="hint">
    Se recuerda en este equipo.
    @if ($registradas->isEmpty())
      No hay impresoras registradas: @if (auth()->user()->isAdmin()) <a href="{{ route('impresoras.create') }}">registra la ZQ620 con su MAC</a>. @else pide a un administrador que registre la ZQ620 con su MAC. @endif
    @endif
  </p>
</div>

<p class="impresora-actual" id="impresoraActual" aria-live="polite">Revisando impresora…</p>
<div class="action-list">
  <button type="button" class="btn btn-secondary btn-block" id="btnElegir"><x-icon name="search" /> Conectar por Bluetooth directo (Chrome)</button>
  <button type="button" class="btn btn-ghost btn-block" id="btnBuscar"><x-icon name="search" /> Ver impresoras que detecta Browser Print</button>
  <button type="button" class="btn btn-ghost btn-block" id="btnOlvidar" hidden><x-icon name="x" /> Olvidar conexión Bluetooth directa</button>
</div>
<pre class="impresoras-detectadas" id="impresorasDetectadas" hidden></pre>

<div class="divider">Otras opciones</div>

<div class="action-list">
  @isset($configuracion)
    <button type="button" class="btn btn-secondary btn-block" id="btnCalibrar"><x-icon name="sliders" /> Calibrar papel de la impresora</button>
  @else
    <a class="btn btn-secondary btn-block" href="{{ route('impresion.prueba') }}"><x-icon name="tag" /> Etiqueta de prueba y calibración</a>
  @endisset
  @isset($descargar)
    <a class="btn btn-ghost btn-block" href="{{ $descargar }}"><x-icon name="download" /> Descargar archivo .zpl</a>
  @endisset
  <button type="button" class="btn btn-ghost btn-block" id="btnNavegador"><x-icon name="printer" /> Imprimir con el navegador (solo computadora)</button>
</div>

