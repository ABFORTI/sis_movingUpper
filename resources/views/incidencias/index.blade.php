@extends('layouts.app')

@php use App\Models\Incidencia; @endphp

@section('title', 'Incidencias')

@section('content')
<main class="page">

  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="alert" class="icon-sm" /> Operación</p>
      <h1 class="page-title">Incidencias</h1>
      <p class="page-sub">{{ $mudanza ? "Hallazgos y diferencias de la {$mudanza->titulo}. Registrarlas no detiene el escaneo." : 'Todavía no hay mudanzas.' }}</p>
    </div>
    @if ($mudanza?->estaEnCurso())
      <a class="btn btn-accent" href="{{ route('incidencias.create') }}"><x-icon name="plus" /> Nueva incidencia</a>
    @endif
  </div>

  <section class="card">
    <form method="GET" class="filters" role="search" aria-label="Filtrar incidencias">
      @if ($mudanzas->count() > 1)
        <div class="field">
          <label for="f-mudanza" class="label">Mudanza</label>
          <select id="f-mudanza" name="mudanza" class="input">
            @foreach ($mudanzas as $m)
              <option value="{{ $m->id }}" @selected($mudanza?->id === $m->id)>{{ $m->folio }} · {{ $m->estado_nombre }}</option>
            @endforeach
          </select>
        </div>
      @endif
      <div class="field">
        <label for="f-estado" class="label">Estado</label>
        <select id="f-estado" name="estado" class="input">
          @foreach (Incidencia::ESTADOS as $clave => $nombre)
            <option value="{{ $clave }}" @selected($estado === $clave)>{{ $nombre }}s</option>
          @endforeach
          <option value="todas" @selected($estado === 'todas')>Todas</option>
        </select>
      </div>
      <div class="field">
        <label for="f-tipo" class="label">Tipo</label>
        <select id="f-tipo" name="tipo" class="input">
          <option value="">Todos</option>
          @foreach (Incidencia::TIPOS as $clave => $nombre)
            <option value="{{ $clave }}" @selected($tipo === $clave)>{{ $nombre }}</option>
          @endforeach
          @if ($hayHistoricas || $tipo === 'historicos')
            <option value="historicos" @selected($tipo === 'historicos')>Tipos anteriores (histórico)</option>
          @endif
        </select>
      </div>
      <div class="filters-actions">
        <button type="submit" class="btn btn-primary"><x-icon name="search" /> Filtrar</button>
      </div>
    </form>

    @if ($incidencias->isEmpty())
      <div class="empty">
        <div class="empty-icon"><x-icon name="check-circle" class="icon-xl" /></div>
        <p class="empty-title">{{ $estado === Incidencia::ABIERTA ? 'Sin incidencias abiertas' : 'Sin resultados' }}</p>
        <p class="empty-text">Aquí aparecen las N/S que no se encontraron en el inventario y los productos dañados.</p>
      </div>
    @else
      <div class="table-wrap">
        <table class="table table-stack">
          <thead><tr><th>Folio</th><th>Incidencia</th><th>N/S · SKU</th><th>Estado</th><th>Piezas</th><th>Responsable</th><th>Actualizada</th></tr></thead>
          <tbody>
            @foreach ($incidencias as $incidencia)
              <tr>
                <td data-label="Folio"><a href="{{ route('incidencias.show', $incidencia) }}"><b class="mono nowrap">{{ $incidencia->folio }}</b></a></td>
                <td data-label="Incidencia" class="cell-incidencia">
                  <a href="{{ route('incidencias.show', $incidencia) }}" class="incidencia-titulo">{{ $incidencia->titulo }}</a>
                  <div class="hint">{{ $incidencia->tipo_nombre }}</div>
                </td>
                <td data-label="N/S · SKU">
                  @if ($incidencia->numero_serie)
                    <span class="mono">{{ $incidencia->numero_serie }}</span>
                    <div class="hint">SKU {{ $incidencia->sku }}{{ $incidencia->tarima ? ' · '.$incidencia->tarima->codigo : '' }}{{ $incidencia->almacen ? ' · '.$incidencia->almacen->etiqueta : '' }}</div>
                    @if ($incidencia->tipo === Incidencia::NO_EN_INVENTARIO && $incidencia->estaVigente())
                      <x-pendiente-revision :compacto="true" />
                    @endif
                  @else
                    —
                  @endif
                </td>
                <td data-label="Estado"><span class="btn-group">@include('incidencias.partials.estado')</span></td>
                <td data-label="Piezas">{{ $incidencia->partidas_count }} @if ($incidencia->evidencias_count)<span class="hint">· {{ $incidencia->evidencias_count }} 📎</span>@endif</td>
                <td data-label="Responsable">{{ $incidencia->responsable?->name ?? '—' }}</td>
                <td class="nowrap" data-label="Actualizada">{{ $incidencia->updated_at->format('d/m/Y H:i') }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      {{ $incidencias->links('partials.paginacion') }}
    @endif
  </section>

</main>
@endsection
