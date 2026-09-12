@props(['for' => null, 'required' => false, 'optional' => false])

<label for="{{ $for }}" {{ $attributes->merge(['class' => 'block text-sm font-medium text-gray-300 mb-1.5']) }}>
    {{ $slot }}
    @if($required) <span class="text-red-400" aria-hidden="true">*</span> @endif
    @if($optional) <span class="text-gray-500 text-xs">(optional)</span> @endif
</label>
