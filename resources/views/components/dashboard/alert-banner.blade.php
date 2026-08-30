@props([
    'type'   => 'info',   // info | warning | error | success
    'icon'   => null,
    'text',
    'action' => null,     // ['label' => '...', 'href' => '...']
    'dismissKey' => null, // localStorage key for dismiss
])

@php
$styles = [
    'info'    => ['wrap' => 'bg-blue-950/50 border-blue-700/50',   'icon' => 'text-blue-400',  'text' => 'text-blue-200',  'action' => 'text-blue-300 hover:text-blue-100'],
    'warning' => ['wrap' => 'bg-amber-950/50 border-amber-700/50', 'icon' => 'text-amber-400', 'text' => 'text-amber-200', 'action' => 'text-amber-300 hover:text-amber-100'],
    'error'   => ['wrap' => 'bg-red-950/50 border-red-700/50',     'icon' => 'text-red-400',   'text' => 'text-red-200',   'action' => 'text-red-300 hover:text-red-100'],
    'success' => ['wrap' => 'bg-emerald-950/50 border-emerald-700/50', 'icon' => 'text-emerald-400', 'text' => 'text-emerald-200', 'action' => 'text-emerald-300 hover:text-emerald-100'],
    'upgrade' => ['wrap' => 'bg-purple-950/60 border-purple-600/40', 'icon' => 'text-purple-400', 'text' => 'text-purple-200', 'action' => 'text-purple-300 hover:text-white'],
];
$s = $styles[$type] ?? $styles['info'];
$defaultIcons = [
    'info'    => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    'warning' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
    'error'   => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
    'success' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
];
$iconPath = $icon ?? $defaultIcons[$type];
@endphp

@if($dismissKey)
<div x-data="{ show: localStorage.getItem('{{ $dismissKey }}') !== '1' }" x-show="show" x-cloak>
@endif

<div class="flex items-center gap-3 px-4 py-3 rounded-xl border {{ $s['wrap'] }}">
    <svg class="w-4 h-4 flex-shrink-0 {{ $s['icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $iconPath }}"/>
    </svg>
    <p class="flex-1 text-sm {{ $s['text'] }}">{{ $text }}</p>
    <div class="flex items-center gap-3 flex-shrink-0">
        @if($action)
            <a href="{{ $action['href'] }}" class="text-xs font-semibold underline underline-offset-2 {{ $s['action'] }} transition whitespace-nowrap">
                {{ $action['label'] }}
            </a>
        @endif
        @if($dismissKey)
            <button @click="localStorage.setItem('{{ $dismissKey }}','1'); show=false"
                    class="text-gray-600 hover:text-gray-400 transition p-0.5 rounded">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        @endif
    </div>
</div>

@if($dismissKey)
</div>
@endif
