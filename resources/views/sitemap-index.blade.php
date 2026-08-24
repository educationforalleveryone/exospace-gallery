@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

    {{-- SEO OS (Iteration 4): index of sitemap GROUPS (static, galleries,
         artists, artworks, content). lastmod is the real max(updated_at)
         per group so crawlers can prioritize re-crawls. --}}
    @foreach(($groups ?? []) as $group)
    <sitemap>
        <loc>{{ url("/sitemap-{$group['group']}-{$group['page']}.xml") }}</loc>
        @if(!empty($group['lastmod']))
        <lastmod>{{ $group['lastmod'] }}</lastmod>
        @endif
    </sitemap>
    @endforeach
</sitemapindex>
