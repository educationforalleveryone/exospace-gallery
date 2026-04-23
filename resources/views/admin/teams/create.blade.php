<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">Create New Team</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
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
                        <label class="block text-sm font-medium text-gray-300 mb-2">Team Name <span class="text-red-400">*</span></label>
                        <input type="text" name="name" value="{{ old('name') }}" required maxlength="100"
                               placeholder="e.g. Studio Collective"
                               class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white placeholder-gray-400 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none transition">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Description <span class="text-gray-500">(optional)</span></label>
                        <textarea name="description" rows="3" maxlength="500"
                                  placeholder="What is this team for?"
                                  class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white placeholder-gray-400 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none transition resize-none">{{ old('description') }}</textarea>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button type="submit" class="flex-1 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-3 px-6 rounded-lg transition">
                            Create Team
                        </button>
                        <a href="{{ route('admin.teams.index') }}" class="px-6 py-3 bg-gray-700 hover:bg-gray-600 text-gray-300 rounded-lg transition font-medium">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
