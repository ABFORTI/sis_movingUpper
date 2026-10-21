@props(['original', 'sku'])

<p {{ $attributes->merge(['class' => 'conversion-sku']) }}>
  <x-icon name="info" class="icon-sm" />
  <span>Conversión aplicada sobre este SKU: <span class="mono">{{ $original }}</span> → <span class="mono">{{ $sku }}</span></span>
</p>
