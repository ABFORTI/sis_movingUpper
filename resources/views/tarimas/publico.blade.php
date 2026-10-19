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
          <p class="eyebrow" style="margin-bottom:2px;">Piezas del embarque</p>
          <h1 class="page-title">{{ $tarima->identificador }}</h1>
          @if ($tarima->estaAnulada())
            <span class="badge badge-warn">EMBARQUE ANULADO · sus etiquetas ya no son válidas</span>
          @endif
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
        @php $trasladadas = $tarima->trasladadaA(); @endphp
        @if ($trasladadas->isNotEmpty())
          <p class="empty-title">Las piezas se movieron al {{ $trasladadas->count() === 1 ? 'embarque' : 'los embarques' }} {{ $trasladadas->join(', ', ' y ') }}</p>
          <p class="empty-text">Escanea la etiqueta nueva de cada pieza para ver su situación.</p>
        @else
          <p class="empty-title">Este embarque está vacío</p>
          <p class="empty-text">Ya no tiene productos registrados.</p>
        @endif
      </div>
    @else
      <div class="table-wrap">
        <table class="table table-stack table-piezas">
          <thead><tr><th>#</th><th>SKU</th><th>N/S</th><th>Descripción</th><th>Situación</th><th>Hora</th></tr></thead>
          <tbody>
            @foreach ($filas as $i => $fila)
              <tr @class(['is-revision' => $fila['pendiente_revision']])>
                <td class="num c-num" data-label="#">{{ $i + 1 }}</td>
                <td class="c-sku" data-label="SKU"><span class="code">{{ $fila['sku'] }}</span></td>
                <td class="c-ns" data-label="N/S">
                  <span class="mono" style="font-size:13px;">{{ $fila['ns'] }}</span>
                  @if ($fila['pendiente_revision'])
                    {{-- Vista pública: sin enlace a la incidencia (requiere sesión). --}}
                    <span class="badge badge-revision" title="No se encontró en el inventario; pendiente de revisión"><x-icon name="alert" class="icon-sm" /> Pendiente de revisión</span>
                  @endif
                </td>
                <td class="c-desc" data-label="Descripción">{{ $fila['descripcion'] }}</td>
                <td data-label="Situación">{{ $situaciones[$fila['numero_serie_id'] ?? 0] ?? ($tarima->estaGuardada() ? '—' : 'En captura') }}</td>
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
