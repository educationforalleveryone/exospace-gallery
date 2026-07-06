<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">Affiliate Dashboard</h2>
    </x-slot>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8">

        {{-- Totals --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-gray-900 border border-gray-700 rounded-xl p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Total Referrals</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $totals['referrals'] }}</p>
            </div>
            <div class="bg-gray-900 border border-gray-700 rounded-xl p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Converted</p>
                <p class="text-2xl font-bold text-emerald-400 mt-1">{{ $totals['converted'] }}</p>
            </div>
            <div class="bg-gray-900 border border-gray-700 rounded-xl p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Conversion Rate</p>
                <p class="text-2xl font-bold text-blue-400 mt-1">{{ $totals['conversion_rate'] }}%</p>
            </div>
            <div class="bg-gray-900 border border-gray-700 rounded-xl p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Attributed Revenue</p>
                <p class="text-2xl font-bold text-amber-400 mt-1">${{ number_format($totals['revenue'], 2) }}</p>
            </div>
        </div>

        {{-- Per-affiliate table --}}
        @if(empty($affiliates))
            <div class="text-center py-16">
                <p class="text-gray-400">No affiliate referrals yet.</p>
                <p class="text-gray-600 text-sm mt-2">Set TWOCHECKOUT_AFFILIATE_ID or pass ?ref=AFFILIATE_ID on upgrade URLs to start tracking.</p>
            </div>
        @else
            <div class="bg-gray-900 border border-gray-700 rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase tracking-wider border-b border-gray-700">
                            <th class="py-3 px-4">Affiliate ID</th>
                            <th class="py-3 px-4 text-center">Referrals</th>
                            <th class="py-3 px-4 text-center">Converted</th>
                            <th class="py-3 px-4 text-center">Pending</th>
                            <th class="py-3 px-4 text-center">Conv. Rate</th>
                            <th class="py-3 px-4 text-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($affiliates as $a)
                        <tr class="border-b border-gray-800 last:border-0">
                            <td class="py-3 px-4 text-gray-200 font-mono text-xs">{{ $a['id'] }}</td>
                            <td class="py-3 px-4 text-center text-gray-300">{{ $a['total'] }}</td>
                            <td class="py-3 px-4 text-center text-emerald-400">{{ $a['converted'] }}</td>
                            <td class="py-3 px-4 text-center text-amber-400">{{ $a['pending'] }}</td>
                            <td class="py-3 px-4 text-center text-gray-300">{{ $a['conversion_rate'] }}%</td>
                            <td class="py-3 px-4 text-right text-gray-300">${{ number_format($a['revenue'], 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
