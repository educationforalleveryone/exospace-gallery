<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-100 leading-tight">
                Analytics — {{ $gallery->title }}
            </h2>
            <a href="{{ route('admin.galleries.edit', $gallery) }}"
               class="text-sm text-gray-400 hover:text-purple-400 transition-colors">
                ← Back to Gallery
            </a>
        </div>
    </x-slot>

    <!-- Skeleton loading overlay -->
    <div id="analytics-skeleton" class="py-10 bg-gray-900 min-h-screen">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <!-- Skeleton stat cards -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                @for($i = 0; $i < 5; $i++)
                <div class="bg-gray-800 border border-gray-700 rounded-xl p-5 animate-pulse">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-9 h-9 rounded-lg bg-gray-700"></div>
                        <div class="h-3 bg-gray-700 rounded w-20"></div>
                    </div>
                    <div class="h-7 bg-gray-700 rounded w-16"></div>
                </div>
                @endfor
            </div>
            <!-- Skeleton chart -->
            <div class="bg-gray-800 border border-gray-700 rounded-xl p-6 animate-pulse">
                <div class="h-4 bg-gray-700 rounded w-40 mb-5"></div>
                <div class="h-32 bg-gray-700/50 rounded-lg"></div>
            </div>
            <!-- Skeleton bottom cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                @for($i = 0; $i < 2; $i++)
                <div class="bg-gray-800 border border-gray-700 rounded-xl p-6 animate-pulse">
                    <div class="h-4 bg-gray-700 rounded w-44 mb-5"></div>
                    <div class="space-y-4">
                        @for($j = 0; $j < 4; $j++)
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-gray-700 flex-shrink-0"></div>
                            <div class="flex-1 space-y-2">
                                <div class="h-3 bg-gray-700 rounded w-3/4"></div>
                                <div class="h-2 bg-gray-700/60 rounded-full"></div>
                            </div>
                        </div>
                        @endfor
                    </div>
                </div>
                @endfor
            </div>
        </div>
    </div>

    <!-- Actual content (hidden until loaded) -->
    <div id="analytics-content" style="display:none;" class="py-10 bg-gray-900 min-h-screen">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- ── Overview stat cards ─────────────────────────────── --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                @php
                    $stats = [
                        ['label' => 'Total Views',     'value' => number_format($totalViews),    'icon' => 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z', 'color' => 'purple'],
                        ['label' => 'Unique Visitors', 'value' => number_format($uniqueVisitors), 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'color' => 'blue'],
                        ['label' => 'Avg. Dwell',      'value' => $avgDwell ? round($avgDwell).'s' : '—', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'color' => 'teal'],
                        ['label' => 'Artwork Focuses', 'value' => number_format($totalFocuses),  'icon' => 'M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4', 'color' => 'amber'],
                        ['label' => 'Tour Starts',     'value' => number_format($tourStarts),    'icon' => 'M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'color' => 'pink'],
                    ];
                    $colorMap = ['purple'=>'text-purple-400 bg-purple-900/30', 'blue'=>'text-blue-400 bg-blue-900/30', 'teal'=>'text-teal-400 bg-teal-900/30', 'amber'=>'text-amber-400 bg-amber-900/30', 'pink'=>'text-pink-400 bg-pink-900/30'];
                @endphp

                @foreach($stats as $stat)
                    <div class="bg-gray-800 border border-gray-700 rounded-xl p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center {{ $colorMap[$stat['color']] }}">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $stat['icon'] }}"/>
                                </svg>
                            </div>
                            <span class="text-xs text-gray-500 font-medium uppercase tracking-wide">{{ $stat['label'] }}</span>
                        </div>
                        <div class="text-2xl font-bold text-white">{{ $stat['value'] }}</div>
                        @if($stat['label'] === 'Total Views' && $viewsTrend !== null)
                            <div class="text-xs mt-1 {{ $viewsTrend >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                {{ $viewsTrend >= 0 ? '↑' : '↓' }} {{ abs($viewsTrend) }}% vs prev 7 days
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- ── Views chart ──────────────────────────────────────── --}}
            <div class="bg-gray-800 border border-gray-700 rounded-xl p-6">
                <h3 class="text-base font-semibold text-gray-100 mb-5">Views — last 30 days</h3>
                <canvas id="views-chart" height="80"></canvas>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                {{-- ── Top artworks ─────────────────────────────────── --}}
                <div class="bg-gray-800 border border-gray-700 rounded-xl p-6">
                    <h3 class="text-base font-semibold text-gray-100 mb-5">Most-Focused Artworks</h3>
                    @if($topArtworks->isEmpty())
                        <div class="py-8 text-center">
                            <p class="text-gray-500 text-sm mb-2">No focus events recorded yet.</p>
                            <a href="{{ route('admin.galleries.index') }}" class="text-xs text-purple-400 hover:text-purple-300 transition underline underline-offset-2">Share your gallery to start collecting data →</a>
                        </div>
                    @else
                        @php $maxFocus = $topArtworks->first()->focus_count; @endphp
                        <div class="space-y-3">
                            @foreach($topArtworks as $row)
                                @php
                                    $img    = $row->image;
                                    $title  = $img ? ($img->title ?: $img->original_name) : 'Deleted image';
                                    $pct    = $maxFocus > 0 ? round(($row->focus_count / $maxFocus) * 100) : 0;
                                @endphp
                                <div class="flex items-center gap-3">
                                    @if($img)
                                        <img src="{{ asset($img->path) }}" alt="{{ $img->title ?: $img->original_name ?: 'Artwork' }}" class="w-10 h-10 rounded-lg object-cover flex-shrink-0 border border-gray-700">
                                    @else
                                        <div class="w-10 h-10 rounded-lg bg-gray-700 flex-shrink-0"></div>
                                    @endif
                                    <div class="flex-1 min-w-0">
                                        <div class="flex justify-between mb-1">
                                            <span class="text-sm text-gray-200 truncate max-w-[180px]">{{ $title }}</span>
                                            <span class="text-sm font-semibold text-purple-400 ml-2 flex-shrink-0">{{ $row->focus_count }}</span>
                                        </div>
                                        <div class="h-1.5 bg-gray-700 rounded-full overflow-hidden">
                                            <div class="h-full bg-gradient-to-r from-purple-500 to-blue-500 rounded-full transition-all"
                                                 style="width: {{ $pct }}%"></div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- ── Traffic sources ───────────────────────────────── --}}
                <div class="bg-gray-800 border border-gray-700 rounded-xl p-6">
                    <h3 class="text-base font-semibold text-gray-100 mb-5">Traffic Sources</h3>
                    @if($referrers->isEmpty())
                        <div class="py-8 text-center">
                            <p class="text-gray-500 text-sm mb-1">No traffic recorded yet.</p>
                            <p class="text-gray-600 text-xs">Direct visits and referrers will appear here once people view your gallery.</p>
                        </div>
                    @else
                        @php $maxRef = $referrers->first()->count; @endphp
                        <div class="space-y-3">
                            @foreach($referrers as $ref)
                                @php $pct = $maxRef > 0 ? round(($ref->count / $maxRef) * 100) : 0; @endphp
                                <div>
                                    <div class="flex justify-between mb-1">
                                        <span class="text-sm text-gray-300 flex items-center gap-2">
                                            <span class="inline-block w-2 h-2 rounded-full bg-teal-400"></span>
                                            {{ $ref->referrer ?: 'direct' }}
                                        </span>
                                        <span class="text-sm font-semibold text-teal-400">{{ $ref->count }}</span>
                                    </div>
                                    <div class="h-1.5 bg-gray-700 rounded-full overflow-hidden">
                                        <div class="h-full bg-gradient-to-r from-teal-500 to-cyan-500 rounded-full"
                                             style="width: {{ $pct }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </div>

        </div>
    </div><!-- end #analytics-content -->

    {{-- Chart.js ──────────────────────────────────────────────────────── --}}
    @vite(['resources/js/admin-vendor.js'])
    <script nonce="@nonce">
        // Show skeleton briefly then reveal real content
        setTimeout(function() {
            document.getElementById('analytics-skeleton').style.display = 'none';
            document.getElementById('analytics-content').style.display = 'block';
            initChart();
        }, 1200);

        function initChart() {
        const ctx = document.getElementById('views-chart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: @json($chartDates),
                datasets: [{
                    label: 'Views',
                    data: @json($chartCounts),
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139,92,246,0.10)',
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: '#8b5cf6',
                    tension: 0.35,
                    fill: true,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: { color: '#6b7280', font: { size: 11 }, maxTicksLimit: 10 }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: { color: '#6b7280', font: { size: 11 }, precision: 0 }
                    }
                }
            }
        });
        } // end initChart
    </script>
</x-app-layout>