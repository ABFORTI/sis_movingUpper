@extends('layouts.app')

@section('title', 'Manuales')

@section('content')
<main class="page page-narrow">

  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="book" class="icon-sm" /> Documentación</p>
      <h1 class="page-title">Manuales</h1>
      <p class="page-sub">
        {{ $esAdmin ? 'Publica los manuales en PDF y decide qué rol puede consultarlos.' : 'Guías y procedimientos disponibles para tu rol.' }}
      </p>
    </div>
    @if ($esAdmin)
      <a class="btn btn-accent" href="{{ route('manuales.create') }}"><x-icon name="plus" /> Nuevo manual</a>
    @endif
  </div>

  @if ($manuales->isEmpty())
    <section class="card">
      <div class="empty">
        <div class="empty-icon"><x-icon name="book" class="icon-xl" /></div>
        @if ($esAdmin)
          <p class="empty-title">Todavía no hay manuales</p>
          <p class="empty-text">Sube el primer PDF y elige si lo verán operadores, auditores o ambos.</p>
          <a class="btn btn-primary" href="{{ route('manuales.create') }}" style="margin-top:16px;"><x-icon name="upload" /> Subir manual</a>
        @else
          <p class="empty-title">No hay manuales para tu rol</p>
          <p class="empty-text">Cuando el administrador publique uno, aparecerá aquí.</p>
        @endif
      </div>
    </section>

  @elseif ($esAdmin)
    <section class="card">
      <div class="toolbar">
        <div class="control">
          <x-icon name="search" class="icon-sm" />
          <input type="search" class="input" placeholder="Buscar por nombre o visibilidad…"
                 data-filter="#tablaManuales" data-filter-count="#conteoManuales" aria-label="Buscar manuales">
        </div>
        <span class="toolbar-count"><b id="conteoManuales">{{ $manuales->count() }}</b> de {{ $manuales->count() }}</span>
      </div>

      <div class="table-wrap">
        <table class="table table-stack" id="tablaManuales">
          <thead>
            <tr><th>Manual</th><th>Visible para</th><th>Actualizado</th><th class="actions"><span class="sr-only">Acciones</span></th></tr>
          </thead>
          <tbody>
            @foreach ($manuales as $manual)
              <tr>
                <td class="cell-manual" data-label="Manual">
                  <div class="manual-row">
                    <span class="tile tile-muted"><x-icon name="file-text" /></span>
                    <div style="min-width:0;">
                      <div class="manual-title">{{ $manual->nombre }}</div>
                      <div class="manual-meta">PDF · {{ $manual->tamanoLegible() }}</div>
                    </div>
                  </div>
                </td>
                <td data-label="Visible para">
                  <span @class(['badge', 'badge-muted' => $manual->visible_para !== \App\Enums\VisibilidadManual::OperadorYAuditor])>
                    {{ $manual->visible_para->etiqueta() }}
                  </span>
                </td>
                <td class="nowrap" data-label="Actualizado">
                  <time datetime="{{ $manual->updated_at->toIso8601String() }}" class="manual-meta">{{ $manual->updated_at->format('d/m/Y H:i') }}</time>
                </td>
                <td class="actions" data-label="">
                  <div class="btn-group" style="justify-content:flex-end;">
                    <a class="btn btn-ghost btn-sm" href="{{ route('manuales.ver', $manual) }}" target="_blank" rel="noopener">
                      <x-icon name="external" /> Ver
                    </a>
                    <a class="btn btn-ghost btn-sm" href="{{ route('manuales.edit', $manual) }}"><x-icon name="pencil" /> Editar</a>
                    <form method="POST" action="{{ route('manuales.destroy', $manual) }}"
                          onsubmit="return confirm(this.dataset.confirmar)"
                          data-confirmar="¿Eliminar el manual «{{ $manual->nombre }}»? El PDF se borrará definitivamente.">
                      @csrf
                      @method('DELETE')
                      <button type="submit" class="btn btn-danger-soft btn-sm"><x-icon name="trash" /> Eliminar</button>
                    </form>
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>

  @else
    <section class="manuales-grid" aria-label="Manuales disponibles">
      @foreach ($manuales as $manual)
        <article class="manual-card">
          <div class="manual-row">
            <span class="tile"><x-icon name="file-text" /></span>
            <div style="min-width:0;">
              <h2 class="manual-title">{{ $manual->nombre }}</h2>
              <p class="manual-meta">PDF · {{ $manual->tamanoLegible() }} · Actualizado {{ $manual->updated_at->format('d/m/Y') }}</p>
            </div>
          </div>
          <div class="manual-card-foot">
            <a class="btn btn-primary btn-sm" href="{{ route('manuales.ver', $manual) }}" target="_blank" rel="noopener">
              <x-icon name="external" /> Ver manual
            </a>
            <a class="btn btn-secondary btn-sm" href="{{ route('manuales.descargar', $manual) }}" aria-label="Descargar {{ $manual->nombre }}">
              <x-icon name="download" /> Descargar
            </a>
          </div>
        </article>
      @endforeach
    </section>
  @endif

</main>
@endsection
