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
      <p class="page-sub">
        @if ($mudanza)
          Mudanza <b>{{ $mudanza->titulo }}</b> · {{ $mudanza->rutas }}
        @else
          Crea un embarque, escanea la N/S de cada pieza e imprime su etiqueta.
        @endif
      </p>
    </div>
    <form method="POST" action="{{ route('tarimas.store') }}" class="nueva-tarima" data-loading>
      @csrf
      @if ($movimientos->count() > 1)
        <label for="movimiento" class="sr-only">Movimiento del nuevo embarque</label>
        <select id="movimiento" name="movimiento" required @class(['input', 'is-invalid' => $errors->has('movimiento')]) @disabled(! $mudanza?->permiteCaptura())>
          <option value="">Movimiento…</option>
          @foreach ($movimientos as $m)
            <option value="{{ $m->id }}" @selected($movimientoSeleccionado === $m->id)>Almacén {{ $m->nombre }}</option>
          @endforeach
        </select>
      @endif
      <button type="submit" class="btn btn-accent" @disabled(! $mudanza?->permiteCaptura())>
        <span class="spinner" aria-hidden="true"></span>
        <x-icon name="plus" class="btn-icon" /> Nuevo embarque
      </button>
    </form>
  </div>

  @unless ($mudanza?->permiteCaptura())
    <div class="alert alert-warn">
      <x-icon name="alert" />
      <div class="alert-body">
        <p class="alert-title">{{ $mudanza ? "La {$mudanza->folio} está {$mudanza->estado_nombre}" : 'No hay una mudanza activa' }}</p>
        <p class="alert-text">
          @if ($mudanza?->permiteCerrarTarimas())
            Ya no se crean embarques ni se escanea; solo se imprimen etiquetas y se guardan o anulan los que quedaron en captura.
          @else
            No se pueden crear embarques ni escanear. {{ auth()->user()->esSupervision() ? 'Actívala desde Mudanzas.' : 'Avisa a supervisión.' }}
          @endif
        </p>
      </div>
    </div>
  @endunless

  <section class="stats" aria-label="Resumen">
    <div class="stat">
      <span class="tile"><x-icon name="layers" /></span>
      <div style="min-width:0;">
        <p class="stat-label">Embarques</p>
        <p class="stat-value">{{ $tarimas->count() }} <small>{{ $tarimasConProductos->count() }} con piezas</small></p>
      </div>
    </div>
    <div class="stat">
      <span class="tile tile-success"><x-icon name="package" /></span>
      <div style="min-width:0;">
        <p class="stat-label">Piezas escaneadas</p>
        <p class="stat-value">{{ number_format($totalPiezas) }}</p>
      </div>
    </div>
    <div class="stat stat-opcional">
      <span @class(['tile', 'tile-warn' => $skuActivo, 'tile-muted' => ! $skuActivo])><x-icon name="{{ $skuActivo ? 'unlock' : 'lock' }}" /></span>
      <div style="min-width:0;">
        <p class="stat-label">SKU activo</p>
        <p @class(['stat-value', 'mono' => $skuActivo])>{{ $skuActivo?->sku ?? '—' }}</p>
      </div>
    </div>
  </section>

  <div class="workspace">
    <div class="sticky-col">
      <section class="card card-captura">
        <div class="card-head">
          <div>
            <h2 class="card-title"><span class="step">1</span> Captura</h2>
            <p class="card-desc">Elige el SKU del inventario y registra cada N/S. <b>Enter</b> agrega.</p>
          </div>
        </div>

        <div class="card-body">
          @if ($tarimas->isEmpty())
            <div class="alert alert-info">
              <x-icon name="info" />
              <div class="alert-body">
                <p class="alert-title">Crea tu primer embarque</p>
                <p class="alert-text">Necesitas al menos un embarque creado para empezar a escanear.</p>
              </div>
            </div>
          @endif

          @unless ($skuActivo)
            <form method="POST" action="{{ route('escaneo.padre') }}" data-sku-form>
              @csrf
              <fieldset>
                @include('wms.partials.selects')

                <div class="field" style="margin-top:16px;" data-sku-selector data-url="{{ route('escaneo.skus') }}">
                  <label for="sku" class="label">SKU / modelo <span class="optional">del inventario del almacén</span></label>
                  <div class="control">
                    <x-icon name="search" />
                    <input id="sku" name="sku" value="{{ old('sku') }}" placeholder="Escribe o escanea el SKU o modelo"
                           @class(['input', 'input-lg', 'input-mono', 'is-invalid' => $errors->has('sku')])
                           role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="sku-opciones"
                           autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="go"
                           @disabled($tarimas->isEmpty()) @if ($tarimas->isNotEmpty()) autofocus @endif data-sku-input>
                    <button type="button" class="control-action" data-sku-abrir aria-label="Ver SKU del inventario" tabindex="-1">
                      <x-icon name="chevron-down" class="icon-sm" />
                    </button>
                  </div>
                  <ul id="sku-opciones" class="combo-lista" role="listbox" aria-label="SKU del inventario" hidden data-sku-lista></ul>
                  <p class="hint" data-sku-estado aria-live="polite"></p>
                  @error('sku') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
                </div>

                <div class="field">
                  <label for="descripcion" class="label">Descripción <span class="optional">obtenido del inventario</span></label>
                  <input id="descripcion" class="input input-derivado" readonly tabindex="-1" placeholder="Se completa al elegir el SKU" data-sku-descripcion>
                </div>

                {{-- Sale en las etiquetas y en el Excel de las piezas de este SKU. --}}
                <div class="field">
                  <label for="demo" class="label">¿Es demo? <span class="optional">sale en la etiqueta y el Excel</span></label>
                  <select id="demo" name="demo" @class(['input', 'is-invalid' => $errors->has('demo')]) @disabled($tarimas->isEmpty())>
                    @foreach (\App\Enums\Demo::cases() as $opcion)
                      <option value="{{ $opcion->value }}" @selected(old('demo', \App\Enums\Demo::NoAplica->value) === $opcion->value)>{{ $opcion->etiqueta() }}</option>
                    @endforeach
                  </select>
                  @error('demo') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top:18px;" @disabled($tarimas->isEmpty())>
                  <x-icon name="unlock" /> Activar SKU
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
              @if ($skuActivo->fueConvertido())
                <x-conversion-sku :original="$skuActivo->sku_original" :sku="$skuActivo->sku" />
              @endif
              <p class="active-sku-desc">{{ $skuActivo->descripcion_mostrada }}</p>
              <div class="active-sku-meta">
                <span><x-icon name="layers" /> {{ $skuActivo->tarima->nombre }}</span>
                @if ($skuActivo->demo->esDemo())
                  <span class="badge badge-warn" data-sku-demo>{{ $skuActivo->demo->etiqueta() }}</span>
                @endif
                @if ($skuActivo->tarima->almacenOrigen())
                  <span><x-icon name="map-pin" /> {{ $skuActivo->tarima->almacenOrigen()->etiqueta }}</span>
                @endif
              </div>
              <div class="active-sku-count">
                <b data-total-activo>{{ $skuActivo->numeros_serie_count }}</b>
                <small>N/S</small>
              </div>
            </div>
          @endunless

          <div class="divider">Números de serie</div>

          <form method="POST" action="{{ route('escaneo.ns') }}" data-escaneo-ns>
            @csrf
            <div class="field">
              <label for="numero_serie" class="label">N/S de la pieza <span class="optional">escanea o escribe a mano</span></label>
              <div class="control">
                <x-icon name="hash" />
                <input id="numero_serie" name="numero_serie" placeholder="{{ $skuActivo ? 'Ej. SN-000123' : 'Activa primero un SKU' }}"
                       @class(['input', 'input-lg', 'input-mono', 'is-invalid' => $errors->has('numero_serie')])
                       maxlength="150" autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="go" data-escaneo
                       @disabled(! $skuActivo) @if ($skuActivo) autofocus @endif>
                <span class="kbd">↵</span>
                @include('wms.partials.teclado')
              </div>
              @error('numero_serie') <p class="field-error" data-error-escaneo><x-icon name="alert" /> {{ $message }}</p> @enderror
              <p class="field-error" data-error-escaneo hidden></p>
            </div>

            <div class="captura-opciones" role="group" aria-label="Otras formas de capturar la N/S">
              <button type="button" class="captura-opcion captura-opcion-foto" data-captura-foto data-url="{{ route('escaneo.ocr') }}"
                      @disabled(! $skuActivo && $tarimas->isEmpty())>
                <span class="tile" aria-hidden="true"><x-icon name="camera" /></span>
                <span><b>Fotos del N/S</b><small data-foto-texto>{{ $skuActivo ? 'Toma una foto de la etiqueta para leer la N/S' : 'Toma una foto de la etiqueta para leer el SKU y la N/S' }}</small></span>
              </button>
              <input type="file" accept="image/*" capture="environment" hidden data-foto-archivo aria-label="Foto de la etiqueta">
            </div>
            <p class="scan-estado" role="status" aria-live="polite" data-foto-estado hidden></p>

            <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;" @disabled(! $skuActivo)>
              <x-icon name="plus" /> Agregar N/S
            </button>
          </form>
        </div>
      </section>
    </div>

    <div>
      <div class="section-head">
        <h2 class="section-title"><span class="step">2</span> Embarques escaneados</h2>
        <span class="badge badge-muted">{{ $tarimasConProductos->count() }} con piezas</span>
      </div>

      <div class="pallets">
        @forelse ($tarimasConProductos as $tarima)
          @php
            $seriesTarima = $tarima->registros->flatMap->numerosSerie;
            $pendientes = $seriesTarima->filter->pendienteRevision();
            $sinEtiqueta = $seriesTarima->filter->etiquetaPendiente()->count();
            // Con alguna etiqueta impresa ya no se agregan, editan ni eliminan N/S desde el panel.
            $conEtiquetas = $seriesTarima->contains(fn ($ns) => ! $ns->etiquetaPendiente());
            $marcadas = $seriesTarima->filter->tieneAnomalia();
          @endphp
          <article class="pallet" data-tarima="{{ $tarima->uuid }}">
            <header class="pallet-head">
              <span class="tile"><x-icon name="layers" /></span>
              <div style="min-width:0;">
                <h3 class="pallet-title">{{ $tarima->nombre }}</h3>
                <p class="pallet-sub">
                  {{ $tarima->registros->count() }} {{ Str::plural('registro', $tarima->registros->count()) }}
                  @if ($tarima->movimiento) · Almacén {{ $tarima->movimiento->nombre }} @endif
                </p>
              </div>
              <span class="badge"><x-icon name="package" class="icon-sm" /> <span data-piezas>{{ $tarima->piezas() }}</span> {{ Str::plural('pieza', $tarima->piezas()) }}</span>
            </header>

            <div class="pallet-body">
              <datalist id="ns-skus-{{ $tarima->id }}">
                @foreach ($tarima->registros->where('tipo', TipoRegistro::Padre) as $modelo)
                  <option value="{{ $modelo->sku }}">{{ $modelo->descripcion_mostrada }}</option>
                @endforeach
              </datalist>
              @foreach ($tarima->registros as $registro)
                @if ($registro->tipo === TipoRegistro::Padre)

                  <div class="line line-parent" data-registro="{{ $registro->id }}">
                    <div class="line-top">
                      <x-icon name="tag" class="icon-sm" style="color:var(--warn-600)" />
                      <span class="code">{{ $registro->sku }}</span>
                      <span class="badge badge-warn"><span data-total-registro>{{ $registro->numerosSerie->count() }}</span> N/S</span>
                    </div>
                    <p class="line-desc">{{ $registro->descripcion_mostrada }}</p>
                    @if ($registro->fueConvertido())
                      <x-conversion-sku :original="$registro->sku_original" :sku="$registro->sku" />
                    @endif
                    <p class="line-note" data-sin-ns @if ($registro->numerosSerie->isNotEmpty()) hidden @endif>Sin números de serie capturados aún.</p>
                    <div class="chips chips-recientes" data-chips data-chips-max="3" aria-label="Últimas N/S" @if ($registro->numerosSerie->isEmpty()) hidden @endif>
                      @foreach ($registro->numerosSerie->slice(-3) as $ns)
                        @include('wms.partials.chip', ['ns' => $ns])
                      @endforeach
                    </div>
                    @include('wms.partials.editar-ns', ['tarima' => $tarima, 'registro' => $registro, 'bloqueado' => $conEtiquetas])
                  </div>
                @else
                  {{-- Registro histórico sin N/S (modo retirado): solo se muestra. --}}
                  <div class="line line-simple">
                    <span class="code">{{ $registro->sku }}</span>
                    <span class="line-desc">{{ $registro->descripcion_mostrada }}</span>
                  </div>
                @endif
              @endforeach

              @if ($pendientes->isNotEmpty())
                <p class="revision-resumen" data-revision-resumen>
                  <x-pendiente-revision />
                  {{ $pendientes->count() }} N/S sin coincidencia en el inventario: <span class="mono">{{ $pendientes->pluck('numero_serie')->join(', ') }}</span>
                </p>
              @endif
              @if ($marcadas->isNotEmpty())
                <p class="anomalias-resumen">✕ {{ $marcadas->count() }} N/S marcadas como dañadas: {{ $marcadas->pluck('numero_serie')->join(', ') }}. Al guardar, cada una se registra como incidencia de «Producto dañado».</p>
              @endif
            </div>

            <footer class="pallet-foot">
              {{-- Una etiqueta por pieza: el contador dice cuántas faltan por imprimir. --}}
              <a @class(['btn btn-sm', 'btn-accent' => $sinEtiqueta, 'btn-secondary' => ! $sinEtiqueta]) href="{{ route('etiquetas.show', $tarima) }}" data-etiquetas-boton>
                <x-icon name="printer" /> Etiquetas
                <span class="badge badge-warn" data-etiquetas-pendientes @unless ($sinEtiqueta) hidden @endunless title="Piezas sin etiqueta impresa">{{ $sinEtiqueta }} sin imprimir</span>
              </a>
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
              <p class="empty-text">Elige un SKU del inventario en el panel de captura y registra sus N/S; aparecerán aquí agrupadas por embarque.</p>
            </div>
          </div>
        @endforelse

        @php $vacias = $tarimas->diff($tarimasConProductos); @endphp
        @if ($vacias->isNotEmpty())
          <div class="empty-pallets">
            <x-icon name="inbox" class="icon-sm" />
            <span>Embarques vacíos: <b>{{ $vacias->pluck('nombre')->join(', ') }}</b></span>
            <span class="btn-group" style="margin-left:auto;">
              @foreach ($vacias as $tarima)
                @include('wms.partials.borrar', ['tarima' => $tarima, 'soloAnular' => true])
              @endforeach
            </span>
          </div>
        @endif

        @if ($tarimasDeOtros->isNotEmpty())

          <div class="empty-pallets">
            <x-icon name="users" class="icon-sm" />
            <span>En captura por otros:
              <b>{{ $tarimasDeOtros->map(fn ($t) => $t->codigo.' ('.($t->creador?->name ?? '—').')')->join(', ') }}</b>
            </span>
          </div>
        @endif
      </div>
    </div>
  </div>
</main>


<dialog id="dlgSerie" class="dialog dialog-serie" aria-labelledby="dlgSerieTitulo" aria-describedby="dlgSerieTexto">
  <div class="dialog-serie-icono" data-serie-icono aria-hidden="true">✓</div>
  <h2 class="dialog-title" id="dlgSerieTitulo" data-serie-titulo></h2>
  <p class="dialog-serie-ns mono" data-serie-ns></p>
  <p class="dialog-text" id="dlgSerieTexto" data-serie-texto></p>
</dialog>
@endsection

@push('scripts')
<script src="{{ asset('js/escaneo.js') }}?v={{ @filemtime(public_path('js/escaneo.js')) }}"></script>
<script src="{{ asset('js/inventario.js') }}?v={{ @filemtime(public_path('js/inventario.js')) }}"></script>
<script src="{{ asset('js/ocr.js') }}?v={{ @filemtime(public_path('js/ocr.js')) }}"></script>
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
