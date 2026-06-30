@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>Exospace — Featured 3D Exhibitions</title>
        <link>{{ url('/discover') }}</link>
        <description>Walk through virtual galleries curated by artists, photographers, and institutions from around the world.</description>
        <language>en</language>
        <atom:link href="{{ url('/feed.xml') }}" rel="self" type="application/rss+xml"/>
        <lastBuildDate>{{ now()->toRfc2822String() }}</lastBuildDate>

        @foreach($galleries as $gallery)
        <item>
            <title>{{ $gallery->title }}</title>
            <link>{{ $gallery->public_url }}</link>
            <guid isPermaLink="true">{{ $gallery->public_url }}</guid>
            @if($gallery->updated_at)
            <pubDate>{{ $gallery->updated_at->toRfc2822String() }}</pubDate>
            @endif
            <description>
                <![CDATA[
                <p>{{ Str::limit($gallery->description ?: 'A new 3D exhibition on Exospace.', 300) }}</p>
                <p><strong>{{ $gallery->images()->count() }} artworks</strong> · {{ number_format($gallery->view_count) }} views @if($gallery->venueTemplate) · {{ $gallery->venueTemplate->name }} @endif</p>
                <p><a href="{{ $gallery->public_url }}">Enter the 3D exhibition →</a></p>
                ]]>
            </description>
            @if($gallery->coverImage)
            <enclosure url="{{ asset($gallery->coverImage->path) }}" type="{{ $gallery->coverImage->mime_type ?? 'image/jpeg' }}"/>
            @endif
        </item>
        @endforeach
    </channel>
</rss>
