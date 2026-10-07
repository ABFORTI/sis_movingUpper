@extends('layouts.app')

@section('title', $tarima->nombre)

@section('content')
@guest
  <header class="public-top">
    <div class="topbar-inner">
      <x-brand />
      <span class="badge badge-success">
        <x-icon name="shield" class="icon-sm" /> Consulta verificada
      </span>
    </div>
  </header>
@endguest

<main class="page page-narrow">

  <section class="card">
    <div class="card-body">
      <div class="public-hero">
        <span class="tile tile-ink"><x-icon name="truck" class="icon-lg" /></span>
        <div>
          <p class="eyebrow" style="margin-bottom:2px;">Contenido de la tarima</p>
          <h1 class="page-title">Tarima {{ $tarima->id }}</h1>
        </div>
      </div>

      <div class="meta-grid">
        <div class="meta-item">
          <span><x-icon name="map-pin" /> Destino</span>
          <b>{{ $destino }}</b>
        </div>
        <div class="meta-item">
          <span><x-icon name="package" /> Total piezas</span>
          <b>{{ $filas->count() }}</b>
        </div>
        <div class="meta-item">
          <span><x-icon name="clock" /> Consultado</span>
          <b>{{ now()->format('d/m/Y H:i') }}</b>
        </div>
      </div>
    </div>
  </section>

  <section class="card">
    @if ($filas->isEmpty())
      <div class="empty">
        <div class="empty-icon"><x-icon name="inbox" class="icon-xl" /></div>
        <p class="empty-title">Esta tarima está vacía</p>
        <p class="empty-text">Ya no tiene productos registrados.</p>
      </div>
    @else
      <div class="table-wrap">
        <table class="table table-stack table-piezas">
          <thead><tr><th>#</th><th>SKU</th><th>N/S</th><th>Descripción</th><th>Hora</th></tr></thead>
          <tbody>
            @foreach ($filas as $i => $fila)
              <tr>
                <td class="num c-num" data-label="#">{{ $i + 1 }}</td>
                <td class="c-sku" data-label="SKU"><span class="code">{{ $fila['sku'] }}</span></td>
                <td class="c-ns" data-label="N/S"><span class="mono" style="font-size:13px;">{{ $fila['ns'] }}</span></td>
                <td class="c-desc" data-label="Descripción">{{ $fila['descripcion'] }}</td>
                <td class="c-hora" data-label="Hora" style="color:var(--text-3);">{{ $fila['hora'] }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </section>

</main>

<footer class="site-foot">
  <img src="{{ asset('images/brand/movingupper-mark.png') }}" alt="" width="26" height="16">
  MovingUpper · Sistema de Upper Logistics
</footer>
@endsection
