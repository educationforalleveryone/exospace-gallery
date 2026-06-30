<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.galleries.events.index', $gallery) }}" class="text-gray-400 hover:text-gray-200 transition text-sm">← Events</a>
            <span class="text-gray-600">/</span>
            <h2 class="font-semibold text-xl text-gray-100">RSVPs: {{ $event->title }}</h2>
        </div>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto px-4">

        <div class="bg-gray-800 border border-gray-700 rounded-xl p-5 mb-5">
            <p class="text-sm text-gray-400">{{ $event->starts_at->format('l, F j, Y \a\t g:i A') }}</p>
            <div class="grid grid-cols-3 gap-4 mt-3">
                <div>
                    <div class="text-2xl font-bold text-purple-400">{{ $rsvps->count() }}</div>
                    <div class="text-xs text-gray-500 uppercase tracking-wider">RSVPs</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-purple-400">{{ $event->capacity ?? '∞' }}</div>
                    <div class="text-xs text-gray-500 uppercase tracking-wider">Capacity</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-purple-400">{{ $event->capacity ? max(0, $event->capacity - $rsvps->count()) : '∞' }}</div>
                    <div class="text-xs text-gray-500 uppercase tracking-wider">Remaining</div>
                </div>
            </div>
        </div>

        @if($rsvps->count() > 0)
            <div class="bg-gray-800 border border-gray-700 rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-900/50 text-xs text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3 text-left">Name</th>
                            <th class="px-4 py-3 text-left">Email</th>
                            <th class="px-4 py-3 text-left">Confirmed at</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700/50">
                        @foreach($rsvps as $rsvp)
                            <tr>
                                <td class="px-4 py-3 text-gray-200">{{ $rsvp->name }}</td>
                                <td class="px-4 py-3 text-gray-400"><a href="mailto:{{ $rsvp->email }}" class="hover:text-purple-300">{{ $rsvp->email }}</a></td>
                                <td class="px-4 py-3 text-gray-500 text-xs">{{ $rsvp->confirmed_at->format('M j, Y g:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Export helper --}}
            <div class="mt-4 text-right">
                <button onclick="copyEmails()" class="text-xs text-purple-400 hover:text-purple-300 transition">Copy all emails</button>
            </div>
        @else
            <div class="text-center py-12 bg-gray-800/50 rounded-xl border border-gray-700/50">
                <p class="text-gray-400">No RSVPs yet.</p>
            </div>
        @endif
    </div>

    <script>
        function copyEmails() {
            const emails = @json($rsvps->pluck('email')->unique()->values());
            const text = emails.join(', ');
            navigator.clipboard.writeText(text).then(() => {
                alert('Copied ' + emails.length + ' email(s) to clipboard');
            }).catch(() => alert(text));
        }
    </script>
</x-app-layout>
