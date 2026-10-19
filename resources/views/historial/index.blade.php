@extends('layouts.app')

@section('title', 'Historial')

@php
  $hayFiltros = collect($filtros)->except('mudanza')->filter(fn ($v) => filled($v))->isNotEmpty();
  $filtrosExcel = array_filter(array_merge($filtros, ['mudanza' => $mudanza?->id]), 'filled');
@endphp

@section('content')
<main class="page">

  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="clock" class="icon-sm" /> Consulta</p>
      <h1 class="page-title">Historial</h1>
      <p class="page-sub">
        {{ $mudanza ? "Embarques guardados de la {$mudanza->titulo} ({$mudanza->estado_nombre})." : 'Todavía no hay mudanzas.' }}
      </p>
      
    </div>

    <form method="GET" action="{{ route('export.todas') }}" class="btn-group" data-excel-alcance aria-label="Exportar Excel de trabajo">
      @foreach ($filtrosExcel as $nombre => $valor)
        <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
      @endforeach
      @php $porEmbarque = old('alcance', request('alcance')) === \App\Http\Controllers\ExportController::POR_EMBARQUE; @endphp
      <select name="alcance" class="input" aria-label="Alcance del Excel" data-alcance>
        <option value="{{ \App\Http\Controllers\ExportController::COMPLETO }}" title="{{ $hayFiltros ? 'Embarques guardados que coinciden con los filtros' : 'Todos los embarques guardados de la mudanza' }}">Completo{{ $hayFiltros ? ' (filtrado)' : '' }}</option>
        <option value="{{ \App\Http\Controllers\ExportController::POR_EMBARQUE }}" @selected($porEmbarque) @disabled($embarquesExcel->isEmpty())>Por embarque</option>
      </select>
      <select name="embarque" class="input" aria-label="Embarque a exportar" data-alcance-embarque @required($porEmbarque) @if (! $porEmbarque) hidden disabled @endif>
        <option value="">Elige un embarque…</option>
        @foreach ($embarquesExcel as $e)
          <option value="{{ $e->id }}" @selected((string) request('embarque') === (string) $e->id)>{{ $e->codigo }}</option>
        @endforeach
      </select>
      <button type="submit" class="btn btn-secondary" title="Exporta según el alcance elegido">
        <x-icon name="sheet" /> Excel de trabajo
      </button>
    </form>
  </div>

  <section class="card">
    <form method="GET" action="{{ route('historial') }}" class="filters" role="search" aria-label="Filtrar historial">
      @if ($mudanzas->count() > 1)
        <div class="field">
          <label for="f-mudanza" class="label">Mudanza</label>
          <select id="f-mudanza" name="mudanza" class="input">
            @foreach ($mudanzas as $m)
              <option value="{{ $m->id }}" @selected($mudanza?->id === $m->id)>{{ $m->titulo }} · {{ $m->estado_nombre }}</option>
            @endforeach
          </select>
        </div>
      @endif
      <div class="field">
        <label for="f-tarima" class="label">Embarque</label>
        <input id="f-tarima" name="tarima" value="{{ $filtros['tarima'] ?? '' }}" class="input" placeholder="Ej. E-012" autocomplete="off">
      </div>
      <div class="field">
        <label for="f-descripcion" class="label">Descripción</label>
        <input id="f-descripcion" name="descripcion" value="{{ $filtros['descripcion'] ?? '' }}" class="input" placeholder="Ej. Refrigeradores" autocomplete="off">
      </div>
      <div class="field">
        <label for="f-ns" class="label">N/S</label>
        <input id="f-ns" name="numero_serie" value="{{ $filtros['numero_serie'] ?? '' }}" class="input input-mono" placeholder="Ej. SN-000123" autocomplete="off" autocapitalize="characters" spellcheck="false">
      </div>
      <div class="filters-actions">
        <button type="submit" class="btn btn-primary"><x-icon name="search" /> Buscar</button>
        <a href="{{ route('historial') }}" class="btn btn-secondary">Limpiar</a>
      </div>
    </form>

    @if ($tarimas->isEmpty())
      <div class="empty">
        <div class="empty-icon"><x-icon name="{{ $hayFiltros ? 'search' : 'inbox' }}" class="icon-xl" /></div>
        <p class="empty-title">{{ $hayFiltros ? 'Sin resultados' : 'Aún no hay embarques guardados' }}</p>
        <p class="empty-text">
          {{ $hayFiltros ? 'Ningún embarque coincide con los filtros. Prueba con otros valores o límpialos.' : 'Imprime las etiquetas de las piezas de un embarque y presiona Guardar para que aparezca aquí.' }}
        </p>
      </div>
    @else
      <div class="toolbar">
        <span class="toolbar-count" style="margin-left:0;">
          Mostrando <b>{{ $tarimas->firstItem() }}–{{ $tarimas->lastItem() }}</b> de <b>{{ $tarimas->total() }}</b> {{ Str::plural('embarque', $tarimas->total()) }}
        </span>
      </div>

      <div class="table-wrap">
        <table class="table table-stack">
          <thead>
            <tr>
              <th>Embarque</th>
              <th>SKU</th>
              <th>Movimiento</th>
              <th>Piezas</th>
              <th>Guardado</th>
              <th>Creado por</th>
              <th class="actions">Acciones</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($tarimas as $tarima)
              @php
                $skus = $tarima->registros->pluck('sku');
                $pendientes = $tarima->registros->flatMap->numerosSerie->filter->pendienteRevision();
              @endphp
              <tr>
                <td data-label="Embarque">
                  <b class="nowrap">{{ $tarima->nombre }}</b>
                  @if ($tarima->requiereReimpresion() && ! $tarima->estaAnulada())
                    <span class="badge badge-warn" title="Llegaron piezas movidas desde otro embarque: su etiqueta trae el número anterior">Reimprimir</span>
                  @endif
                  @php $sinPartida = app(\App\Services\TarimaService::class)->piezasSinPartida($tarima); @endphp
                  @if ($sinPartida > 0)
                    <span class="badge badge-warn" title="Guardado sin numerar sus piezas: no salen en el Excel. Ábrelo en Corregir → Completar piezas.">{{ $sinPartida }} sin consecutivo</span>
                  @endif
                  @if ($tarima->estaAnulada())
                    <span class="badge badge-warn" title="{{ $tarima->anulada_at->format('d/m/Y H:i') }} · {{ $tarima->anuladaPor?->name ?? '—' }} · {{ $tarima->motivo_anulacion }}">Anulado</span>
                  @endif
                  @if ($pendientes->isNotEmpty())
                    <div style="margin-top:4px;" title="N/S sin coincidencia en el inventario: {{ $pendientes->pluck('numero_serie')->join(', ') }}">
                      <x-pendiente-revision :compacto="true" /> <span class="hint mono">{{ $pendientes->pluck('numero_serie')->join(', ') }}</span>
                    </div>
                  @endif
                </td>
                <td data-label="SKU">
                  <div class="chips" style="margin-top:0;">
                    @foreach ($skus->take(3) as $sku)
                      <span class="chip">{{ $sku }}</span>
                    @endforeach
                    @if ($skus->count() > 3)
                      <span class="chip" title="{{ $skus->slice(3)->join(', ') }}">+{{ $skus->count() - 3 }}</span>
                    @endif
                  </div>
                </td>
                <td data-label="Movimiento"><span class="mono">{{ $tarima->movimiento?->nombre ?? '—' }}</span></td>
                <td data-label="Piezas">
                  <span class="badge badge-muted">{{ $tarima->piezas() }}</span>
                  @php $trasladadas = $tarima->piezas() === 0 ? $tarima->trasladadaA() : collect(); @endphp
                  @if ($trasladadas->isNotEmpty())
                    <div class="hint" style="margin-top:4px;">Las piezas se movieron al {{ $trasladadas->count() === 1 ? 'embarque' : 'los embarques' }} {{ $trasladadas->join(', ', ' y ') }}</div>
                  @endif
                </td>
                <td class="nowrap" data-label="Guardado">{{ $tarima->guardada_at->format('d/m/Y H:i') }}</td>
                <td data-label="Creado por">{{ $tarima->creador?->name ?? '—' }}</td>
                <td class="actions" data-label="">
                  <span class="btn-group" style="justify-content:flex-end;">
                    @unless ($tarima->estaAnulada())
                      <a class="btn btn-secondary btn-sm" href="{{ route('etiquetas.show', $tarima) }}">
                        <x-icon name="tag" /> Etiquetas
                      </a>
                    @endunless
                    <a class="btn btn-secondary btn-sm" href="{{ $urlsQr[$tarima->id] }}" target="_blank" rel="noopener">
                      <x-icon name="package" /> Ver piezas
                    </a>
                    @can('corregir', $tarima)
                      <a class="btn btn-secondary btn-sm" href="{{ route('tarimas.corregir', $tarima) }}"><x-icon name="pencil" /> Corregir</a>
                    @endcan
                    @include('wms.partials.borrar', ['tarima' => $tarima, 'soloAnular' => true])
                  </span>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      {{ $tarimas->links('partials.paginacion') }}
    @endif
  </section>

</main>
@endsection
