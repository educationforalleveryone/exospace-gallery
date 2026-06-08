@props([
    'title'     => null,
    'action'    => null,  // ['label' => '...', 'href' => '...']
    'padding'   => true,
    'noBorder'  => false,
])

<div class="bg-gray-800 overflow-hidden shadow-lg rounded-xl border {{ $noBorder ? 'border-transparent' : 'border-gray-700' }}">
    @if($title)
    <div class="px-6 py-4 border-b border-gray-700 flex items-center justify-between">
        <h3 class="text-base font-semibold text-gray-100">{{ $title }}</h3>
        @if($action)
            <a href="{{ $action['href'] }}" class="text-sm text-purple-400 hover:text-purple-300 transition font-medium">
                {{ $action['label'] }}
            </a>
        @endif
    </div>
    @endif
    <div @class(['p-6' => $padding])>
        {{ $slot }}
    </div>
</div>
