<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">Feedback</h2>
    </x-slot>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8">

        {{-- Status filter tabs --}}
        <div class="flex gap-2 mb-6">
            <a href="{{ route('super.feedback.index') }}"
               class="px-4 py-2 rounded-lg text-sm font-medium transition {{ !request('status') ? 'bg-purple-600 text-white' : 'bg-gray-800 text-gray-400 hover:bg-gray-700' }}">
                All ({{ $counts['all'] }})
            </a>
            @foreach(['new', 'reviewed', 'resolved'] as $status)
            <a href="?status={{ $status }}"
               class="px-4 py-2 rounded-lg text-sm font-medium transition {{ request('status') === $status ? 'bg-purple-600 text-white' : 'bg-gray-800 text-gray-400 hover:bg-gray-700' }}">
                {{ ucfirst($status) }} ({{ $counts[$status] }})
            </a>
            @endforeach
        </div>

        {{-- Feedback list --}}
        @if($feedback->isEmpty())
            <div class="text-center py-16">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                <p class="text-gray-400">No feedback yet.</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($feedback as $item)
                <div class="bg-gray-900 border border-gray-700 rounded-xl p-5">
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <div>
                            <span class="text-sm font-semibold text-gray-200">{{ $item->categoryLabel() }}</span>
                            <span class="text-xs text-gray-500 ml-2">{{ $item->created_at->diffForHumans() }}</span>
                        </div>
                        <form method="POST" action="{{ route('super.feedback.update-status', $item) }}" class="flex gap-1">
                            @csrf
                            @method('PATCH')
                            <select name="status" data-change="submitForm"
                                    class="text-xs bg-gray-800 border border-gray-600 rounded-lg px-2 py-1 text-gray-300 focus:border-purple-500 outline-none">
                                @foreach(['new', 'reviewed', 'resolved'] as $status)
                                    <option value="{{ $status }}" {{ $item->status === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                        </form>
                    </div>

                    <p class="text-sm text-gray-300 whitespace-pre-wrap mb-3">{{ $item->message }}</p>

                    <div class="flex flex-wrap gap-3 text-xs text-gray-500">
                        @if($item->user)
                            <span>From: <span class="text-gray-400">{{ $item->user->name }}</span> ({{ $item->user->email }})</span>
                        @else
                            <span>From: <span class="text-gray-400">Anonymous</span></span>
                        @endif
                        @if($item->page_url)
                            <span>Page: <a href="{{ $item->page_url }}" target="_blank" class="text-purple-400 hover:text-purple-300">{{ Str::limit($item->page_url, 60) }}</a></span>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $feedback->links() }}
            </div>
        @endif
    </div>

    {{-- CSP-safe delegated change handler: submit the parent form --}}
    <script nonce="@nonce">
    window.submitForm = function(el, e) {
        if (el.form) el.form.submit();
    };
    </script>
</x-app-layout>
