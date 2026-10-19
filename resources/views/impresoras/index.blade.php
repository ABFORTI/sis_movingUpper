@extends('layouts.app')

@section('title', 'Impresoras')

@section('content')
<main class="page page-narrow">

  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="shield" class="icon-sm" /> Administración</p>
      <h1 class="page-title">Impresoras</h1>
      <p class="page-sub">Impresoras Zebra registradas por su MAC Bluetooth. Cada handheld elige la suya en la pantalla de impresión.</p>
    </div>
    <a class="btn btn-accent" href="{{ route('impresoras.create') }}"><x-icon name="plus" /> Nueva impresora</a>
  </div>

  <section class="card">
    @if ($impresoras->isEmpty())
      <div class="empty">
        <div class="empty-icon"><x-icon name="printer" class="icon-xl" /></div>
        <p class="empty-title">Todavía no hay impresoras</p>
        <p class="empty-text">Registra la ZQ620 con su MAC Bluetooth para que los equipos la encuentren.</p>
      </div>
    @else
      <div class="table-wrap">
        <table class="table table-stack">
          <thead>
            <tr><th>Impresora</th><th>MAC</th><th>Nombre Bluetooth</th><th>Estado</th><th class="actions"><span class="sr-only">Acciones</span></th></tr>
          </thead>
          <tbody>
            @foreach ($impresoras as $impresora)
              <tr @class(['is-inactive' => ! $impresora->activa])>
                <td data-label="Impresora">
                  <b>{{ $impresora->nombre }}</b>
                  @if ($impresora->ubicacion)<div class="hint">{{ $impresora->ubicacion }}</div>@endif
                </td>
                <td data-label="MAC"><span class="mono">{{ $impresora->mac }}</span></td>
                <td data-label="Nombre Bluetooth"><span class="mono">{{ $impresora->nombre_bluetooth ?? '—' }}</span></td>
                <td data-label="Estado">
                  @if ($impresora->activa)
                    <span class="badge badge-success"><span class="dot"></span> Activa</span>
                  @else
                    <span class="badge badge-muted"><span class="dot"></span> Inactiva</span>
                  @endif
                </td>
                <td class="actions" data-label="">
                  <a class="btn btn-ghost btn-sm" href="{{ route('impresoras.edit', $impresora) }}"><x-icon name="pencil" /> Editar</a>
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
