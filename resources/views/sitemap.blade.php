@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">

    {{-- SEO OS (Iteration 4): generic urlset renderer. $entries is a list of
         ['loc' => ..., 'lastmod' => ?string, 'changefreq' => ?string,
          'priority' => ?string, 'image' => ?['loc' => ..., 'title' => ...]] --}}
    @foreach(($entries ?? []) as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
        @if(!empty($entry['lastmod']))
        <lastmod>{{ $entry['lastmod'] }}</lastmod>
        @endif
        @if(!empty($entry['changefreq']))
        <changefreq>{{ $entry['changefreq'] }}</changefreq>
        @endif
        @if(!empty($entry['priority']))
        <priority>{{ $entry['priority'] }}</priority>
        @endif
        @if(!empty($entry['image']))
        <image:image>
            <image:loc>{{ $entry['image']['loc'] }}</image:loc>
            <image:title>{{ $entry['image']['title'] }}</image:title>
        </image:image>
        @endif
    </url>
    @endforeach
</urlset>
