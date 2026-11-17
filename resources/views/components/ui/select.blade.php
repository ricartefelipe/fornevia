@props(['label', 'name', 'options' => [], 'placeholder' => null, 'id' => null])
@php($inputId = $id ?? 'f-'.str_replace(['.', '[', ']'], '-', $name))
@php($error = $errors->first($name))
<div>
    <label for="{{ $inputId }}" class="fv-label">{{ $label }}</label>
    <select id="{{ $inputId }}" name="{{ $name }}"
        @if ($error) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif
        {{ $attributes->class(['fv-input', 'fv-input-error' => $error]) }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}">{{ $text }}</option>
        @endforeach
        {{ $slot }}
    </select>
    @if ($error)
        <p id="{{ $inputId }}-error" class="mt-1 text-xs text-red-700">{{ $error }}</p>
    @endif
</div>
