@extends('layouts.app')

@php
  use App\Enums\TipoRegistro;
  $totalPiezas = $tarimasConProductos->sum(fn ($t) => $t->piezas());
@endphp

@section('title', 'Escaneo')

@section('content')
<main class="page">
  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="scan" class="icon-sm" />Operación</p>
      <h1 class="page-title">Escaneo de material</h1>
      <p class="page-sub">Captura productos, asígnalos a una tarima y genera su etiqueta y reporte.</p>
    </div>
    <form method="POST" action="{{ route('tarimas.store') }}" data-loading>
      @csrf
      <button type="submit" class="btn btn-accent">
        <span class="spinner" aria-hidden="true"></span>
        <x-icon name="plus" class="btn-icon" /> Nueva tarima
      </button>
    </form>
  </div>

  <section class="stats" aria-label="Resumen">
    <div class="stat">
      <span class="tile"><x-icon name="layers" /></span>
      <div style="min-width:0;">
        <p class="stat-label">Tarimas</p>
        <p class="stat-value">{{ $tarimas->count() }} <small>{{ $tarimasConProductos->count() }} con carga</small></p>
      </div>
    </div>
    <div class="stat">
      <span class="tile tile-success"><x-icon name="package" /></span>
      <div style="min-width:0;">
        <p class="stat-label">Piezas escaneadas</p>
        <p class="stat-value">{{ number_format($totalPiezas) }}</p>
      </div>
    </div>
    {{-- En handheld se ocultan: el SKU activo y el modo ya se ven en la tarjeta de captura. --}}
    <div class="stat stat-opcional">
      <span @class(['tile', 'tile-warn' => $skuActivo, 'tile-muted' => ! $skuActivo])><x-icon name="{{ $skuActivo ? 'unlock' : 'lock' }}" /></span>
      <div style="min-width:0;">
        <p class="stat-label">SKU padre activo</p>
        <p @class(['stat-value', 'mono' => $skuActivo])>{{ $skuActivo?->sku ?? '—' }}</p>
      </div>
    </div>
    <div class="stat stat-opcional">
      <span class="tile tile-ink"><x-icon name="sliders" /></span>
      <div style="min-width:0;">
        <p class="stat-label">Modo actual</p>
        <p class="stat-value" style="font-size:18px;">{{ $modo === 1 ? 'SKU + N/S' : 'Solo SKUs' }}</p>
      </div>
    </div>
  </section>

  <div class="workspace">
    <div class="sticky-col">
      <section class="card">
        <div class="card-head">
          <div>
            <h2 class="card-title"><span class="step">1</span> Captura</h2>
            <p class="card-desc">Elige el modo y escanea. <b>Enter</b> agrega el registro.</p>
          </div>
        </div>

        <div class="card-body">
          <div class="segmented" role="group" aria-label="Modo de operación">
            @foreach ([1 => ['tag', 'SKU + N/S'], 2 => ['package', 'Solo SKUs']] as $valor => [$icono, $texto])
              <form method="POST" action="{{ route('wms.modo') }}"
                    @if ($skuActivo && $modo !== $valor) onsubmit="return confirm('Hay un SKU padre activo. ¿Cerrarlo y cambiar de modo?')" @endif>
                @csrf
                <input type="hidden" name="modo" value="{{ $valor }}">
                <button type="submit" @class(['segmented-btn', 'is-active' => $modo === $valor]) aria-pressed="{{ $modo === $valor ? 'true' : 'false' }}">
                  <x-icon name="{{ $icono }}" class="icon-sm" /> {{ $texto }}
                </button>
              </form>
            @endforeach
          </div>

          @if ($tarimas->isEmpty())
            <div class="alert alert-info" style="margin-top:16px;">
              <x-icon name="info" />
              <div class="alert-body">
                <p class="alert-title">Crea tu primera tarima</p>
                <p class="alert-text">Necesitas al menos una tarima para empezar a escanear.</p>
              </div>
            </div>
          @endif

          @if ($modo === 1)
            @unless ($skuActivo)
            <form method="POST" action="{{ route('escaneo.padre') }}" style="margin-top:20px;">
              @csrf
              <fieldset>
                <div class="field">
                  <label for="sku" class="label">SKU del modelo <span class="optional">se escanea una vez</span></label>
                  <div class="control">
                    <x-icon name="scan" />
                    <input id="sku" name="sku" value="{{ old('sku') }}" placeholder="Ej. 2364F"
                           @class(['input', 'input-lg', 'input-mono', 'is-invalid' => $errors->has('sku')])
                           autofocus autocomplete="off" spellcheck="false">
                    <span class="kbd">↵</span>
                  </div>
                  @error('sku') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
                </div>

                <div class="field">
                  <label for="descripcion" class="label">Descripción del modelo <span class="optional">opcional</span></label>
                  <input id="descripcion" name="descripcion" value="{{ old('descripcion') }}"
                         placeholder="Ej. Bomba hidráulica" class="input">
                </div>

                <div style="margin-top:16px;">@include('wms.partials.selects')</div>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top:18px;">
                  <x-icon name="unlock" /> Activar SKU padre
                </button>
              </fieldset>
            </form>
            @else
              <div class="active-sku" role="status" data-sku-activo-tarima="{{ $skuActivo->tarima->uuid }}">
                <div class="active-sku-top">
                  <span class="live"><span class="live-dot"></span> SKU activo</span>
                  <form method="POST" action="{{ route('escaneo.padre.cerrar') }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="x" /> Cerrar SKU</button>
                  </form>
                </div>
                <p class="active-sku-code">{{ $skuActivo->sku }}</p>
                <div class="active-sku-meta">
                  <span><x-icon name="layers" /> {{ $skuActivo->tarima->nombre }}</span>
                  <span><x-icon name="map-pin" /> {{ $skuActivo->destino->codigo }}</span>
                </div>
                <div class="active-sku-count">
                  <b>{{ $skuActivo->numeros_serie_count }}</b>
                  <small>N/S</small>
                </div>
              </div>
            @endunless

            <div class="divider">Números de serie</div>

            <form method="POST" action="{{ route('escaneo.ns') }}">
              @csrf
              <div class="field">
                <label for="numero_serie" class="label">N/S de la pieza <span class="optional">escanea las veces necesarias</span></label>
                <div class="control">
                  <x-icon name="hash" />
                  <input id="numero_serie" name="numero_serie" placeholder="{{ $skuActivo ? 'Ej. SN-000123' : 'Activa primero un SKU padre' }}"
                         @class(['input', 'input-lg', 'input-mono', 'is-invalid' => $errors->has('numero_serie')])
                         autocomplete="off" spellcheck="false"
                         @disabled(! $skuActivo) @if ($skuActivo) autofocus @endif>
                  <span class="kbd">↵</span>
                </div>
                @error('numero_serie') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
              </div>
              <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;" @disabled(! $skuActivo)>
                <x-icon name="plus" /> Agregar N/S
              </button>
            </form>
          @else
            <form method="POST" action="{{ route('escaneo.simple') }}" style="margin-top:20px;">
              @csrf
              <div class="field">
                <label for="sku" class="label">SKU / N/S escaneado</label>
                <div class="control">
                  <x-icon name="scan" />
                  <input id="sku" name="sku" value="{{ old('sku') }}" placeholder="Ej. SKU-10023"
                         @class(['input', 'input-lg', 'input-mono', 'is-invalid' => $errors->has('sku')])
                         autofocus autocomplete="off" spellcheck="false">
                  <span class="kbd">↵</span>
                </div>
                @error('sku') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
              </div>

              <div class="field">
                <label for="descripcion" class="label">Descripción <span class="optional">opcional</span></label>
                <input id="descripcion" name="descripcion" value="{{ old('descripcion') }}" placeholder="Ej. Caja tornillos M8" class="input">
              </div>

              <div style="margin-top:16px;">@include('wms.partials.selects')</div>

              <button type="submit" class="btn btn-primary btn-block" style="margin-top:18px;">
                <x-icon name="plus" /> Agregar escaneo
              </button>
            </form>
          @endif
        </div>
      </section>
    </div>

    <div>
      <div class="section-head">
        <h2 class="section-title"><span class="step">2</span> Tarimas</h2>
        <span class="badge badge-muted">{{ $tarimasConProductos->count() }} con productos</span>
      </div>

      <div class="pallets">
        @forelse ($tarimasConProductos as $tarima)
          <article class="pallet" data-tarima="{{ $tarima->uuid }}">
            <header class="pallet-head">
              <span class="tile"><x-icon name="layers" /></span>
              <div style="min-width:0;">
                <h3 class="pallet-title">{{ $tarima->nombre }}</h3>
                <p class="pallet-sub">{{ $tarima->registros->count() }} {{ Str::plural('registro', $tarima->registros->count()) }}</p>
              </div>
              <span class="badge"><x-icon name="package" class="icon-sm" /> {{ $tarima->piezas() }} {{ Str::plural('pieza', $tarima->piezas()) }}</span>
            </header>

            <div class="pallet-body">
              @foreach ($tarima->registros as $registro)
                @if ($registro->tipo === TipoRegistro::Padre)
                  <div class="line line-parent">
                    <div class="line-top">
                      <x-icon name="tag" class="icon-sm" style="color:var(--warn-600)" />
                      <span class="code">{{ $registro->sku }}</span>
                      <span class="dest"><x-icon name="map-pin" class="icon-sm" style="width:11px;height:11px" />{{ $registro->destino->codigo }}</span>
                      <span class="badge badge-warn">{{ $registro->numerosSerie->count() }} N/S</span>
                    </div>
                    <p class="line-desc" style="margin-top:4px;">{{ $registro->descripcion_mostrada }}</p>
                    @if ($registro->numerosSerie->isEmpty())
                      <p class="line-note">Sin números de serie capturados aún.</p>
                    @else
                      <div class="chips">
                        @foreach ($registro->numerosSerie as $ns)
                          <span class="chip">{{ $ns->numero_serie }}</span>
                        @endforeach
                      </div>
                    @endif
                  </div>
                @else
                  <div class="line line-simple">
                    <span class="code">{{ $registro->sku }}</span>
                    <span class="line-desc">{{ $registro->descripcion_mostrada }}</span>
                    <span class="dest"><x-icon name="map-pin" class="icon-sm" style="width:11px;height:11px" />{{ $registro->destino->codigo }}</span>
                  </div>
                @endif
              @endforeach
            </div>

            <footer class="pallet-foot">
              <a class="btn btn-secondary btn-sm" href="{{ route('etiquetas.show', $tarima) }}"><x-icon name="printer" /> Etiqueta</a>
              <a class="btn btn-secondary btn-sm" href="{{ route('export.tarima', $tarima) }}"><x-icon name="sheet" /> Excel</a>
              <span class="spacer"></span>
              @include('wms.partials.borrar', ['tarima' => $tarima])
              @include('wms.partials.guardar', ['tarima' => $tarima])
            </footer>
          </article>
        @empty
          <div class="card">
            <div class="empty">
              <div class="empty-icon"><x-icon name="inbox" class="icon-xl" /></div>
              <p class="empty-title">Aún no hay productos escaneados</p>
              <p class="empty-text">Escanea tu primer SKU en el panel de captura y aparecerá aquí agrupado por tarima.</p>
            </div>
          </div>
        @endforelse

        @php $vacias = $tarimas->diff($tarimasConProductos); @endphp
        @if ($vacias->isNotEmpty())
          <div class="empty-pallets">
            <x-icon name="inbox" class="icon-sm" />
            <span>Tarimas vacías: <b>{{ $vacias->pluck('nombre')->join(', ') }}</b></span>
            @if (auth()->user()->isAdmin())
              <span class="btn-group" style="margin-left:auto;">
                @foreach ($vacias as $tarima)
                  @include('wms.partials.borrar', ['tarima' => $tarima, 'soloEliminar' => true])
                @endforeach
              </span>
            @endif
          </div>
        @endif
      </div>
    </div>
  </div>
</main>
@endsection

@push('scripts')
<template id="tpl-alerta-success">
  <div class="alert alert-success" data-alert>
    <x-icon name="check-circle" />
    <div class="alert-body"><p class="alert-title"></p></div>
    <button type="button" class="alert-close" aria-label="Cerrar"><x-icon name="x" class="icon-sm" /></button>
  </div>
</template>
<template id="tpl-alerta-danger">
  <div class="alert alert-danger" data-alert role="alert">
    <x-icon name="alert" />
    <div class="alert-body"><p class="alert-title"></p></div>
    <button type="button" class="alert-close" aria-label="Cerrar"><x-icon name="x" class="icon-sm" /></button>
  </div>
</template>
<script src="{{ asset('js/tarimas.js') }}?v={{ @filemtime(public_path('js/tarimas.js')) }}"></script>
@endpush
