@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
    <url>
        <loc>{{ url('/') }}</loc>
        <changefreq>weekly</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc>{{ url('/discover') }}</loc>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>
    <url>
        <loc>{{ url('/pricing') }}</loc>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
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
</urlset>
