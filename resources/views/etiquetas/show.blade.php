@extends('layouts.app')

@section('title', "Etiqueta {$tarima->nombre}")

@section('content')
<main class="page">

  @php
    $origen = $tarima->estaGuardada() ? ['historial', 'Historial'] : ['wms', 'Escaneo'];
  @endphp
  <nav class="breadcrumb" aria-label="Ruta">
    <a href="{{ route($origen[0]) }}">{{ $origen[1] }}</a>
    <x-icon name="chevron-right" />
    <span>{{ $tarima->nombre }}</span>
  </nav>

  <div class="page-head">
    <div>
      <h1 class="page-title">Etiqueta de {{ $tarima->nombre }}</h1>
    </div>
    <a class="btn btn-secondary" href="{{ route($origen[0]) }}"><x-icon name="arrow-left" /> Volver {{ $tarima->estaGuardada() ? 'al historial' : 'al escaneo' }}</a>
  </div>


  <div class="print-layout">

    <section class="card sticky-col">
      <div class="card-head">
        <div>
          <h2 class="card-title"><x-icon name="printer" /> Impresión</h2>
          <p class="card-desc">Destino <b>{{ $destino }}</b> · {{ count($etiquetasSvg) }} {{ Str::plural('etiqueta', count($etiquetasSvg)) }}</p>
        </div>
      </div>
      <div class="card-body">
        <button type="button" class="btn btn-primary btn-lg btn-block" id="btnImprimir"
                data-zpl="{{ route('etiquetas.zpl', $tarima) }}"
                data-impresa="{{ route('tarimas.impresa', $tarima) }}"
                data-diagnostico="{{ route('impresion.diagnostico') }}"
                data-uuid="{{ $tarima->uuid }}">
          <span class="spinner" aria-hidden="true"></span>
          <x-icon name="printer" class="btn-icon" /> <span data-texto>Imprimir etiqueta</span>
        </button>
        <div id="impresionEstado" class="status-box" role="status" aria-live="polite"></div>

        <div id="impresionVerificar" class="verify-box" hidden>
          <p class="verify-title">¿La etiqueta salió completa y legible?</p>
          <p class="verify-text">Revisa que el QR y el código de barras no estén cortados ni borrosos.</p>
          <div class="btn-group">
            <button type="button" class="btn btn-success btn-sm" id="btnConfirmar"><x-icon name="check" /> Sí, se imprimió bien</button>
            <button type="button" class="btn btn-secondary btn-sm" id="btnReintentar">No, imprimir de nuevo</button>
          </div>
        </div>

        <div class="divider">Impresora</div>

        <div class="action-list">
          <button type="button" class="btn btn-secondary btn-block" id="btnBuscar"><x-icon name="search" /> Buscar impresora</button>
          <button type="button" class="btn btn-ghost btn-block" id="btnOlvidar" hidden><x-icon name="x" /> Usar otra impresora Bluetooth</button>
        </div>

        <div class="divider">Otras opciones</div>

        <div class="action-list">
          <a class="btn btn-secondary btn-block" href="{{ route('etiquetas.zpl', ['tarima' => $tarima, 'descargar' => 1]) }}"><x-icon name="download" /> Descargar archivo .zpl</a>
          <button type="button" class="btn btn-ghost btn-block" id="btnNavegador"><x-icon name="printer" /> Imprimir con el navegador</button>
        </div>

        <div class="note">
          <x-icon name="info" class="icon-sm" />
          <p>
            <b>Android / handheld:</b> app Zebra Browser Print<br>
            <b>Computadora:</b> Chrome o Edge se conectan por Bluetooth directamente; por USB, con Browser Print.<br>
          </p>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h2 class="card-title"><x-icon name="tag" /> Vista previa</h2>
          <p class="card-desc">Mismo diseño que se envía a la impresora, a escala.</p>
        </div>
        <span class="badge badge-muted">{{ $soloPadre ? 'SKU padre + QR' : 'Listado de piezas' }}</span>
      </div>
      <div class="card-body">
        <div class="preview-canvas">
          <div id="zonaImpresion">
            @foreach ($etiquetasSvg as $i => $svg)
              <figure class="etiqueta-hoja">
                {!! $svg !!}
                @if (count($etiquetasSvg) > 1)
                  <figcaption>Etiqueta {{ $i + 1 }} de {{ count($etiquetasSvg) }}</figcaption>
                @endif
              </figure>
            @endforeach
          </div>
        </div>
      </div>
    </section>

  </div>
</main>

<style>
  @page { size: {{ $formato->anchoMm() }}mm {{ $formato->altoMm() }}mm; margin: 0; }
  @media print {
    .etiqueta-svg { width: {{ $formato->anchoMm() }}mm !important; height: {{ $formato->altoMm() }}mm !important; max-width: none !important; }
  }
</style>
@endsection

@push('scripts')
<script src="{{ asset('js/impresion.js') }}?v={{ @filemtime(public_path('js/impresion.js')) }}"></script>
@endpush
