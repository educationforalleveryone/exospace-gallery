<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-100 leading-tight">
                {{ __('My Galleries') }}
            </h2>
            <a href="{{ route('admin.galleries.create') }}" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-2 px-6 rounded-lg transition inline-flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                New Gallery
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @if(session('status'))
                <div class="mb-6 p-4 bg-green-900 border border-green-700 text-green-200 rounded-lg">
                    {{ session('status') }}
                </div>
            @endif

            @if($galleries->count() > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($galleries as $gallery)
                        <div class="bg-gray-800 border border-gray-700 rounded-lg p-6 hover:border-purple-500 transition shadow-lg">
                            <div class="mb-4">
                                <h3 class="text-xl font-bold text-gray-100 mb-2">{{ $gallery->title }}</h3>
                                <p class="text-sm text-gray-400 mb-4 line-clamp-2">
                                    {{ $gallery->description ?: 'No description' }}
                                </p>
                            </div>
                            
                            <div class="flex items-center space-x-4 text-sm text-gray-500 mb-4">
                                <span class="flex items-center">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                    {{ $gallery->images->count() }} images
                                </span>
                                <span class="flex items-center">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                    {{ $gallery->view_count }} views
                                </span>
                            </div>
                            
                            <div class="flex gap-2">
                                <a href="{{ route('gallery.view', $gallery->slug) }}" target="_blank" 
                                   class="flex-1 bg-gray-700 hover:bg-gray-600 text-center text-gray-100 font-semibold py-2 px-4 rounded-lg transition">
                                    View
                                </a>
                                <a href="{{ route('admin.galleries.edit', $gallery) }}" 
                                   class="flex-1 bg-purple-600 hover:bg-purple-700 text-center text-white font-semibold py-2 px-4 rounded-lg transition">
                                    Edit
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
                
                <div class="mt-6">
                    {{ $galleries->links() }}
                </div>
            @else
                <div class="bg-gray-800 border border-gray-700 rounded-lg p-12 text-center">
                    <div class="mb-6">
                        <svg class="w-20 h-20 mx-auto text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-gray-300 mb-2">No galleries yet</h3>
                    <p class="text-gray-500 mb-6">Create your first 3D gallery to get started</p>
                    <a href="{{ route('admin.galleries.create') }}" class="inline-block bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-3 px-8 rounded-lg transition">
                        Create Your First Gallery
                    </a>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>