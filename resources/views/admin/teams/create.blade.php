<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Create New Team" :back="route('admin.teams.index')" backLabel="All teams"/>
    </x-slot>

    <div class="page-shell-narrow">
            <div class="bg-gray-800 border border-gray-700 rounded-xl p-8">

                @if($errors->any())
                    <div class="mb-6 p-4 bg-red-900/50 border border-red-700 text-red-300 rounded-lg">
                        <ul class="list-disc list-inside space-y-1 text-sm">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('admin.teams.store') }}" method="POST" class="space-y-6">
                    @csrf

                    <div>
                        <label for="name" class="label-text mb-1.5">Team Name <span class="text-red-400" aria-hidden="true">*</span></label>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="100" aria-required="true"
                               placeholder="e.g. Studio Collective"
                               class="input-base">
                    </div>

                    <div>
                        <label for="description" class="label-text mb-1.5">Description <span class="text-gray-500">(optional)</span></label>
                        <textarea name="description" id="description" rows="3" maxlength="500"
                                  placeholder="What is this team for?"
                                  class="input-base resize-none">{{ old('description') }}</textarea>
                    </div>

                    <div class="bg-gray-700/30 border border-gray-600/50 rounded-lg p-4 text-xs text-gray-400 space-y-1.5">
                        <p class="font-medium text-gray-300 text-sm mb-2">What happens after you create a team:</p>
                        <p class="flex items-center gap-2"><span class="text-green-400">✓</span> You become the team owner</p>
                        <p class="flex items-center gap-2"><span class="text-green-400">✓</span> The team gets its own gallery workspace, separate from your personal galleries</p>
                        <p class="flex items-center gap-2"><span class="text-green-400">✓</span> You can invite collaborators immediately</p>
                        <p class="flex items-center gap-2"><span class="text-green-400">✓</span> Team galleries use the owner's plan limits</p>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button type="submit" id="create-team-btn"
                                class="btn btn-primary flex-1 disabled:opacity-60">
                            <svg id="create-team-spinner" class="hidden animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span id="create-team-label">Create Team</span>
                        </button>
                        <a href="{{ route('admin.teams.index') }}" class="px-6 py-3 bg-gray-700 hover:bg-gray-600 text-gray-300 rounded-lg transition font-medium">
                            Cancel
                        </a>
                    </div>

                    <script nonce="@nonce">
                    document.querySelector('form').addEventListener('submit', function() {
                        const btn = document.getElementById('create-team-btn');
                        btn.disabled = true;
                        document.getElementById('create-team-spinner').classList.remove('hidden');
                        document.getElementById('create-team-label').textContent = 'Creating…';
                    });
                    </script>
                </form>
            </div>
        </div>

</x-app-layout>
