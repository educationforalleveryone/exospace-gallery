{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @for($i = 1; $i <= max($pages, 1); $i++)
    <sitemap>
        <loc>{{ url("/sitemap-{$i}.xml") }}</loc>
        <lastmod>{{ now()->toIso8601String() }}</lastmod>
    </sitemap>
    @endfor
</sitemapindex>
