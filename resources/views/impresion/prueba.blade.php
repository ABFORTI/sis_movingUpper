@extends('layouts.app')

@section('title', 'Prueba de impresión')

@section('content')
<main class="page">

  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="printer" class="icon-sm" /> Impresora Zebra</p>
      <h1 class="page-title">Prueba de impresión</h1>
      <p class="page-sub">Elige la impresora, imprime esta etiqueta y revisa que salga completa. No afecta ningún embarque.</p>
    </div>
  </div>

  <div class="print-layout">
    <section class="card sticky-col">
      <div class="card-head">
        <div>
          <h2 class="card-title"><x-icon name="printer" /> Impresión</h2>
          <p class="card-desc">Etiqueta configurada: <b>{{ $formato->medidas() }}</b>, {{ $formato->dpi }} dpi, papel {{ $formato->media }}.</p>
        </div>
      </div>
      <div class="card-body">
        @include('impresion.partials.panel', [
          'zpl' => route('impresion.prueba.zpl'),
          'configuracion' => route('impresion.configuracion.zpl'),
          'textoBoton' => 'Imprimir etiqueta de prueba',
        ])
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h2 class="card-title"><x-icon name="tag" /> Así debe salir</h2>
          <p class="card-desc">Mismo diseño que se envía a la impresora, a escala.</p>
        </div>
      </div>
      <div class="card-body">
        <div class="preview-canvas">
          <div id="zonaImpresion">
            <figure class="etiqueta-hoja">{!! $svg !!}</figure>
          </div>
        </div>
        <div class="note" style="margin-top:16px;">
          <x-icon name="info" class="icon-sm" />
          <p>
            <b>Sale corrida hacia abajo, cortada o en dos etiquetas:</b> la impresora no tiene medido el papel.
            Usa «Calibrar papel» (avanza una o dos etiquetas en blanco) o, en Zebra Printer Setup,
            <i>Configuración de medios → Tipo: etiqueta con espacio (gap) → Calibrar</i>. Luego imprime otra prueba.<br>
            <b>Sale completa pero un poco alta o baja</b> aun después de calibrar: pide a sistemas ajustar
            <code>ZEBRA_AJUSTE_SUPERIOR_MM</code> (negativo sube, positivo baja).
          </p>
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
