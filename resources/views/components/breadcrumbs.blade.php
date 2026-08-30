{{--
    Breadcrumb trail (SEO OS Iteration 1).

    Renders a semantic <nav aria-label="Breadcrumb"><ol>…</ol></nav> plus
    the BreadcrumbList JSON-LD graph. Visible breadcrumbs double as an
    internal-linking device — every crumb except the last is a real link.

    Usage:
        @php
            use App\Support\Seo\Breadcrumb;
            $crumbs = Breadcrumb::trail([
                ['Discover', route('discover')],
                ['Gallery Title'],
            ]);
        @endphp
        <x-breadcrumbs :crumbs="$crumbs" />
--}}
@php
    /** @var \App\Support\Seo\Breadcrumb[]|null $crumbs */
    $crumbs = $crumbs ?? [];
    $showJsonLd = $showJsonLd ?? true;
@endphp

@if(count($crumbs) > 1)
<nav aria-label="Breadcrumb" class="text-sm">
    <ol class="flex flex-wrap items-center gap-1.5 text-gray-500">
        @foreach($crumbs as $i => $crumb)
            <li class="flex items-center gap-1.5">
                @if($crumb->url)
                    <a href="{{ $crumb->url }}" class="hover:text-brand-300 transition">{{ $crumb->label() }}</a>
                @else
                    <span class="text-gray-400" aria-current="page">{{ $crumb->label() }}</span>
                @endif
                @if(!$loop->last)
                    <svg class="w-3 h-3 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                @endif
            </li>
        @endforeach
    </ol>
</nav>

@if($showJsonLd)
<script type="application/ld+json">
{!! json_encode(\App\Support\Seo\Breadcrumb::toJsonLd($crumbs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endif
@endif
