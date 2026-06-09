@props(['disabled' => false])

<input
    @disabled($disabled)
    {{ $attributes->merge([
        'class' => 'bg-gray-800 border border-gray-600 text-gray-100 placeholder-gray-500
                    focus:border-purple-500 focus:ring-1 focus:ring-purple-500
                    rounded-lg shadow-sm transition-colors duration-150
                    disabled:opacity-50 disabled:cursor-not-allowed
                    autofill:bg-gray-800'
    ]) }}
>
