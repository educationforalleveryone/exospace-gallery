{{--
    Dark-theme pagination (ITERATION 2) — overrides Laravel's light
    "pagination::tailwind" view for every {{ $x->links() }} call in the app.

    Composition: result count (left) + page buttons (right), wrapping cleanly
    on mobile. Uses the design-system button kit; the current page is the one
    solid brand button. aria-current marks the current page for screen readers.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination') }}" class="flex flex-wrap items-center justify-between gap-3 mt-5">
        {{-- Result count --}}
        <p class="text-xs text-gray-500" aria-live="polite">
            @if ($paginator->total() > 0)
                <span class="sr-only">{{ __('Showing') }} </span>
                <span class="font-semibold text-gray-300 text-numeric">{{ $paginator->firstItem() }}</span>
                <span aria-hidden="true">–</span>
                <span class="font-semibold text-gray-300 text-numeric">{{ $paginator->lastItem() }}</span>
                {{ __('of') }}
                <span class="font-semibold text-gray-300 text-numeric">{{ number_format($paginator->total()) }}</span>
                <span class="sr-only"> {{ __('results') }}</span>
            @endif
        </p>

        {{-- Page buttons --}}
        <div class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="btn btn-sm btn-secondary opacity-40 pointer-events-none" aria-disabled="true" aria-label="{{ __('Previous') }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-sm btn-secondary" aria-label="{{ __('Previous') }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
            @endif

            @foreach ($elements as $element)
                {{-- "Three dots" separator --}}
                @if (is_string($element))
                    <span class="px-1.5 text-xs text-gray-600" aria-hidden="true">{{ $element }}</span>
                @endif

                {{-- Array of page links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="btn btn-sm btn-primary pointer-events-none">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="btn btn-sm btn-ghost" aria-label="{{ __('Go to page') }} {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-sm btn-secondary" aria-label="{{ __('Next') }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            @else
                <span class="btn btn-sm btn-secondary opacity-40 pointer-events-none" aria-disabled="true" aria-label="{{ __('Next') }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </span>
            @endif
        </div>
    </nav>
@endif
