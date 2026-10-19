@extends('layouts.app')

@section('title', "Etiquetas {$tarima->nombre}")

@php
  $origen = $volver === 'historial' ? ['historial', 'Historial'] : ['wms', 'Escaneo'];
  $n = $seleccion->count();
  $titulos = ['pendientes' => 'Sin imprimir', 'todas' => 'Todas', 'pieza' => 'Una pieza'];
@endphp

@section('content')
<main class="page">

  <nav class="breadcrumb" aria-label="Ruta">
    <a href="{{ route($origen[0]) }}">{{ $origen[1] }}</a>
    <x-icon name="chevron-right" />
    <span>{{ $tarima->nombre }}</span>
  </nav>

  <div class="page-head">
    <div>
      <h1 class="page-title">Etiquetas del {{ $tarima->nombre }}</h1>
      <p class="page-sub">Una etiqueta por pieza, con su modelo, el número de embarque <b class="mono">{{ $tarima->identificador }}</b> y el código de barras de su N/S.</p>
    </div>
    <a class="btn btn-secondary" href="{{ route($origen[0]) }}"><x-icon name="arrow-left" /> Volver {{ $volver === 'historial' ? 'al historial' : 'al escaneo' }}</a>
  </div>

  <div class="print-layout">

    <section class="card sticky-col">
      <div class="card-head">
        <div>
          <h2 class="card-title"><x-icon name="printer" /> Impresión</h2>
          <p class="card-desc">
            {{ $todas->count() }} {{ Str::plural('pieza', $todas->count()) }} en el embarque ·
            @if ($pendientes->isEmpty())
              todas con etiqueta impresa
            @else
              <b>{{ $pendientes->count() }} sin etiqueta</b>
            @endif
          </p>
        </div>
      </div>
      <div class="card-body">
        <div class="seleccion-etiquetas" role="group" aria-label="Qué etiquetas imprimir">
          @if ($pendientes->isNotEmpty())
            <a href="{{ route('etiquetas.show', $tarima) }}" @class(['btn btn-sm', 'btn-primary' => $modo === 'pendientes', 'btn-secondary' => $modo !== 'pendientes']) @if ($modo === 'pendientes') aria-current="true" @endif>Sin imprimir ({{ $pendientes->count() }})</a>
          @endif
          <a href="{{ route('etiquetas.show', ['tarima' => $tarima, 'ver' => 'todas']) }}" @class(['btn btn-sm', 'btn-primary' => $modo === 'todas', 'btn-secondary' => $modo !== 'todas']) @if ($modo === 'todas') aria-current="true" @endif>Todas ({{ $todas->count() }})</a>
        </div>

        <form method="GET" action="{{ route('etiquetas.show', $tarima) }}" class="field seleccion-pieza">
          <label for="pieza" class="label">Reimprimir una pieza <span class="optional">si su etiqueta se dañó</span></label>
          <div class="input-con-boton">
            <select id="pieza" name="piezas" class="input">
              @foreach ($todas as $ns)
                <option value="{{ $ns->id }}" @selected($modo === 'pieza' && $seleccion->contains('id', $ns->id))>{{ $ns->numero_serie }} · {{ $modelos[$ns->id] }}{{ $ns->etiquetaPendiente() ? ' · sin imprimir' : '' }}</option>
              @endforeach
            </select>
            <button type="submit" class="btn btn-secondary">Elegir</button>
          </div>
        </form>

        <p class="seleccion-resumen" role="status">
          {{ $titulos[$modo] }}: <b>{{ $n }} {{ Str::plural('etiqueta', $n) }}</b>@if ($n === 1) (N/S <span class="mono">{{ $seleccion->first()->numero_serie }}</span>)@endif.
          @if ($modo !== 'pendientes' && $seleccion->contains(fn ($ns) => ! $ns->etiquetaPendiente()))
            Es una reimpresión: pega la nueva sobre la anterior.
          @endif
        </p>

        @include('impresion.partials.panel', [
          'zpl' => route('etiquetas.zpl', ['tarima' => $tarima, 'piezas' => $piezasParam]),
          'impresa' => route('tarimas.impresa', ['tarima' => $tarima, 'piezas' => $piezasParam]),
          'uuid' => $tarima->uuid,
          'descargar' => route('etiquetas.zpl', ['tarima' => $tarima, 'piezas' => $piezasParam, 'descargar' => 1]),
          'textoBoton' => $n === 1 ? 'Imprimir etiqueta' : "Imprimir {$n} etiquetas",
        ])
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h2 class="card-title"><x-icon name="tag" /> Vista previa</h2>
          <p class="card-desc">Mismo diseño que se envía a la impresora, a escala. Pega cada etiqueta en la pieza con ese N/S.</p>
        </div>
        <span class="badge badge-muted">Una por pieza</span>
      </div>
      <div class="card-body">
        <div class="preview-canvas">
          <div id="zonaImpresion">
            @foreach ($seleccion as $i => $ns)
              <figure class="etiqueta-hoja">
                {!! $etiquetasSvg[$ns->id] !!}
                <figcaption>
                  {{ $i + 1 }} de {{ $n }} · N/S <span class="mono">{{ $ns->numero_serie }}</span>
                  · {{ $ns->etiquetaPendiente() ? 'sin imprimir' : 'impresa el '.$ns->etiqueta_impresa_at->format('d/m/Y H:i') }}
                </figcaption>
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
