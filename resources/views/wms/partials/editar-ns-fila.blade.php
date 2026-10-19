{{-- Una N/S en "Editar números de serie". Sin $ns es la plantilla que escaneo.js llena con la N/S recién escaneada. --}}
@php
  $id = $ns?->id ?? '__ID__';
  $valor = $ns?->numero_serie ?? '';
@endphp
<li @class(['ns-fila', 'is-marcada' => $ns?->tieneAnomalia()]) data-ns-fila>
  <label class="ns-fila-check">
    <input type="checkbox" name="ns[]" value="{{ $id }}" form="{{ $acciones }}" data-ns-id>
    <span class="ns-fila-texto">
      <span class="mono" data-ns-valor>{{ $valor }}</span>
      @if ($ns?->pendienteRevision())
        <x-pendiente-revision :compacto="true" />
      @endif
      @if ($ns?->tieneAnomalia())
        <span class="badge badge-warn">✕ Dañado</span>
        <span class="anomalias-nota">{{ $ns->anomalia_nota ?? 'Sin descripción.' }}</span>
      @endif
    </span>
  </label>

  @unless ($bloqueado)
    <details class="ns-fila-editar" @if ((int) old('ns_editada') === $ns?->id && $abierto) open @endif>
      <summary class="btn btn-ghost btn-sm" aria-label="Editar la N/S {{ $valor }}"><x-icon name="pencil" class="icon-sm" /> Editar</summary>
      <form method="POST" action="{{ route('tarimas.ns.editar', [$tarima, $id]) }}" class="ns-fila-form" data-ns-form>
        @csrf
        @method('PUT')
        <input type="hidden" name="registro_panel" value="{{ $registro->id }}">
        <input type="hidden" name="ns_editada" value="{{ $id }}" data-ns-id>
        <label class="label" for="ns-sku-{{ $id }}">SKU</label>
        <input id="ns-sku-{{ $id }}" name="nuevo_sku" value="{{ $registro->sku }}" list="{{ $listaSkus }}" required maxlength="100"
               class="input input-mono" autocomplete="off" autocapitalize="characters" spellcheck="false">
        <label class="label" for="ns-valor-{{ $id }}">N/S</label>
        <input id="ns-valor-{{ $id }}" name="nueva_ns" value="{{ $valor }}" required maxlength="150"
               class="input input-mono" autocomplete="off" autocapitalize="characters" spellcheck="false" data-ns-entrada>
        <button type="submit" class="btn btn-primary btn-sm"><x-icon name="check" /> Guardar cambio</button>
      </form>
    </details>
  @endunless
</li>
