<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'RSVPs: '.$event->title" :back="route('admin.galleries.events.index', $gallery)" backLabel="Events"/>
    </x-slot>

    <div class="page-shell-mid">

        <div class="bg-gray-800 border border-gray-700 rounded-xl p-5 mb-5">
            <p class="text-sm text-gray-400">{{ $event->starts_at->format('l, F j, Y \a\t g:i A') }}</p>
            <div class="grid grid-cols-3 gap-4 mt-3">
                <div>
                    <div class="text-2xl font-semibold text-gray-50 text-numeric">{{ $rsvps->count() }}</div>
                    <div class="text-xs text-gray-500 uppercase tracking-wider">RSVPs</div>
                </div>
                <div>
                    <div class="text-2xl font-semibold text-gray-50 text-numeric">{{ $event->capacity ?? '∞' }}</div>
                    <div class="text-xs text-gray-500 uppercase tracking-wider">Capacity</div>
                </div>
                <div>
                    <div class="text-2xl font-semibold text-gray-50 text-numeric">{{ $event->capacity ? max(0, $event->capacity - $rsvps->count()) : '∞' }}</div>
                    <div class="text-xs text-gray-500 uppercase tracking-wider">Remaining</div>
                </div>
            </div>
        </div>

        @if($rsvps->count() > 0)
            <div class="table-wrap">
                <table class="table-base min-w-[540px]">
                    <thead class="table-head">
                        <tr>
                            <th class="table-head-cell">Name</th>
                            <th class="table-head-cell">Email</th>
                            <th class="table-head-cell">Confirmed at</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700/50">
                        @foreach($rsvps as $rsvp)
                            <tr class="table-row-base">
                                <td class="table-cell text-gray-200">{{ $rsvp->name }}</td>
                                <td class="table-cell text-gray-400"><a href="mailto:{{ $rsvp->email }}" class="hover:text-brand-300">{{ $rsvp->email }}</a></td>
                                <td class="table-cell text-gray-500 text-xs">{{ $rsvp->confirmed_at->format('M j, Y g:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Export helper --}}
            <div class="mt-4 flex justify-end">
                <button data-click="copyEmails" class="btn btn-sm btn-secondary">Copy all emails</button>
            </div>
        @else
            <div class="empty-state bg-gray-800/50 rounded-xl border border-gray-700/50">
                <svg class="w-10 h-10 text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <p class="text-gray-300 text-sm font-medium">No RSVPs yet</p>
                <p class="text-gray-500 text-xs mt-1 max-w-xs">Visitors can reserve a spot from the gallery's event schedule. Responses will appear here as they come in.</p>
            </div>
        @endif
    </div>

    <script nonce="@nonce">
        function copyEmails() {
            const emails = @json($rsvps->pluck('email')->unique()->values());
            const text = emails.join(', ');
            navigator.clipboard.writeText(text).then(() => {
                window.toast('Copied ' + emails.length + ' email(s) to clipboard', 'success');
            }).catch(() => {
                // Clipboard unavailable — select the text is not possible here, tell the user.
                window.toast('Clipboard unavailable — please copy the emails manually', 'error');
            });
        }
    </script>
</x-app-layout>
