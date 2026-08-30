{{--
    Canonical page header (ITERATION 2).

    ONE component for every authenticated page's title block. Renders the
    layout's predictable composition:

        [back link | breadcrumbs]
        H1  page title            (visible h1 — fixes the "h2-only in the
        description (optional)     $header slot = no h1 on page" audit gap)
        meta row (optional)
                        [action area — right aligned on ≥lg, stacked on mobile]

    Usage (in the layout's $header slot):
        <x-slot name="header">
            <x-page-header title="Galleries" description="Create and manage 3D galleries">
                <x-slot:actions>
                    <a href="…" class="btn btn-primary">New gallery</a>
                </x-slot:actions>
            </x-page-header>
        </x-slot>

    Props:
        - title       plain-text page title (escaped). For rich titles (chips,
                      avatars) use the `heading` slot instead.
        - description supporting line under the title (string) — or `description` slot.
        - back        URL for a standardised "← Back to …" link (backLabel customises text).

    Slots: heading · description · meta (chips/counts under description) ·
           breadcrumb (anything above the title, e.g. <x-breadcrumbs>) · actions.
--}}
@props([
    'title'       => null,
    'description' => null,
    'back'        => null,
    'backLabel'   => 'Back',
])

<div class="flex flex-col gap-3 sm:gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div class="min-w-0">
        @if(isset($back))
            <a href="{{ $back }}" class="back-link mb-2">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                {{ $backLabel }}
            </a>
        @endif

        @isset($breadcrumb)
            <div class="mb-2">{{ $breadcrumb }}</div>
        @endisset

        @if(isset($heading))
            {{ $heading }}
        @elseif($title)
            <h1 class="page-title break-words">{{ $title }}</h1>
        @endif

        @if(isset($description))
            @if($description instanceof \Illuminate\View\ComponentSlot)
                <div class="page-subtitle">{{ $description }}</div>
            @else
                <p class="page-subtitle">{{ $description }}</p>
            @endif
        @endif

        @isset($meta)
            <div class="mt-2 flex flex-wrap items-center gap-2">{{ $meta }}</div>
        @endisset
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-3 shrink-0">{{ $actions }}</div>
    @endisset
</div>
