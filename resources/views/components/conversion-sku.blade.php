@props(['original', 'sku'])
{{--
  Señal de un SKU registrado con su equivalente del catálogo de conversiones
  (config/conversiones_sku.php): muestra el SKU escaneado y el registrado.
--}}
<p {{ $attributes->merge(['class' => 'conversion-sku']) }}>
  <x-icon name="info" class="icon-sm" />
  <span>Conversión aplicada sobre este SKU: <span class="mono">{{ $original }}</span> → <span class="mono">{{ $sku }}</span></span>
</p>
