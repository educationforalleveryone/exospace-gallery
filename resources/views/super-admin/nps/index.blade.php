<x-app-layout>
    <x-slot name="header">
        <x-page-header title="NPS Dashboard" :back="route('super.index')" backLabel="Master Control"/>
    </x-slot>

    <div class="page-shell-mid">

        {{-- NPS Score Banner --}}
        <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-400 uppercase tracking-wider mb-1">Net Promoter Score</p>
                    <p class="text-5xl font-bold {{ $stats['nps_score'] >= 0 ? 'text-emerald-400' : 'text-red-400' }}">
                        {{ $stats['nps_score'] > 0 ? '+' : '' }}{{ $stats['nps_score'] }}
                    </p>
                    <p class="text-xs text-gray-500 mt-1">Range: -100 to +100 | Based on {{ $stats['total'] }} responses</p>
                </div>
                <div class="text-right space-y-1">
                    <p class="text-sm"><span class="inline-block w-3 h-3 rounded-full bg-emerald-500 mr-2"></span>Promoters: {{ $stats['promoters'] }}</p>
                    <p class="text-sm"><span class="inline-block w-3 h-3 rounded-full bg-amber-500 mr-2"></span>Passives: {{ $stats['passives'] }}</p>
                    <p class="text-sm"><span class="inline-block w-3 h-3 rounded-full bg-red-500 mr-2"></span>Detractors: {{ $stats['detractors'] }}</p>
                    <p class="text-sm text-gray-400 mt-2">Avg score: {{ $stats['avg_score'] }}/10</p>
                </div>
            </div>
        </div>

        {{-- Responses --}}
        @if($responses->isEmpty())
            <div class="text-center py-16">
                <p class="text-gray-400">No NPS responses yet.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach($responses as $r)
                <div class="bg-gray-900 border border-gray-700 rounded-xl p-4 flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center text-lg font-bold
                        {{ $r->score >= 9 ? 'bg-emerald-500/20 text-emerald-400' : ($r->score <= 6 ? 'bg-red-500/20 text-red-400' : 'bg-amber-500/20 text-amber-400') }}">
                        {{ $r->score }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-sm font-medium text-gray-200">{{ $r->user?->name ?? 'Unknown' }}</span>
                            <span class="text-xs text-gray-500">{{ $r->user?->email }}</span>
                            <span class="text-xs text-gray-600">{{ $r->responded_at?->diffForHumans() }}</span>
                        </div>
                        @if($r->feedback)
                            <p class="text-sm text-gray-400">{{ $r->feedback }}</p>
                        @endif
                    </div>
                    <span class="text-xs px-2 py-1 rounded-full {{ $r->npsCategory() === 'promoter' ? 'bg-emerald-900/40 text-emerald-400' : ($r->npsCategory() === 'detractor' ? 'bg-red-900/40 text-red-400' : 'bg-amber-900/40 text-amber-400') }}">
                        {{ ucfirst($r->npsCategory()) }}
                    </span>
                </div>
                @endforeach
            </div>
            <div class="mt-6">{{ $responses->links() }}</div>
        @endif
    </div>
</x-app-layout>
