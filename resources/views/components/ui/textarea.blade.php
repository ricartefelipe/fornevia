@props(['label', 'name', 'rows' => 3, 'id' => null])
@php($inputId = $id ?? 'f-'.str_replace(['.', '[', ']'], '-', $name))
@php($error = $errors->first($name))
<div>
    <label for="{{ $inputId }}" class="fv-label">{{ $label }}</label>
    <textarea id="{{ $inputId }}" name="{{ $name }}" rows="{{ $rows }}"
        @if ($error) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif
        {{ $attributes->class(['fv-input', 'fv-input-error' => $error]) }}>{{ $slot }}</textarea>
    @if ($error)
        <p id="{{ $inputId }}-error" class="mt-1 text-xs text-red-700">{{ $error }}</p>
    @endif
</div>
