@extends('layouts.app')

@php
  use App\Models\Mudanza;
  use App\Services\MudanzaService;

  $insignias = [
      Mudanza::BORRADOR => 'badge-muted',
      Mudanza::ACTIVA => 'badge-success',
      Mudanza::SUSPENDIDA => 'badge-warn',
      Mudanza::EN_CIERRE => 'badge-warn',
      Mudanza::CERRADA => 'badge-dark',
      Mudanza::CANCELADA => 'badge-muted',
  ];
@endphp

@section('title', 'Mudanzas')

@section('content')
<main class="page">

  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="shield" class="icon-sm" /> Supervisión</p>
      <h1 class="page-title">Mudanzas</h1>
      <p class="page-sub">Solo puede haber una mudanza en curso. Todo lo que se escanea se registra en ella.
        @unless (auth()->user()->isAdmin()) Puedes crear y activar mudanzas; editarlas y el resto de cambios de estado son del administrador. @endunless
      </p>
    </div>
    <a class="btn btn-accent" href="{{ route('mudanzas.create') }}"><x-icon name="plus" /> Nueva mudanza</a>
  </div>

  <section class="card">
    @if ($mudanzas->isEmpty())
      <div class="empty">
        <div class="empty-icon"><x-icon name="truck" class="icon-xl" /></div>
        <p class="empty-title">Todavía no hay mudanzas</p>
        <p class="empty-text">Crea una mudanza y actívala para que los operadores puedan crear embarques.</p>
      </div>
    @else
      <div class="table-wrap">
        <table class="table table-stack">
          <thead>
            <tr><th>Folio</th><th>Cliente</th><th>Movimientos</th><th>Estado</th><th>Embarques</th><th class="actions"><span class="sr-only">Acciones</span></th></tr>
          </thead>
          <tbody>
            @foreach ($mudanzas as $mudanza)
              <tr>
                <td data-label="Folio"><b class="nowrap mono">{{ $mudanza->folio }}</b></td>
                <td data-label="Cliente">{{ $mudanza->cliente ?? '—' }}</td>
                <td data-label="Movimientos"><span class="mono">{{ $mudanza->rutas }}</span></td>
                <td data-label="Estado"><span class="badge {{ $insignias[$mudanza->estado] ?? 'badge-muted' }}">{{ $mudanza->estado_nombre }}</span></td>
                <td data-label="Embarques">{{ $mudanza->tarimas_guardadas_count }} guardados · {{ $mudanza->tarimas_count }} en total</td>
                <td class="actions" data-label="">
                  <span class="btn-group" style="justify-content:flex-end;">
                    @if (auth()->user()->isAdmin() && ! in_array($mudanza->estado, [Mudanza::CERRADA, Mudanza::CANCELADA], true))
                      <a class="btn btn-ghost btn-sm" href="{{ route('mudanzas.edit', $mudanza) }}"><x-icon name="pencil" /> Editar</a>
                    @endif
                    @foreach ($servicio->accionesPara($mudanza, auth()->user()) as $accion)
                      @php [, , $pideMotivo] = MudanzaService::TRANSICIONES[$accion]; @endphp
                      <form method="POST" action="{{ route('mudanzas.estado', $mudanza) }}"
                            @if ($pideMotivo)
                              data-motivo="{{ MudanzaService::ETIQUETAS[$accion] }} {{ $mudanza->folio }}"
                            @else
                              onsubmit="return confirm('¿{{ MudanzaService::ETIQUETAS[$accion] }} {{ $mudanza->folio }}?')"
                            @endif>
                        @csrf
                        <input type="hidden" name="accion" value="{{ $accion }}">
                        <input type="hidden" name="motivo">
                        <button type="submit" @class(['btn', 'btn-sm', 'btn-danger-soft' => $accion === MudanzaService::CANCELAR, 'btn-secondary' => $accion !== MudanzaService::CANCELAR])>
                          {{ MudanzaService::ETIQUETAS[$accion] }}
                        </button>
                      </form>
                    @endforeach
                  </span>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </section>

</main>
@endsection
