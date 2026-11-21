@props(['label', 'name', 'type' => 'text', 'hint' => null, 'id' => null])
@php($inputId = $id ?? 'f-'.str_replace(['.', '[', ']'], '-', $name))
@php($error = $errors->first($name))
<div>
    <label for="{{ $inputId }}" class="fv-label">{{ $label }}</label>
    <input id="{{ $inputId }}" name="{{ $name }}" type="{{ $type }}"
        @if ($error) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @elseif ($hint) aria-describedby="{{ $inputId }}-hint" @endif
        {{ $attributes->class(['fv-input', 'fv-input-error' => $error]) }}>
    @if ($hint && ! $error)
        <p id="{{ $inputId }}-hint" class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $inputId }}-error" class="mt-1 text-xs text-red-700">{{ $error }}</p>
    @endif
</div>
