<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-100 leading-tight">
                {{ __('Dashboard') }}
            </h2>
            <a href="{{ route('admin.galleries.create') }}" 
               class="bg-gradient-to-r from-purple-600 to-indigo-600 px-6 py-2 rounded-lg font-semibold hover:from-purple-700 hover:to-indigo-700 transition transform hover:scale-105">
                + Create Gallery
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <!-- Welcome Message -->
            <div class="mb-8">
                <h3 class="text-2xl font-bold text-gray-100 mb-2">Welcome back, {{ Auth::user()->name }}! 👋</h3>
                <p class="text-gray-400">Here's an overview of your galleries and activity.</p>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                
                <!-- Total Galleries -->
                <div class="bg-gray-800 overflow-hidden shadow-lg rounded-lg border border-gray-700 hover:border-purple-500 transition">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="bg-purple-600 w-12 h-12 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="text-3xl font-bold text-gray-100 mb-1">
                            {{ Auth::user()->galleries()->count() }}
                        </div>
                        <div class="text-sm text-gray-400">Total Galleries</div>
                    </div>
                </div>

                <!-- Total Views -->
                <div class="bg-gray-800 overflow-hidden shadow-lg rounded-lg border border-gray-700 hover:border-indigo-500 transition">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="bg-indigo-600 w-12 h-12 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="text-3xl font-bold text-gray-100 mb-1">
                            {{ Auth::user()->galleries()->sum('view_count') }}
                        </div>
                        <div class="text-sm text-gray-400">Total Views</div>
                    </div>
                </div>

                <!-- Usage Quota (Replaces Total Images) -->
                <div class="bg-gray-800 overflow-hidden shadow-lg rounded-lg border border-gray-700 hover:border-blue-500 transition">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="bg-blue-600 w-12 h-12 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                </svg>
                            </div>
                            @if(Auth::user()->plan === 'free')
                                <span class="text-xs bg-gray-700 text-gray-300 px-2 py-1 rounded-full">Free Plan</span>
                            @else
                                <span class="text-xs bg-purple-600 text-white px-2 py-1 rounded-full">Pro Plan</span>
                            @endif
                        </div>
                        
                        <div class="text-lg font-bold text-gray-100 mb-3">Your Usage</div>
                        
                        <!-- Gallery Quota -->
                        <div class="mb-3">
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="text-gray-400">Galleries</span>
                                <span class="text-gray-300 font-medium">
                                    {{ Auth::user()->galleries()->count() }} / {{ Auth::user()->max_galleries }}
                                </span>
                            </div>
                            <div class="w-full bg-gray-700 h-2 rounded-full overflow-hidden">
                                @php
                                    $galleryPercent = Auth::user()->max_galleries > 0 
                                        ? (Auth::user()->galleries()->count() / Auth::user()->max_galleries) * 100 
                                        : 0;
                                    $galleryPercent = min($galleryPercent, 100);
                                @endphp
                                <div class="bg-gradient-to-r from-purple-500 to-indigo-500 h-2 rounded-full transition-all duration-500" 
                                     style="width: {{ $galleryPercent }}%"></div>
                            </div>
                        </div>
                        
                        <!-- Image Quota -->
                        <div class="mb-3">
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="text-gray-400">Images Per Gallery</span>
                                <span class="text-gray-300 font-medium">
                                    Limit: {{ Auth::user()->max_images }}
                                </span>
                            </div>
                            <div class="w-full bg-gray-700 h-2 rounded-full overflow-hidden">
                                <div class="bg-gradient-to-r from-blue-500 to-cyan-500 h-2 rounded-full" 
                                     style="width: 100%"></div>
                            </div>
                        </div>
                        
                        <!-- Upgrade CTA (Free users only) -->
                        @if(Auth::user()->plan === 'free')
                            <div class="mt-4 pt-3 border-t border-gray-700">
                                <a href="/pricing" class="block text-center bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white text-sm font-semibold py-2 rounded-lg transition">
                                    Upgrade to Pro
                                </a>
                            </div>
                        @endif
                    </div>
                </div>

            </div>

            <!-- Quick Actions Card -->
            <div class="bg-gray-800 overflow-hidden shadow-lg rounded-lg border border-gray-700 mb-8">
                <div class="p-8 text-center">
                    <div class="mb-6">
                        <div class="bg-gradient-to-br from-purple-600 to-indigo-600 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-100 mb-2">Ready to Create?</h3>
                        <p class="text-gray-400 mb-6">Transform your images into immersive 3D galleries in minutes</p>
                    </div>
                    <a href="{{ route('admin.galleries.create') }}" 
                       class="inline-block bg-gradient-to-r from-purple-600 to-indigo-600 px-8 py-4 rounded-lg text-lg font-semibold hover:from-purple-700 hover:to-indigo-700 transition transform hover:scale-105">
                        Create Your First Gallery
                    </a>
                </div>
            </div>

            <!-- Recent Galleries -->
            @if(Auth::user()->galleries()->count() > 0)
            <div class="bg-gray-800 overflow-hidden shadow-lg rounded-lg border border-gray-700">
                <div class="p-6 border-b border-gray-700">
                    <h3 class="text-xl font-bold text-gray-100">Recent Galleries</h3>
                </div>
                <div class="divide-y divide-gray-700">
                    @foreach(Auth::user()->galleries()->latest()->take(5)->get() as $gallery)
                    <div class="p-6 hover:bg-gray-750 transition">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <h4 class="text-lg font-semibold text-gray-100 mb-1">{{ $gallery->title }}</h4>
                                <p class="text-sm text-gray-400 mb-2">{{ Str::limit($gallery->description, 100) }}</p>
                                <div class="flex items-center space-x-4 text-sm text-gray-500">
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        {{ $gallery->images()->count() }} images
                                    </span>
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                        {{ $gallery->view_count }} views
                                    </span>
                                    <span>{{ $gallery->created_at->diffForHumans() }}</span>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2 ml-4">
                                <a href="{{ route('gallery.view', $gallery->slug) }}" 
                                   target="_blank"
                                   class="bg-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-600 transition">
                                    View
                                </a>
                                <a href="{{ route('admin.galleries.edit', $gallery) }}" 
                                   class="bg-purple-600 px-4 py-2 rounded-lg text-sm hover:bg-purple-700 transition">
                                    Edit
                                </a>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>