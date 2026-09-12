@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

    @foreach(($groups ?? []) as $group)
    <sitemap>
        <loc>{{ url("/sitemap-{$group['group']}-{$group['page']}.xml") }}</loc>
        @if(!empty($group['lastmod']))
        <lastmod>{{ $group['lastmod'] }}</lastmod>
        @endif
    </sitemap>
    @endforeach
</sitemapindex>
