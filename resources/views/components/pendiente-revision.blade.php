@props(['incidencia' => null, 'compacto' => false])
{{--
  Señal de N/S registrada sin coincidencia en el inventario (incidencia abierta
  de "no encontrado en inventario"). Icono + texto: no depende solo del color.
  Desaparece sola cuando la incidencia se resuelve.
--}}
@php
  $titulo = 'Pendiente de revisión: no se encontró en el inventario'.($incidencia ? " · {$incidencia->folio}" : '');
@endphp
@if ($incidencia && ! $compacto)
  <a href="{{ route('incidencias.show', $incidencia) }}" {{ $attributes->merge(['class' => 'badge badge-revision']) }} title="{{ $titulo }}">
    <x-icon name="alert" class="icon-sm" /> Pendiente de revisión <span class="mono">{{ $incidencia->folio }}</span>
  </a>
@else
  <span {{ $attributes->merge(['class' => 'badge badge-revision']) }} title="{{ $titulo }}">
    <x-icon name="alert" class="icon-sm" />{{ $compacto ? 'Revisar' : 'Pendiente de revisión' }}
  </span>
@endif
