@extends('layouts.app')

@section('title', 'Tráileres')

@section('content')
@php
  $supervision = auth()->user()->esSupervision();
  $clases = ['en_carga' => 'badge-warn', 'en_transito' => '', 'recibido' => 'badge-success'];
@endphp
<main class="page page-narrow">

  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="truck" class="icon-sm" /> Traslado</p>
      <h1 class="page-title">Tráileres</h1>
      <p class="page-sub">Cada tráiler: las piezas de los embarques que se escanearon al subir en el origen y al bajar en el destino.</p>
    </div>
    @if ($supervision && $mudanza)
      <a class="btn btn-secondary" href="{{ route('embarques.reporte', ['mudanza' => $mudanza->id]) }}"><x-icon name="download" /> Reporte final</a>
    @endif
  </div>

  @if ($mudanzas->count() > 1)
    <form method="GET" class="filters" style="border:1px solid var(--border);border-radius:var(--r-lg);margin-bottom:20px;grid-template-columns:1fr auto;">
      <div class="field">
        <label for="f-mudanza" class="label">Mudanza</label>
        <select id="f-mudanza" name="mudanza" class="input">
          @foreach ($mudanzas as $m)
            <option value="{{ $m->id }}" @selected($mudanza?->id === $m->id)>{{ $m->titulo }} · {{ $m->estado_nombre }}</option>
          @endforeach
        </select>
      </div>
      <div class="filters-actions"><button class="btn btn-secondary">Ver</button></div>
    </form>
  @endif

  @if ($mudanza)
    <div class="stats stats-embarque">
      <div class="stat"><span class="stat-label">Piezas</span><span class="stat-value">{{ number_format($resumen['piezas']) }}</span></div>
      <div class="stat"><span class="stat-label">Sin cargar</span><span class="stat-value">{{ number_format($resumen['sin_cargar']) }}</span></div>
      <div class="stat"><span class="stat-label">Cargadas</span><span class="stat-value">{{ number_format($resumen['cargadas']) }}</span></div>
      <div class="stat"><span class="stat-label">Recibidas</span><span class="stat-value">{{ number_format($resumen['recibidas']) }}</span></div>
    </div>

    @if ($supervision && $mudanza->estaEnCurso())
      <details class="card" @if ($errors->hasAny(['placas', 'remolque', 'chofer', 'movimiento'])) open @endif>
        <summary class="card-head summary-card"><h2 class="card-title"><x-icon name="plus" /> Nuevo tráiler</h2></summary>
        <form method="POST" action="{{ route('embarques.store') }}" class="card-body" data-loading>
          @csrf
          @if ($movimientos->count() > 1)
            <div class="field">
              <label for="movimiento" class="label">Movimiento (almacén origen → destino)</label>
              <select id="movimiento" name="movimiento" required @class(['input', 'is-invalid' => $errors->has('movimiento')])>
                <option value="">Elige…</option>
                @foreach ($movimientos as $m)
                  <option value="{{ $m->id }}" @selected((int) old('movimiento') === $m->id)>Almacén {{ $m->nombre }}</option>
                @endforeach
              </select>
              @error('movimiento') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
              <p class="hint">Solo se podrán cargar embarques de ese movimiento.</p>
            </div>
          @elseif ($movimientos->count() === 1)
            <p class="hint">Movimiento: <b class="mono">Almacén {{ $movimientos->first()->nombre }}</b></p>
          @endif
          <div class="field">
            <label for="placas" class="label">Placas del tractor</label>
            <input id="placas" name="placas" value="{{ old('placas') }}" required minlength="3" maxlength="20" autocapitalize="characters"
                   @class(['input', 'input-mono', 'is-invalid' => $errors->has('placas')])>
            @error('placas') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
          </div>
          <div class="field">
            <label for="remolque" class="label">Remolque / caja <span class="optional">opcional</span></label>
            <input id="remolque" name="remolque" value="{{ old('remolque') }}" maxlength="30" class="input input-mono" autocapitalize="characters">
          </div>
          <div class="field">
            <label for="chofer" class="label">Chofer <span class="optional">opcional</span></label>
            <input id="chofer" name="chofer" value="{{ old('chofer') }}" maxlength="150" class="input">
          </div>
          <button type="submit" class="btn btn-primary btn-block" style="margin-top:12px;">
            <span class="spinner" aria-hidden="true"></span><x-icon name="truck" class="btn-icon" /> Crear y empezar a cargar
          </button>
        </form>
      </details>
    @endif

    <section class="card">
      @if ($embarques->isEmpty())
        <div class="empty">
          <div class="empty-icon"><x-icon name="truck" class="icon-xl" /></div>
          <p class="empty-title">Sin tráileres</p>
          <p class="empty-text">{{ $supervision ? 'Regístralo cuando el tráiler esté en el andén.' : 'Supervisión registra el tráiler cuando está en el andén.' }}</p>
        </div>
      @else
        <div class="table-wrap">
          <table class="table table-stack">
            <thead><tr><th>Folio</th><th>Placas</th><th>Movimiento</th><th>Estado</th><th>Piezas</th></tr></thead>
            <tbody>
              @foreach ($embarques as $e)
                <tr>
                  <td data-label="Folio"><a href="{{ route('embarques.show', $e) }}" class="mono"><b>{{ $e->folio }}</b></a></td>
                  <td data-label="Placas"><span><span class="mono">{{ $e->placas }}</span>{{ $e->remolque ? " · {$e->remolque}" : '' }}</span></td>
                  <td data-label="Movimiento"><span class="mono">{{ $e->movimiento?->nombre ?? '—' }}</span></td>
                  <td data-label="Estado"><span class="badge {{ $clases[$e->estado] ?? '' }}">{{ $e->estado_nombre }}</span></td>
                  <td data-label="Piezas">
                    {{ number_format($e->cargas_count) }}
                    @if ($e->estado !== 'en_carga') <span class="hint">· {{ number_format($e->recibidas_count) }} recibidas</span> @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </section>
  @else
    <div class="card"><div class="empty"><p class="empty-title">Todavía no hay mudanzas</p></div></div>
  @endif

</main>
@endsection
