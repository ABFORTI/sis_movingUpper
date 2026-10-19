{{-- Una N/S en la tarjeta del embarque: ⚠ + "revisar" si no tuvo coincidencia en el inventario, ✕ si se marcó dañada. --}}
@php
  $revision = $ns->pendienteRevision();
  $titulo = collect([
      $revision ? 'Pendiente de revisión: no se encontró en el inventario ('.$ns->incidenciaPendiente->folio.')' : null,
      $ns->tieneAnomalia() ? 'Dañado: '.($ns->anomalia_nota ?? 'sin descripción') : null,
      $ns->coincidencia_nombre ? 'Coincidió en: '.$ns->coincidencia_nombre : null,
  ])->filter()->join(' · ');
@endphp
<span @class(['chip', 'chip-revision' => $revision, 'chip-anomalia' => $ns->tieneAnomalia() && ! $revision]) @if ($titulo) title="{{ $titulo }}" @endif data-ns="{{ $ns->numero_serie }}">@if ($revision)⚠ @elseif ($ns->tieneAnomalia())✕ @endif{{ $ns->numero_serie }}@if ($revision)<span class="chip-etiqueta">revisar</span>@endif</span>
