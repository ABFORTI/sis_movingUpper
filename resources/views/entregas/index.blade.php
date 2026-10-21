@extends('layouts.app')

@section('title', 'Reporte Diario')

@php
  $esHoy = $fecha->isToday();
  $diaTexto = $fecha->format('d/m/Y');
  $piezasDelDia = $resumen->sum('piezas');
  $ultima = $entregas->firstWhere('fecha', '!=', null);
@endphp

@section('content')
<main class="page page-narrow">

  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="file-text" class="icon-sm" /> Cliente</p>
      <h1 class="page-title">Reporte Diario</h1>
      <p class="page-sub">El trabajo de un día en un Excel para el cliente: las piezas guardadas cuya fecha de registro es el día elegido. Cada reporte queda guardado tal cual se envió, con su versión y su huella (SHA-256).</p>
    </div>
  </div>


  <form method="GET" class="filters filtros-dia" role="search" aria-label="Elegir el día del reporte">
    @if ($mudanzas->count() > 1)
      <div class="field">
        <label for="f-mudanza" class="label">Mudanza</label>
        <select id="f-mudanza" name="mudanza" class="input">
          @foreach ($mudanzas as $m)
            <option value="{{ $m->id }}" @selected($mudanza?->id === $m->id)>{{ $m->titulo }} · {{ $m->estado_nombre }}</option>
          @endforeach
        </select>
      </div>
    @elseif ($mudanza)
      <input type="hidden" name="mudanza" value="{{ $mudanza->id }}">
    @endif
    <div class="field">
      <label for="f-fecha" class="label">Día</label>
      <input id="f-fecha" type="date" name="fecha" value="{{ $fecha->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}"
             @class(['input', 'is-invalid' => $errors->has('fecha')]) required>
      @error('fecha') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
    </div>
    <div class="filters-actions">
      <button class="btn btn-primary"><x-icon name="search" /> Ver día</button>
      @unless ($esHoy)
        <a class="btn btn-secondary" href="{{ route('entregas.index', array_filter(['mudanza' => $mudanza?->id])) }}">Hoy</a>
      @endunless
    </div>
  </form>

  @if ($mudanza)
    <section class="card">
      <div class="card-head">
        <div>
          <h2 class="card-title"><x-icon name="package" /> Trabajado {{ $esHoy ? 'hoy' : "el {$diaTexto}" }}</h2>
          <p class="card-desc">{{ number_format($piezasDelDia) }} {{ Str::plural('pieza', $piezasDelDia) }} guardadas en {{ $resumen->count() }} {{ Str::plural('embarque', $resumen->count()) }}.</p>
        </div>
      </div>
      @if ($resumen->isEmpty())
        <div class="empty">
          <div class="empty-icon"><x-icon name="inbox" class="icon-xl" /></div>
          <p class="empty-title">Sin piezas guardadas ese día</p>
          <p class="empty-text">Elige otro día o guarda los embarques en captura para que sus piezas entren al reporte.</p>
        </div>
      @else
        <div class="table-wrap">
          <table class="table table-stack">
            <thead><tr><th>Embarque</th><th>Piezas</th><th>SKU distintos</th></tr></thead>
            <tbody>
              @foreach ($resumen as $fila)
                <tr>
                  <td data-label="Embarque"><b class="nowrap">{{ $fila['codigo'] }}</b></td>
                  <td data-label="Piezas">{{ number_format($fila['piezas']) }}</td>
                  <td data-label="SKU distintos">{{ $fila['skus'] }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </section>

    <form method="POST" action="{{ route('entregas.store') }}" class="card" data-loading>
      @csrf
      <input type="hidden" name="mudanza" value="{{ $mudanza->id }}">
      <input type="hidden" name="fecha" value="{{ $fecha->format('Y-m-d') }}">
      <div class="card-head">
        <div>
          <h2 class="card-title"><x-icon name="plus" /> Generar reporte del {{ $diaTexto }}</h2>
          <p class="card-desc">{{ $ultima ? "Ya hay un reporte de este día (v{$ultima->version}): el nuevo marca piezas nuevas, las que cambiaron de embarque y las que salieron." : 'Primer reporte de este día.' }}</p>
        </div>
      </div>
      <div class="card-body">
        @if ($pendientes['en_captura'] || $pendientes['reimprimir'] || $pendientes['incidencias'])
          <div class="alert alert-warn" style="margin-bottom:16px;">
            <x-icon name="alert" />
            <div class="alert-body">
              <p class="alert-title">Revisa antes de entregar</p>
              <ul>
                @if ($pendientes['en_captura'])
                  <li>Embarques en captura con piezas de este día (<b>no</b> se incluyen hasta guardarlos): {!! collect($pendientes['en_captura'])->map(fn ($c) => '<span class="nowrap">'.e($c).'</span>')->join(', ') !!}</li>
                @endif
                @if ($pendientes['reimprimir'])
                  <li>Embarques con etiquetas por reimprimir: {!! collect($pendientes['reimprimir'])->map(fn ($c) => '<span class="nowrap">'.e($c).'</span>')->join(', ') !!}</li>
                @endif
                @if ($pendientes['incidencias'])
                  <li>{{ $pendientes['incidencias'] }} {{ Str::plural('incidencia abierta', $pendientes['incidencias']) }} en la mudanza (aparecen en la columna «Incidencias abiertas»)</li>
                @endif
              </ul>
            </div>
          </div>
        @endif
        <div class="field">
          <label for="notas" class="label">Notas para este reporte <span class="optional">opcional</span></label>
          <textarea id="notas" name="notas" rows="2" maxlength="500" class="input dialog-campo" placeholder="Ej. Incluye embarques del pasillo B">{{ old('notas') }}</textarea>
        </div>
        @error('entrega') <p class="field-error" role="alert"><x-icon name="alert" /> {{ $message }}</p> @enderror
      </div>
      <div class="card-foot">
        <button type="submit" class="btn btn-primary" @disabled($resumen->isEmpty())>
          <span class="spinner" aria-hidden="true"></span>
          <x-icon name="file-text" class="btn-icon" /> Generar Excel del día
        </button>
      </div>
    </form>

    <section class="card">
      <div class="card-head">
        <div>
          <h2 class="card-title"><x-icon name="download" /> Reportes del {{ $diaTexto }}</h2>
        </div>
      </div>
      @if ($entregas->isEmpty())
        <div class="empty">
          <div class="empty-icon"><x-icon name="inbox" class="icon-xl" /></div>
          <p class="empty-title">Aún no hay reportes de este día</p>
          <p class="empty-text">Genera el Excel cuando el trabajo del día esté listo para enviarse al cliente.</p>
        </div>
      @else
        <div class="table-wrap">
          <table class="table table-stack">
            <thead><tr><th>Versión</th><th>Generado</th><th>Piezas</th><th>Cambios</th><th>Huella</th><th class="actions"><span class="sr-only">Acciones</span></th></tr></thead>
            <tbody>
              @foreach ($entregas as $entrega)
                <tr>
                  <td data-label="Versión">
                    <b>v{{ $entrega->version }}</b>
                    @unless ($entrega->fecha)<span class="badge badge-muted" title="Entrega anterior al Reporte Diario">Mudanza completa</span>@endunless
                  </td>
                  <td data-label="Generado">
                    <span><span class="nowrap">{{ $entrega->created_at->format('d/m/Y H:i') }}</span> · {{ $entrega->generadaPor?->name ?? '—' }}</span>
                  </td>
                  <td data-label="Piezas">{{ number_format($entrega->total_partidas) }}</td>
                  <td data-label="Cambios">
                    @if ($entrega->nuevas + $entrega->cambiadas + $entrega->salieron === 0)
                      <span class="hint">—</span>
                    @else
                      <span class="badge badge-success">+{{ $entrega->nuevas }}</span>
                      <span class="badge badge-muted" title="Cambiaron de embarque">⇄ {{ $entrega->cambiadas }}</span>
                      <span class="badge badge-warn" title="Salieron">−{{ $entrega->salieron }}</span>
                    @endif
                  </td>
                  <td data-label="Huella"><code class="mono" title="SHA-256: {{ $entrega->sha256 }}">{{ substr($entrega->sha256, 0, 12) }}…</code></td>
                  <td class="actions" data-label="">
                    <a class="btn btn-secondary btn-sm" href="{{ route('entregas.descargar', $entrega) }}"><x-icon name="download" /> Excel</a>
                  </td>
                </tr>
                @if ($entrega->notas)
                  <tr><td colspan="6" class="hint" data-label="Notas">{{ $entrega->notas }}</td></tr>
                @endif
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
