<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $user->name }}'s Galleries - Master Control</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-black to-gray-900 text-white min-h-screen">
    
    <!-- Header -->
    <div class="bg-black/50 backdrop-blur-md border-b border-red-500/30">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex justify-between items-center">
                <div>
                    <a href="{{ route('super.index') }}" class="text-gray-400 hover:text-white text-sm mb-2 inline-block">
                        ← Back to Master Control
                    </a>
                    <h1 class="text-3xl font-bold">{{ $user->name }}'s Galleries</h1>
                    <p class="text-gray-400 text-sm">{{ $user->email }} • {{ strtoupper($user->plan) }} Plan</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Message -->
    @if(session('success'))
        <div class="max-w-7xl mx-auto px-6 mt-4">
            <div class="bg-green-500/20 border border-green-500 text-green-300 px-4 py-3 rounded-lg">
                ✅ {{ session('success') }}
            </div>
        </div>
    @endif

    <!-- Galleries List -->
    <div class="max-w-7xl mx-auto px-6 py-8">
        @if($galleries->count() === 0)
            <div class="bg-gray-800/30 border border-gray-700 rounded-lg p-12 text-center">
                <div class="text-6xl mb-4">🎨</div>
                <h3 class="text-xl font-bold mb-2">No Galleries Yet</h3>
                <p class="text-gray-400">This user hasn't created any galleries.</p>
            </div>
        @else
            <div class="grid gap-6">
                @foreach($galleries as $gallery)
                    <div class="bg-black/40 border border-gray-700 rounded-lg p-6 hover:border-gray-600 transition">
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <div class="flex items-center gap-3 mb-2">
                                    <h3 class="text-2xl font-bold">{{ $gallery->title }}</h3>
                                    @if($gallery->is_active)
                                        <span class="px-3 py-1 bg-green-600 text-xs rounded-full">ACTIVE</span>
                                    @else
                                        <span class="px-3 py-1 bg-red-600 text-xs rounded-full">INACTIVE</span>
                                    @endif
                                </div>
                                
                                @if($gallery->description)
                                    <p class="text-gray-400 mb-4">{{ $gallery->description }}</p>
                                @endif

                                <div class="flex gap-6 text-sm text-gray-400">
                                    <div>🖼️ {{ $gallery->images_count }} images</div>
                                    <div>👁️ {{ number_format($gallery->view_count) }} views</div>
                                    <div>🕐 Created {{ $gallery->created_at->diffForHumans() }}</div>
                                </div>

                                <div class="mt-4 flex gap-2">
                                    <a href="{{ route('gallery.view', $gallery->slug) }}" 
                                       target="_blank"
                                       class="px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm transition">
                                        👁️ View Gallery
                                    </a>
                                    
                                    <form method="POST" action="{{ route('super.toggleGallery', $gallery) }}" class="inline">
                                        @csrf
                                        <button type="submit" 
                                                class="px-4 py-2 {{ $gallery->is_active ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700' }} rounded-lg text-sm transition">
                                            {{ $gallery->is_active ? '🔒 Deactivate' : '✅ Activate' }}
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="ml-6">
                                <div class="text-right text-sm text-gray-400">
                                    <div>Wall: {{ ucfirst($gallery->wall_texture) }}</div>
                                    <div>Frame: {{ ucfirst($gallery->frame_style) }}</div>
                                    <div>Lighting: {{ ucfirst($gallery->lighting_preset) }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Gallery Images Preview -->
                        @if($gallery->images->count() > 0)
                            <div class="mt-6 pt-6 border-t border-gray-700">
                                <h4 class="text-sm font-semibold mb-3 text-gray-400">IMAGES</h4>
                                <div class="grid grid-cols-6 gap-2">
                                    @foreach($gallery->images->take(12) as $image)
                                        <div class="aspect-square bg-gray-800 rounded overflow-hidden">
                                            <img src="{{ asset($image->path) }}" 
                                                 alt="{{ $image->title }}"
                                                 class="w-full h-full object-cover">
                                        </div>
                                    @endforeach
                                    @if($gallery->images->count() > 12)
                                        <div class="aspect-square bg-gray-800 rounded flex items-center justify-center text-gray-400">
                                            +{{ $gallery->images->count() - 12 }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

</body>
</html>