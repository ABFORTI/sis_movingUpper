@extends('layouts.app')

@section('title', 'Historial')

@php
  $hayFiltros = collect($filtros)->filter(fn ($v) => filled($v))->isNotEmpty();
  $verCreador = auth()->user()->puedeVerTodasLasTarimas();
@endphp

@section('content')
<main class="page">

  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="clock" class="icon-sm" /> Consulta</p>
      <h1 class="page-title">Historial</h1>
      <p class="page-sub">
        {{ $verCreador ? 'Todas las tarimas guardadas en el sistema.' : 'Las tarimas que tú creaste y guardaste.' }}
      </p>
    </div>

    <a class="btn btn-secondary" href="{{ route('export.todas', array_filter($filtros, 'filled')) }}" title="{{ $hayFiltros ? 'Exporta las tarimas guardadas que coinciden con los filtros' : 'Exporta todas las tarimas guardadas visibles para ti' }}">
      <x-icon name="sheet" /> Exportar a Excel
    </a>
  </div>

  <section class="card">
    <form method="GET" action="{{ route('historial') }}" class="filters" role="search" aria-label="Filtrar historial">
      <div class="field">
        <label for="f-tarima" class="label">Tarima</label>
        <input id="f-tarima" name="tarima" value="{{ $filtros['tarima'] ?? '' }}" class="input" placeholder="Ej. 12" inputmode="numeric" autocomplete="off">
      </div>
      <div class="field">
        <label for="f-descripcion" class="label">Descripción</label>
        <input id="f-descripcion" name="descripcion" value="{{ $filtros['descripcion'] ?? '' }}" class="input" placeholder="Ej. Bomba hidráulica" autocomplete="off">
      </div>
      <div class="field">
        <label for="f-destino" class="label">Producto destino</label>
        <input id="f-destino" name="destino" value="{{ $filtros['destino'] ?? '' }}" class="input" placeholder="Ej. PROD-A o Plomería" autocomplete="off">
      </div>
      <div class="filters-actions">
        <button type="submit" class="btn btn-primary"><x-icon name="search" /> Buscar</button>
        <a href="{{ route('historial') }}" class="btn btn-secondary">Limpiar</a>
      </div>
    </form>

    @if ($tarimas->isEmpty())
      <div class="empty">
        <div class="empty-icon"><x-icon name="{{ $hayFiltros ? 'search' : 'inbox' }}" class="icon-xl" /></div>
        <p class="empty-title">{{ $hayFiltros ? 'Sin resultados' : 'Aún no hay tarimas guardadas' }}</p>
        <p class="empty-text">
          {{ $hayFiltros ? 'Ninguna tarima coincide con los filtros. Prueba con otros valores o límpialos.' : 'Imprime la etiqueta de una tarima y presiona Guardar para que aparezca aquí.' }}
        </p>
      </div>
    @else
      <div class="toolbar">
        <span class="toolbar-count" style="margin-left:0;">
          Mostrando <b>{{ $tarimas->firstItem() }}–{{ $tarimas->lastItem() }}</b> de <b>{{ $tarimas->total() }}</b> {{ Str::plural('tarima', $tarimas->total()) }}
        </span>
      </div>

      <div class="table-wrap">
        <table class="table table-stack">
          <thead>
            <tr>
              <th>Tarima</th>
              <th>SKU</th>
              <th>Producto destino</th>
              <th>Piezas</th>
              <th>Guardada</th>
              @if ($verCreador)<th>Creada por</th>@endif
              <th class="actions">Acciones</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($tarimas as $tarima)
              @php
                $skus = $tarima->registros->pluck('sku');
                $destinos = $tarima->registros->pluck('destino.codigo')->unique();
              @endphp
              <tr>
                <td data-label="Tarima"><b class="nowrap">{{ $tarima->nombre }}</b></td>
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
                <td data-label="Producto destino">{{ $destinos->join(', ') ?: '—' }}</td>
                <td data-label="Piezas"><span class="badge badge-muted">{{ $tarima->piezas() }}</span></td>
                <td class="nowrap" data-label="Guardada">{{ $tarima->guardada_at->format('d/m/Y H:i') }}</td>
                @if ($verCreador)
                  <td data-label="Creada por">{{ $tarima->creador?->name ?? '—' }}</td>
                @endif
                <td class="actions" data-label="">
                  <span class="btn-group" style="justify-content:flex-end;">
                    <a class="btn btn-secondary btn-sm" href="{{ route('etiquetas.show', $tarima) }}">
                      <x-icon name="tag" /> Ver tarjeta
                    </a>
                    <a class="btn btn-secondary btn-sm" href="{{ $urlsQr[$tarima->id] }}" target="_blank" rel="noopener">
                      <x-icon name="qr" /> Ver QR
                    </a>
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
