@props(['disabled' => false])

<input
    @disabled($disabled)
    {{ $attributes->merge([
        'class' => 'bg-gray-800/80 border border-gray-600/80 text-gray-100 placeholder-gray-500
                    focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20
                    rounded-lg shadow-sm transition-all duration-150
                    hover:border-gray-500
                    disabled:opacity-50 disabled:cursor-not-allowed
                    autofill:bg-gray-800'
    ]) }}
>
