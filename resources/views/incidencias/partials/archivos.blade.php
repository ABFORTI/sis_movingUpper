{{-- Campo de evidencias. En el handheld, Android ofrece cámara o archivos. --}}
<div class="field">
  <label for="{{ $id ?? 'archivos' }}" class="label">{{ $etiqueta ?? 'Evidencia' }} <span class="optional">{{ $nota ?? 'fotos, PDF, Excel o correo · hasta 10 MB c/u' }}</span></label>
  <input type="file" id="{{ $id ?? 'archivos' }}" name="archivos[]" multiple
         accept="image/*,.pdf,.xlsx,.xls,.csv,.eml,.msg,.txt"
         @class(['input', 'input-archivo', 'is-invalid' => $errors->has('archivos') || $errors->has('archivos.*')])>
  @error('archivos') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
  @if ($errors->has('archivos.*'))
    <p class="field-error"><x-icon name="alert" /> {{ $errors->first('archivos.*') }}</p>
  @endif
</div>
