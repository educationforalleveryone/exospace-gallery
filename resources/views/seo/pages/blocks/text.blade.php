{{-- Block: text — heading + paragraphs. Paragraphs split on blank lines; escaped. --}}
<section class="max-w-3xl mx-auto px-4 py-14">
    @if(!empty($data['heading']))
        <h2 class="text-2xl md:text-3xl font-bold text-white mb-6">{{ $data['heading'] }}</h2>
    @endif
    <div class="space-y-4">
        @foreach(preg_split('/\n\s*\n/', trim((string) ($data['body'] ?? ''))) as $paragraph)
            @if(trim($paragraph) !== '')
                <p class="text-gray-300 leading-relaxed">{{ trim($paragraph) }}</p>
            @endif
        @endforeach
    </div>
</section>
