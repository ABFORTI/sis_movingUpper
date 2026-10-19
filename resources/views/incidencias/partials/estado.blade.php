@php
  $clase = [
      'abierta' => 'badge-warn',
      'resuelta' => 'badge-success',
  ][$incidencia->estado] ?? 'badge-muted';
@endphp
<span class="badge {{ $clase }}">@if ($incidencia->estado === 'abierta')<x-icon name="alert" class="icon-sm" /> @else<x-icon name="check" class="icon-sm" /> @endif{{ $incidencia->estado_nombre }}</span>
@if ($incidencia->bloquea_traslado && $incidencia->estaVigente())
  <span class="badge badge-dark" title="Las piezas vinculadas no se pueden cargar mientras siga abierta"><x-icon name="lock" class="icon-sm" /> Bloquea</span>
@endif
@if ($incidencia->estado_legado)
  <span class="badge badge-muted" title="Estado anterior a la simplificación de estados">antes: {{ ['en_investigacion' => 'en investigación', 'pendiente_cliente' => 'pendiente del cliente', 'descartada' => 'descartada'][$incidencia->estado_legado] ?? $incidencia->estado_legado }}</span>
@endif
