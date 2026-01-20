<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('My Galleries') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <!-- Add button at the top -->
            <div class="mb-4 flex justify-end">
                <a href="{{ route('admin.galleries.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded inline-flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    New Gallery
                </a>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    
                    @if(session('status'))
                        <div class="mb-4 p-4 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-200 rounded">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if($galleries->count() > 0)
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach($galleries as $gallery)
                                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                    <h3 class="text-lg font-bold mb-2">{{ $gallery->title }}</h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                                        {{ $gallery->images->count() }} Images • {{ $gallery->view_count }} Views
                                    </p>
                                    <div class="flex gap-2 text-sm">
                                        <a href="{{ route('admin.galleries.edit', $gallery) }}" class="text-blue-500 hover:underline">Edit</a>
                                        <a href="{{ $gallery->public_url }}" target="_blank" class="text-green-500 hover:underline">View</a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-6">
                            {{ $galleries->links() }}
                        </div>
                    @else
                        <p class="text-gray-500 text-center py-10">No galleries found. Create your first one!</p>
                    @endif

                </div>
            </div>
        </div>
    </div>
</x-app-layout>