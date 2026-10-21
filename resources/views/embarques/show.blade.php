@extends('layouts.app')

@section('title', "Tráiler {$embarque->folio}")

@section('content')
@php
  $supervision = auth()->user()->esSupervision();
  $escaneo = match (true) {
      $embarque->enCarga() => ['ruta' => route('embarques.cargar', $embarque), 'titulo' => 'Cargar', 'ayuda' => 'Escanea el código de barras de cada pieza al subirla (es su N/S). También puedes escribir el número de embarque (E-004) para subir todas sus piezas.'],
      $embarque->enTransito() => ['ruta' => route('embarques.recibir', $embarque), 'titulo' => 'Recibir', 'ayuda' => 'Escanea el código de barras de cada pieza al bajarla en el destino (es su N/S). El número de embarque (E-004) recibe todas sus piezas.'],
      default => null,
  };
@endphp
<main class="page page-narrow">

  <nav class="breadcrumb" aria-label="Ruta">
    <a href="{{ route('embarques.index', ['mudanza' => $embarque->mudanza_id]) }}">Tráileres</a>
    <x-icon name="chevron-right" />
    <span>{{ $embarque->folio }}</span>
  </nav>

  <div class="page-head">
    <div style="min-width:0;">
      <p class="eyebrow">Tráiler <span class="mono">{{ $embarque->folio }}</span> · {{ $embarque->mudanza->folio }}@if ($embarque->movimiento) · Almacén <span class="mono">{{ $embarque->movimiento->nombre }}</span>@endif</p>
      <h1 class="page-title"><span class="mono">{{ $embarque->placas }}</span>{{ $embarque->remolque ? " · {$embarque->remolque}" : '' }}</h1>
      <div class="btn-group" style="margin-top:8px;">
        <span @class(['badge', 'badge-warn' => $embarque->enCarga(), 'badge-success' => $embarque->estado === 'recibido'])>{{ $embarque->estado_nombre }}</span>
        <span class="badge badge-muted">{{ number_format($total) }} {{ Str::plural('pieza', $total) }}</span>
        @unless ($embarque->enCarga())
          <span class="badge badge-muted">{{ number_format($recibidas) }} recibidas</span>
        @endunless
      </div>
    </div>
  </div>

  @if ($escaneo)
    <section class="card" data-anden
             data-modo="{{ $embarque->enCarga() ? 'cargar' : 'recibir' }}"
             data-clave="embarque-{{ $embarque->id }}-{{ $embarque->estado }}">
      <form method="POST" action="{{ $escaneo['ruta'] }}" class="card-body" data-anden-form>
        @csrf
        <div class="field" style="margin-top:0;">
          <label for="codigo" class="label">{{ $escaneo['titulo'] }} <span class="optional">N/S de la pieza, número de embarque o SKU</span></label>
          <div class="control">
            <x-icon name="scan" />
            <input id="codigo" name="codigo" placeholder="Escanea la etiqueta de la pieza"
                   @class(['input', 'input-lg', 'input-mono', 'is-invalid' => $errors->has('codigo')])
                   autofocus autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="go" data-escaneo data-aviso-ok>
            <span class="kbd">↵</span>
            @include('wms.partials.teclado')
          </div>
          @error('codigo') <p class="field-error" data-error-escaneo><x-icon name="alert" /> {{ $message }}</p> @enderror
          <p class="hint">{{ $escaneo['ayuda'] }}</p>
        </div>


        <div class="anden-estado" data-anden-estado role="status" aria-live="assertive" hidden>
          <span class="anden-estado-icono" data-icono aria-hidden="true"></span>
          <div style="min-width:0;">
            <p class="anden-estado-titulo" data-titulo></p>
            <p class="anden-estado-texto" data-texto></p>
          </div>
        </div>
        <div class="anden-acciones" data-anden-acciones hidden></div>

        <div class="anden-pendientes" data-anden-pendientes hidden>
          <x-icon name="clock" class="icon-sm" />
          <span data-texto></span>
          <button type="button" class="btn btn-secondary btn-sm" data-reintentar>Reintentar ahora</button>
        </div>

        <ol class="anden-log" data-anden-log aria-label="Últimos escaneos"></ol>
      </form>
    </section>
  @endif

  <section class="card">
    <div class="card-head">
      <div>
        <h2 class="card-title"><x-icon name="package" /> Embarques {{ $embarque->enCarga() ? 'cargados' : 'en el tráiler' }}</h2>
        <p class="card-desc"><span data-anden-total-tarimas>{{ $tarimas->count() }}</span> embarques · <span data-anden-total>{{ number_format($total) }}</span> piezas @unless ($embarque->enCarga()) ·<span data-anden-recibidas>{{ number_format($recibidas) }}</span> recibidas @endunless</p>
      </div>
      <a class="btn btn-secondary btn-sm" href="{{ route('embarques.manifiesto', $embarque) }}"><x-icon name="sheet" /> Manifiesto</a>
    </div>
    <div class="empty" data-anden-vacio @unless ($tarimas->isEmpty()) hidden @endunless>
      <div class="empty-icon"><x-icon name="truck" class="icon-xl" /></div>
      <p class="empty-title">El tráiler está vacío</p>
    </div>
    <ul class="lista-embarque" data-anden-lista>
      @foreach ($tarimas as $fila)
        @php $completa = $fila['recibidas'] === $fila['piezas']; @endphp
        <li data-tarima-id="{{ $fila['tarima']->id }}" @class(['is-recibida' => ! $embarque->enCarga() && $completa])>
          <div style="min-width:0;">
            <b>{{ $fila['tarima']->codigo }}</b>
            <span class="hint"><span data-piezas>{{ $fila['piezas'] }}</span> piezas · {{ $fila['cargada_at']->format('H:i') }}</span>
          </div>
          @if ($embarque->enCarga())
            <form method="POST" action="{{ route('embarques.bajar', [$embarque, $fila['tarima']]) }}" data-motivo="¿Bajar el embarque {{ $fila['tarima']->codigo }} del tráiler?">
              @csrf
              <input type="hidden" name="motivo">
              <button type="submit" class="btn btn-ghost btn-sm"><x-icon name="x" /> Bajar</button>
            </form>
          @elseif ($completa)
            <span class="badge badge-success" data-progreso><x-icon name="check" class="icon-sm" /> Recibido</span>
          @else
            <span class="badge badge-warn" data-progreso>{{ $fila['recibidas'] }} de {{ $fila['piezas'] }}</span>
          @endif
        </li>
      @endforeach
    </ul>
  </section>

  <section class="card">
    <div class="card-body">
      <div class="meta-grid" style="margin-top:0;">
        <div class="meta-item"><span><x-icon name="user" /> Creó</span><b>{{ $embarque->creadoPor?->name }}</b><small class="hint">{{ $embarque->created_at->format('d/m/Y H:i') }}</small></div>
        <div class="meta-item"><span><x-icon name="truck" /> Salida</span><b>{{ $embarque->salida_at?->format('d/m/Y H:i') ?? 'Pendiente' }}</b><small class="hint">{{ $embarque->salidaPor?->name }}{{ $embarque->sello ? " · sello {$embarque->sello}" : '' }}</small></div>
        <div class="meta-item"><span><x-icon name="check-circle" /> Recepción</span><b>{{ $embarque->recibido_at?->format('d/m/Y H:i') ?? 'Pendiente' }}</b><small class="hint">{{ $embarque->recibidoPor?->name }}</small></div>
      </div>
      @if ($embarque->chofer)<p class="hint" style="margin-top:12px;">Chofer: {{ $embarque->chofer }}</p>@endif
      @if ($embarque->notas)<p class="incidencia-texto">{{ $embarque->notas }}</p>@endif
    </div>
  </section>

  @if ($supervision && $embarque->enCarga() && $total > 0)
    <section class="card">
      <div class="card-head"><div><h2 class="card-title"><x-icon name="shield" /> Salida</h2><p class="card-desc">Antes de salir se vuelve a revisar cada pieza: que siga en un embarque vigente y sin incidencias que bloqueen su traslado.</p></div></div>
      <form method="POST" action="{{ route('embarques.despachar', $embarque) }}" class="card-body" data-loading
            onsubmit="return confirm('¿Registrar la salida del tráiler {{ $embarque->folio }} con {{ $total }} piezas? Ya no se podrá cargar ni bajar nada.')">
        @csrf
        <div class="field" style="margin-top:0;">
          <label for="sello" class="label">Sello</label>
          <input id="sello" name="sello" value="{{ old('sello') }}" required maxlength="50" autocapitalize="characters" @class(['input', 'input-mono', 'is-invalid' => $errors->has('sello')])>
          @error('sello') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
        </div>
        <div class="field">
          <label for="chofer" class="label">Chofer</label>
          <input id="chofer" name="chofer" value="{{ old('chofer', $embarque->chofer) }}" maxlength="150" class="input"
                 autocomplete="off" @if (! empty($sugerencias['chofer'])) list="sug-chofer" @endif>
          @include('embarques.partials.sugerencias', ['id' => 'sug-chofer', 'valores' => $sugerencias['chofer'] ?? []])
        </div>
        @error('salida') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
        <button type="submit" class="btn btn-primary btn-block" style="margin-top:12px;">
          <span class="spinner" aria-hidden="true"></span><x-icon name="truck" class="btn-icon" /> Registrar salida
        </button>
      </form>
    </section>
  @endif

  @if ($supervision && $embarque->enTransito())
    <section class="card">
      <div class="card-head"><div><h2 class="card-title"><x-icon name="check-circle" /> Cerrar recepción</h2>
        <p class="card-desc">{{ $total - $recibidas ? ($total - $recibidas).' piezas sin escanear: al cerrar quedan como «Faltante en recepción» en el manifiesto y el reporte final.' : 'Llegó todo lo cargado.' }}</p></div></div>
      <form method="POST" action="{{ route('embarques.cerrar', $embarque) }}" class="card-body"
            onsubmit="return confirm('¿Cerrar la recepción del tráiler {{ $embarque->folio }}?{{ $total - $recibidas ? ' Las '.($total - $recibidas).' piezas sin escanear quedarán como faltante.' : '' }}')">
        @csrf
        <button type="submit" @class(['btn', 'btn-block', 'btn-success' => $total === $recibidas, 'btn-danger-soft' => $total !== $recibidas])>
          <x-icon name="check" class="btn-icon" /> Cerrar recepción
        </button>
      </form>
    </section>
  @endif

</main>
<template id="tpl-fila-tarima">
  <li>
    <div style="min-width:0;"><b data-codigo></b> <span class="hint"><span data-piezas></span> piezas · <span data-hora></span></span></div>
    <form method="POST" data-motivo>
      @csrf
      <input type="hidden" name="motivo">
      <button type="submit" class="btn btn-ghost btn-sm"><x-icon name="x" /> Bajar</button>
    </form>
  </li>
</template>
<script src="{{ asset('js/escaneo.js') }}?v={{ @filemtime(public_path('js/escaneo.js')) }}"></script>
<script src="{{ asset('js/anden.js') }}?v={{ @filemtime(public_path('js/anden.js')) }}"></script>
@endsection
