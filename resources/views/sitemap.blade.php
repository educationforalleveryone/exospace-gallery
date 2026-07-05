@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">

    {{-- S-4: Static pages only on page 1 (when called from sitemapPage controller) --}}
    @if($includeStatic ?? true)
    @foreach($staticPages as $page)
    <url>
        <loc>{{ $page['url'] }}</loc>
        <changefreq>{{ $page['changefreq'] }}</changefreq>
        <priority>{{ $page['priority'] }}</priority>
    </url>
    @endforeach
    @endif

    {{-- Public galleries --}}
    @foreach($galleries as $gallery)
    <url>
        <loc>{{ $gallery->public_url }}</loc>
        @if($gallery->updated_at)
        <lastmod>{{ $gallery->updated_at->toIso8601String() }}</lastmod>
        @endif
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
        @if($gallery->coverImage)
        <image:image>
            <image:loc>{{ asset($gallery->coverImage->path) }}</image:loc>
            <image:title>{{ $gallery->title }}</image:title>
        </image:image>
        @endif
    </url>
    @endforeach

    {{-- (Task H13) — artist profiles. New section. --}}
    @foreach($artists as $artist)
    <url>
        <loc>{{ route('artist.profile', $artist->slug) }}</loc>
        @if($artist->updated_at)
        <lastmod>{{ $artist->updated_at->toIso8601String() }}</lastmod>
        @endif
        <changefreq>weekly</changefreq>
        <priority>0.6</priority>
    </url>
    @endforeach
</urlset>
