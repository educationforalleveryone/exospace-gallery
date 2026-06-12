<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">Venue Templates</h2>
    </x-slot>
    <div class="py-8 max-w-5xl mx-auto px-4">
        @if(session('status'))
            <div class="mb-4 text-sm text-green-400 bg-green-900/20 border border-green-700/30 rounded-lg px-4 py-3">{{ session('status') }}</div>
        @endif
        <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
            <table class="w-full text-sm text-gray-300">
                <thead class="bg-gray-900/50 text-xs text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 text-left">#</th>
                        <th class="px-4 py-3 text-left">Name</th>
                        <th class="px-4 py-3 text-left">Plan</th>
                        <th class="px-4 py-3 text-left">Galleries</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700/50">
                    @foreach($venues as $venue)
                    <tr class="{{ $venue->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 text-gray-500">{{ $venue->sort_order }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-200">{{ $venue->name }}</div>
                            <div class="text-xs text-gray-500 mt-0.5">{{ $venue->slug }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ match($venue->plan_required) { 'free' => 'bg-green-900/40 text-green-400', 'pro' => 'bg-purple-900/40 text-purple-400', default => 'bg-amber-900/40 text-amber-400' } }}">
                                {{ ucfirst($venue->plan_required) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-400">{{ $venue->galleries_count }}</td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('super.venues.toggle', $venue) }}">
                                @csrf @method('PATCH')
                                <button class="text-xs {{ $venue->is_active ? 'text-green-400 hover:text-red-400' : 'text-gray-500 hover:text-green-400' }} transition">
                                    {{ $venue->is_active ? '● Active' : '○ Inactive' }}
                                </button>
                            </form>
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('super.venues.edit', $venue) }}" class="text-xs text-purple-400 hover:text-purple-300 transition">Edit</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>