@props(['value'])

<label {{ $attributes->merge(['class' => 'label-text mb-1.5']) }}>
    {{ $value ?? $slot }}
</label>
