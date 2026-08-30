@props([
    'title'     => null,
    'action'    => null,  // ['label' => '...', 'href' => '...']
    'padding'   => true,
    'noBorder'  => false,
])

<div class="card shadow-card overflow-hidden">
    @if($title)
    <div class="px-6 py-4 border-b border-gray-700/60 flex items-center justify-between">
        <h2 class="section-title">{{ $title }}</h2>
        @if($action)
            <a href="{{ $action['href'] }}" class="action-link text-brand-400 hover:text-brand-300">
                {{ $action['label'] }}
            </a>
        @endif
    </div>
    @endif
    <div @class(['card-pad' => $padding])>
        {{ $slot }}
    </div>
</div>
