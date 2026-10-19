@extends('layouts.app')

@php
  use App\Models\Incidencia;
  use App\Models\IncidenciaEvento;
  $supervision = auth()->user()->esSupervision();
  $iconos = [
      IncidenciaEvento::CREADA => 'plus', IncidenciaEvento::COMENTARIO => 'file-text', IncidenciaEvento::ESTADO => 'check-circle',
      IncidenciaEvento::ASIGNACION => 'user', IncidenciaEvento::EVIDENCIA => 'upload', IncidenciaEvento::PARTIDAS => 'package',
  ];
@endphp

@section('title', $incidencia->folio)

@section('content')
<main class="page page-narrow">

  <nav class="breadcrumb" aria-label="Ruta">
    <a href="{{ route('incidencias.index', ['mudanza' => $incidencia->mudanza_id]) }}">Incidencias</a>
    <x-icon name="chevron-right" />
    <span>{{ $incidencia->folio }}</span>
  </nav>

  <div class="page-head">
    <div style="min-width:0;">
      <p class="eyebrow"><span class="mono">{{ $incidencia->folio }}</span> · {{ $incidencia->tipo_nombre }}</p>
      <h1 class="page-title">{{ $incidencia->titulo }}</h1>
      <div class="btn-group" style="margin-top:8px;">@include('incidencias.partials.estado')</div>
    </div>
  </div>

  <section class="card">
    <div class="card-body">
      <div class="meta-grid" style="margin-top:0;">
        <div class="meta-item"><span><x-icon name="user" /> Registrada por</span><b>{{ $incidencia->creadaPor?->name ?? '—' }}</b><small class="hint">{{ $incidencia->created_at->format('d/m/Y H:i') }}</small></div>
        <div class="meta-item"><span><x-icon name="shield" /> Responsable</span><b>{{ $incidencia->responsable?->name ?? 'Sin asignar' }}</b></div>
        <div class="meta-item"><span><x-icon name="lock" /> Traslado</span><b>{{ $incidencia->bloquea_traslado ? 'Bloquea' : 'No bloquea' }}</b></div>
        @if ($incidencia->numero_serie)
          <div class="meta-item"><span><x-icon name="hash" /> N/S capturada</span><b class="mono">{{ $incidencia->numero_serie }}</b><small class="hint">SKU {{ $incidencia->sku }}</small></div>
          <div class="meta-item"><span><x-icon name="map-pin" /> Embarque · almacén</span><b>{{ $incidencia->tarima?->codigo ?? '—' }}</b><small class="hint">{{ $incidencia->almacen?->etiqueta ?? '—' }}</small></div>
        @endif
      </div>
      @if ($incidencia->tipo === Incidencia::NO_EN_INVENTARIO && $incidencia->estaVigente())
        <p style="margin-top:12px;"><x-pendiente-revision /> La pieza se muestra señalada en el escaneo, el historial y los Excel de entrega y del tráiler hasta que se resuelva.</p>
      @endif
      @if ($incidencia->descripcion)
        <p class="incidencia-texto">{{ $incidencia->descripcion }}</p>
      @endif
      @if ($incidencia->resolucion)
        <div @class(['alert', 'alert-success' => $incidencia->estado === Incidencia::RESUELTA, 'alert-info' => $incidencia->estado !== Incidencia::RESUELTA]) style="margin-top:16px;">
          <x-icon name="check-circle" />
          <div class="alert-body">
            <p class="alert-title">{{ $incidencia->estado_nombre }}{{ $incidencia->tipo_resolucion ? ': '.Incidencia::RESOLUCIONES[$incidencia->tipo_resolucion] : '' }}</p>
            <p class="alert-text">{{ $incidencia->resolucion }} · {{ $incidencia->resueltaPor?->name }}, {{ $incidencia->resuelta_at?->format('d/m/Y H:i') }}</p>
          </div>
        </div>
      @endif
    </div>
  </section>

  <section class="card">
    <div class="card-head"><div><h2 class="card-title"><x-icon name="package" /> Piezas afectadas</h2></div></div>
    @if ($incidencia->partidas->isEmpty())
      <div class="card-body"><p class="hint">{{ $incidencia->numero_serie ? 'La pieza se ligará en cuanto se guarde su embarque.' : 'Ninguna pieza guardada vinculada.' }}</p></div>
    @else
      <div class="table-wrap">
        <table class="table table-stack">
          <thead><tr><th>#</th><th>N/S</th><th>SKU</th><th>Embarque</th><th>Estado</th></tr></thead>
          <tbody>
            @foreach ($incidencia->partidas as $p)
              <tr>
                <td class="mono" data-label="Consecutivo">{{ $p->consecutivo }}</td>
                <td data-label="N/S"><span class="mono">{{ $p->ns_mostrada }}</span> @if ($p->pendienteRevision())<x-pendiente-revision :compacto="true" />@endif</td>
                <td data-label="SKU"><span class="code">{{ $p->sku }}</span></td>
                <td data-label="Embarque">{{ $p->tarima->codigo }}</td>
                <td data-label="Estado">{{ $p->estaRetirada() ? 'Retirada' : 'En embarque' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
    @if ($supervision)
      <form method="POST" action="{{ route('incidencias.vincular', $incidencia) }}" class="card-body inline-form" style="border-top:1px solid var(--border);">
        @csrf
        <div class="field" style="flex:1;">
          <label for="piezas" class="label">Vincular piezas <span class="optional">N/S o consecutivo, una por renglón</span></label>
          <textarea id="piezas" name="piezas" rows="2" class="input dialog-campo input-mono" autocapitalize="characters" spellcheck="false">{{ old('piezas') }}</textarea>
        </div>
        <button class="btn btn-secondary"><x-icon name="plus" /> Vincular</button>
      </form>
    @endif
  </section>

  @include('partials.evidencias', ['evidencias' => $incidencia->evidencias])

  <section class="card">
    <div class="card-head"><div><h2 class="card-title"><x-icon name="clock" /> Historial</h2></div></div>
    <ol class="timeline card-body">
      @foreach ($incidencia->eventos as $ev)
        <li class="timeline-item">
          <span class="tile tile-muted"><x-icon :name="$iconos[$ev->tipo] ?? 'info'" class="icon-sm" /></span>
          <div style="min-width:0;">
            <p class="timeline-meta"><b>{{ $ev->usuario?->name ?? '—' }}</b> · {{ $ev->created_at->format('d/m/Y H:i') }}</p>
            <p class="timeline-texto">{{ $ev->detalle }}</p>
          </div>
        </li>
      @endforeach
    </ol>

    <form method="POST" action="{{ route('incidencias.comentar', $incidencia) }}" enctype="multipart/form-data" class="card-body" style="border-top:1px solid var(--border);" data-loading>
      @csrf
      <div class="field">
        <label for="comentario" class="label">Agregar comentario</label>
        <textarea id="comentario" name="comentario" rows="2" maxlength="5000" class="input dialog-campo" placeholder="Avance, hallazgo, respuesta del cliente…">{{ old('comentario') }}</textarea>
        @error('comentario') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
      </div>
      @include('incidencias.partials.archivos', ['id' => 'archivosComentario', 'etiqueta' => 'Adjuntar'])
      <button type="submit" class="btn btn-secondary btn-block" style="margin-top:12px;">
        <span class="spinner" aria-hidden="true"></span><x-icon name="plus" class="btn-icon" /> Agregar al historial
      </button>
    </form>
  </section>

  @if ($supervision)
    <section class="card">
      <div class="card-head"><div><h2 class="card-title"><x-icon name="shield" /> Seguimiento</h2><p class="card-desc">Solo administración y auditoría.</p></div></div>
      <div class="card-body seguimiento">
        <form method="POST" action="{{ route('incidencias.asignar', $incidencia) }}" class="inline-form">
          @csrf
          <div class="field" style="flex:1;">
            <label for="responsable_id" class="label">Responsable</label>
            <select id="responsable_id" name="responsable_id" class="input">
              <option value="">Sin asignar</option>
              @foreach ($usuarios as $u)
                <option value="{{ $u->id }}" @selected($incidencia->responsable_id === $u->id)>{{ $u->name }}</option>
              @endforeach
            </select>
          </div>
          <button class="btn btn-secondary">Asignar</button>
        </form>

        @unless ($incidencia->estaVigente())
          <form method="POST" action="{{ route('incidencias.reabrir', $incidencia) }}" class="inline-form">
            @csrf
            <div class="field" style="flex:1;">
              <label for="nota" class="label">Reabrir</label>
              <input id="nota" name="nota" class="input" maxlength="1000" required minlength="5" placeholder="Motivo para reabrir">
              @error('nota') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
            </div>
            <button class="btn btn-secondary">Reabrir</button>
          </form>
        @endunless

        @if ($incidencia->estaVigente())
          <form method="POST" action="{{ route('incidencias.resolver', $incidencia) }}" enctype="multipart/form-data" class="resolver" data-loading>
            @csrf
            <h3 class="section-title">Resolver</h3>
            <div class="field">
              <label for="tipo_resolucion" class="label">Cómo se resolvió</label>
              <select id="tipo_resolucion" name="tipo_resolucion" required @class(['input', 'is-invalid' => $errors->has('tipo_resolucion')])>
                <option value="">Elige…</option>
                @foreach (Incidencia::RESOLUCIONES as $clave => $nombre)
                  <option value="{{ $clave }}" @selected(old('tipo_resolucion') === $clave)>{{ $nombre }}{{ in_array($clave, Incidencia::RESOLUCIONES_DEL_CLIENTE, true) ? ' · requiere evidencia' : '' }}</option>
                @endforeach
              </select>
            </div>
            <div class="field">
              <label for="resolucion" class="label">Detalle</label>
              <textarea id="resolucion" name="resolucion" rows="2" required minlength="5" maxlength="5000" @class(['input', 'dialog-campo', 'is-invalid' => $errors->has('resolucion')])>{{ old('resolucion') }}</textarea>
              @error('resolucion') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
            </div>
            @include('incidencias.partials.archivos', ['id' => 'archivosResolucion', 'etiqueta' => 'Respaldo', 'nota' => 'obligatorio si es decisión del cliente'])
            <button type="submit" class="btn btn-success btn-block" style="margin-top:12px;">
              <span class="spinner" aria-hidden="true"></span><x-icon name="check" class="btn-icon" /> Marcar como resuelta
            </button>
          </form>

        @endif
      </div>
    </section>
  @endif

</main>
@endsection
