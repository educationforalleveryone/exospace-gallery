<x-app-layout>
    
    <!-- PHP Logic Block: Initialize variables required by the new changes -->
    @php
        $galleriesCount = Auth::user()->galleries()->count();
        $totalViews = Auth::user()->galleries()->sum('view_count');
        
        // Determine onboarding status based on logic inferred from Change #1
        $onboardingComplete = $galleriesCount > 0; 
    @endphp

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

    <div class="py-12" x-data="{ 
        showWelcome: !localStorage.getItem('exospace_onboarded') && {{ $galleriesCount }} === 0,
        dismissWelcome() {
            localStorage.setItem('exospace_onboarded', 'true');
            this.showWelcome = false;
        }
    }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <!-- Welcome Message -->
            <div class="mb-8">
                <h3 class="text-2xl font-bold text-gray-100 mb-2">Welcome back, {{ Auth::user()->name }}! 👋</h3>
                <p class="text-gray-400">Here's an overview of your galleries and activity.</p>
            </div>

            <!-- ✨ UPDATED: Progress Tracker for First-Time Users -->
            @if(!$onboardingComplete)
            <div class="mb-8 bg-gradient-to-r from-purple-900/30 to-indigo-900/30 border border-purple-500/30 rounded-xl p-6">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="bg-purple-600/20 w-10 h-10 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <h4 class="text-lg font-bold text-gray-100">Your Journey to Success</h4>
                        </div>
                        <p class="text-sm text-gray-300 mb-4">Complete these steps to unlock the full potential of Exospace</p>
                        
                        <!-- Progress Steps -->
                        <div class="space-y-3">
                            <!-- Step 1 (Account Creation - Always Done here) -->
                            <div class="flex items-center gap-3 text-sm">
                                <div class="flex-shrink-0 w-6 h-6 rounded-full bg-green-500/20 border-2 border-green-500 flex items-center justify-center">
                                    <svg class="w-3 h-3 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <span class="text-gray-300 line-through">Create your account</span>
                                <span class="text-green-400 text-xs font-semibold">✓ Done</span>
                            </div>

                            <!-- Step 2: Create Gallery (Dynamic) -->
                            <div class="flex items-center gap-3 text-sm">
                                @if($galleriesCount > 0)
                                    <div class="flex-shrink-0 w-6 h-6 rounded-full bg-green-500/20 border-2 border-green-500 flex items-center justify-center">
                                        <svg class="w-3 h-3 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <span class="text-gray-300 line-through">Create your first gallery</span>
                                    <span class="text-green-400 text-xs font-semibold">✓ Done</span>
                                @else
                                    <div class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-600 border-2 border-purple-400 flex items-center justify-center animate-pulse">
                                        <span class="text-white text-xs font-bold">2</span>
                                    </div>
                                    <span class="text-gray-100 font-semibold">Create your first gallery</span>
                                    <span class="text-purple-400 text-xs font-semibold">← You are here</span>
                                @endif
                            </div>

                            <!-- Step 3: Share Gallery (Dynamic) -->
                            <div class="flex items-center gap-3 text-sm">
                                @if($totalViews > 0)
                                    <div class="flex-shrink-0 w-6 h-6 rounded-full bg-green-500/20 border-2 border-green-500 flex items-center justify-center">
                                        <svg class="w-3 h-3 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <span class="text-gray-300 line-through">Share your 3D exhibition</span>
                                    <span class="text-green-400 text-xs font-semibold">✓ Done</span>
                                @elseif($galleriesCount > 0)
                                    <div class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-600 border-2 border-purple-400 flex items-center justify-center animate-pulse">
                                        <span class="text-white text-xs font-bold">3</span>
                                    </div>
                                    <span class="text-gray-100 font-semibold">Share your 3D exhibition</span>
                                    <span class="text-purple-400 text-xs font-semibold">← You are here</span>
                                @else
                                    <div class="flex-shrink-0 w-6 h-6 rounded-full bg-gray-700 border-2 border-gray-600 flex items-center justify-center">
                                        <span class="text-gray-400 text-xs font-bold">3</span>
                                    </div>
                                    <span class="text-gray-400">Share your 3D exhibition</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Smart Button -->
                    @if(auth()->user()->canCreateGallery())
                        <a href="{{ route('admin.galleries.create') }}" 
                           class="flex-shrink-0 bg-purple-600 hover:bg-purple-700 text-white font-semibold px-6 py-3 rounded-lg transition transform hover:scale-105 text-sm">
                            Continue →
                        </a>
                    @else
                        <button onclick="document.getElementById('upgrade-modal').style.display='flex'" 
                                class="flex-shrink-0 bg-orange-600 hover:bg-orange-700 text-white font-semibold px-6 py-3 rounded-lg transition transform hover:scale-105 text-sm">
                            Upgrade to Create More →
                        </button>
                    @endif
                </div>
            </div>
            @endif

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
                            {{ $galleriesCount }}
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
                            {{ $totalViews }}
                        </div>
                        <div class="text-sm text-gray-400">Total Views</div>
                    </div>
                </div>

                <!-- Usage Quota -->
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
                                    {{ $galleriesCount }} / {{ Auth::user()->max_galleries }}
                                </span>
                            </div>
                            <div class="w-full bg-gray-700 h-2 rounded-full overflow-hidden">
                                @php
                                    $galleryPercent = Auth::user()->max_galleries > 0 
                                        ? ($galleriesCount / Auth::user()->max_galleries) * 100 
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
            @if($galleriesCount > 0)
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

        <!-- ONE-TIME WELCOME MODAL -->
        <div x-show="showWelcome" 
             x-cloak
             @click.self="dismissWelcome()"
             class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4"
             style="display: none;"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0">
            
            <div @click.stop 
                 class="bg-gradient-to-br from-gray-800 via-gray-900 to-purple-900/30 border border-purple-500/30 rounded-2xl max-w-2xl w-full shadow-2xl"
                 x-transition:enter="transition ease-out duration-300 delay-100"
                 x-transition:enter-start="opacity-0 scale-90"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-90">
                
                <!-- Header with animated gradient -->
                <div class="relative overflow-hidden rounded-t-2xl bg-gradient-to-r from-purple-600 to-indigo-600 p-8 text-center">
                    <div class="absolute inset-0 bg-gradient-to-r from-purple-600 via-pink-500 to-indigo-600 animate-pulse opacity-50"></div>
                    <div class="relative">
                        <div class="inline-block mb-4">
                            <div class="bg-white/20 backdrop-blur-sm w-20 h-20 rounded-2xl flex items-center justify-center">
                                <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                                </svg>
                            </div>
                        </div>
                        <h2 class="text-3xl font-bold text-white mb-3">Welcome to the Future of Art Exhibitions! 🎨</h2>
                        <p class="text-purple-100 text-lg">Your journey to creating stunning 3D galleries starts now</p>
                    </div>
                </div>
                
                <!-- Content -->
                <div class="p-8">
                    <div class="mb-8">
                        <h3 class="text-xl font-bold text-gray-100 mb-4">Here's what you get with your account:</h3>
                        
                        <div class="space-y-4">
                            <div class="flex items-start gap-4">
                                <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-purple-600/20 border border-purple-500/30 flex items-center justify-center">
                                    <svg class="w-5 h-5 text-purple-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-semibold text-gray-100 mb-1">{{ Auth::user()->max_galleries }} Free Galleries</h4>
                                    <p class="text-sm text-gray-400">Create multiple exhibitions to showcase different collections</p>
                                </div>
                            </div>
                            
                            <div class="flex items-start gap-4">
                                <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-indigo-600/20 border border-indigo-500/30 flex items-center justify-center">
                                    <svg class="w-5 h-5 text-indigo-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-semibold text-gray-100 mb-1">{{ Auth::user()->max_images }} Images per Gallery</h4>
                                    <p class="text-sm text-gray-400">Plenty of space to showcase your best work in stunning detail</p>
                                </div>
                            </div>
                            
                            <div class="flex items-start gap-4">
                                <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-blue-600/20 border border-blue-500/30 flex items-center justify-center">
                                    <svg class="w-5 h-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-semibold text-gray-100 mb-1">Immersive 3D Experience</h4>
                                    <p class="text-sm text-gray-400">Walk through your galleries like a real museum</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Start -->
                    <div class="bg-purple-900/20 border border-purple-500/20 rounded-xl p-5 mb-6">
                        <h4 class="font-semibold text-purple-300 mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                            Quick Start Guide
                        </h4>
                        <ol class="text-sm text-purple-200/80 space-y-2 ml-7">
                            <li class="list-decimal">Click "Create Gallery" to start your first exhibition</li>
                            <li class="list-decimal">Give it a name and description that represents your art</li>
                            <li class="list-decimal">Upload your images and watch them transform into 3D</li>
                            <li class="list-decimal">Share your gallery link with the world!</li>
                        </ol>
                    </div>
                    
                    <!-- CTA Buttons -->
                    <div class="flex gap-3">
                        <a href="{{ route('admin.galleries.create') }}" 
                           @click="dismissWelcome()"
                           class="flex-1 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold py-4 rounded-xl transition-all transform hover:scale-105 text-center">
                            Let's Go! 🚀
                        </a>
                        <button @click="dismissWelcome()" 
                                class="px-6 bg-gray-700 hover:bg-gray-600 text-gray-300 font-semibold py-4 rounded-xl transition">
                            I'll Explore First
                        </button>
                    </div>
                    
                    @if(Auth::user()->plan === 'free')
                    <p class="mt-4 text-center text-xs text-gray-500">
                        Want unlimited galleries? <a href="/pricing" class="text-purple-400 hover:text-purple-300 underline">Upgrade to Pro</a>
                    </p>
                    @endif
                </div>
                
            </div>
        </div>

        <!-- ✨ NEW: UPGRADE MODAL (For Change #4 functionality) -->
        <div id="upgrade-modal" 
             class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden items-center justify-center p-4"
             style="display: none;">
            
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 border border-orange-500/30 rounded-2xl max-w-md w-full shadow-2xl relative overflow-hidden">
                <!-- Close Button -->
                <button onclick="document.getElementById('upgrade-modal').style.display='none'" 
                        class="absolute top-4 right-4 text-gray-400 hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>

                <div class="p-8 text-center">
                    <div class="bg-orange-600/20 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                    </div>
                    
                    <h3 class="text-2xl font-bold text-white mb-2">Upgrade Your Plan</h3>
                    <p class="text-gray-400 mb-6">You've reached your gallery limit. Upgrade to Pro today to create unlimited 3D exhibitions.</p>
                    
                    <div class="space-y-3">
                        <a href="/pricing" 
                           class="block w-full bg-gradient-to-r from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600 text-white font-bold py-3 rounded-xl transition">
                            View Pricing Plans
                        </a>
                        <button onclick="document.getElementById('upgrade-modal').style.display='none'" 
                                class="block w-full bg-gray-700 hover:bg-gray-600 text-gray-300 font-semibold py-3 rounded-xl transition">
                            Maybe Later
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>