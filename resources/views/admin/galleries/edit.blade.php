<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">
            Edit Gallery: {{ $gallery->title }}
        </h2>
    </x-slot>

    <!-- Dropzone CSS -->
    <link rel="stylesheet" href="https://unpkg.com/dropzone@5/dist/min/dropzone.min.css" type="text/css" />
    
    <!-- Custom Premium Styles -->
    <style>
        /* Dropzone Drag Hover State */
        .dropzone.dz-drag-hover {
            border-color: #a855f7 !important;
            background: rgba(168, 85, 247, 0.05) !important;
        }
        
        /* Custom scrollbar for dark theme */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #1f2937; 
        }
        ::-webkit-scrollbar-thumb {
            background: #4b5563; 
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #6b7280; 
        }
        
        /* Smooth transitions for cards */
        .gallery-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Custom checkbox styling */
        .custom-checkbox:checked {
            background-color: #9333ea;
            border-color: #9333ea;
        }
    </style>

    <div class="py-12 bg-gray-900 min-h-screen">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            @if(session('status'))
                <div class="bg-green-900/50 border border-green-700 text-green-100 p-4 rounded-lg">
                    {{ session('status') }}
                </div>
            @endif

            <!-- 1. Gallery Settings -->
            <div class="bg-gray-800 border border-gray-700 shadow-lg sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-100 mb-4">Gallery Settings</h3>
                
                <form action="{{ route('admin.galleries.update', $gallery) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Title -->
                        <div class="mb-4 md:col-span-2">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Title</label>
                            <input type="text" name="title" value="{{ old('title', $gallery->title) }}" required
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors">
                        </div>

                        <!-- Description -->
                        <div class="mb-4 md:col-span-2">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Description</label>
                            <textarea name="description" rows="3"
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors">{{ old('description', $gallery->description) }}</textarea>
                        </div>

                        <!-- Wall Texture -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Wall Texture</label>
                            <select name="wall_texture" required
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors">
                                <option value="white" {{ $gallery->wall_texture == 'white' ? 'selected' : '' }}>White Museum</option>
                                <option value="concrete" {{ $gallery->wall_texture == 'concrete' ? 'selected' : '' }}>Concrete</option>
                                <option value="brick" {{ $gallery->wall_texture == 'brick' ? 'selected' : '' }}>Brick</option>
                                <option value="wood" {{ $gallery->wall_texture == 'wood' ? 'selected' : '' }}>Wood</option>
                            </select>
                        </div>

                        <!-- Floor Material -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Floor Material</label>
                            <select name="floor_material" required
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors">
                                <option value="wood" {{ $gallery->floor_material == 'wood' ? 'selected' : '' }}>Wood</option>
                                <option value="marble" {{ $gallery->floor_material == 'marble' ? 'selected' : '' }}>Marble</option>
                                <option value="concrete" {{ $gallery->floor_material == 'concrete' ? 'selected' : '' }}>Concrete</option>
                            </select>
                        </div>

                        <!-- Frame Style -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Frame Style</label>
                            <select name="frame_style" required
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors">
                                <option value="modern" {{ $gallery->frame_style == 'modern' ? 'selected' : '' }}>Modern (Black)</option>
                                <option value="classic" {{ $gallery->frame_style == 'classic' ? 'selected' : '' }}>Classic (Gold)</option>
                                <option value="minimal" {{ $gallery->frame_style == 'minimal' ? 'selected' : '' }}>Minimal (Frameless)</option>
                            </select>
                        </div>

                        <!-- Lighting -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Lighting</label>
                            <select name="lighting_preset" required
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors">
                                <option value="bright" {{ $gallery->lighting_preset == 'bright' ? 'selected' : '' }}>Bright</option>
                                <option value="moody" {{ $gallery->lighting_preset == 'moody' ? 'selected' : '' }}>Moody</option>
                                <option value="dramatic" {{ $gallery->lighting_preset == 'dramatic' ? 'selected' : '' }}>Dramatic</option>
                            </select>
                        </div>
                    </div>

                    <!-- CHANGE #1: AJAX Audio Upload Section -->
                    <!-- Background Music (Pro Feature) -->
                    <div class="mb-6 mt-6 p-6 bg-gray-900/50 rounded-lg border border-gray-600">
                        <label class="block text-sm font-medium text-gray-300 mb-3">
                            🎵 Background Music
                            @if(!auth()->user()->isPro())
                                <span class="text-xs bg-purple-600 text-white px-2 py-0.5 rounded-full ml-2">Pro Only</span>
                            @endif
                        </label>
                        
                        @if(auth()->user()->isPro())
                            <!-- ✅ AJAX Upload Container -->
                            <div class="space-y-3">
                                <!-- Current Audio Preview -->
                                <div id="audio-preview-container" @if($gallery->audio_path) @else style="display:none;" @endif class="bg-gray-700 rounded-lg p-3 flex items-center justify-between mb-3">
                                    <div class="flex items-center">
                                        <svg class="w-5 h-5 text-purple-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                        </svg>
                                        <span id="audio-filename" class="text-sm text-gray-300">
                                            @if($gallery->audio_path)
                                                {{ basename($gallery->audio_path) }}
                                            @else
                                                No audio uploaded
                                            @endif
                                        </span>
                                    </div>
                                    <audio id="audio-player" controls class="h-8">
                                        @if($gallery->audio_path)
                                            <source src="{{ asset('storage/' . $gallery->audio_path) }}" type="audio/mpeg">
                                        @endif
                                    </audio>
                                </div>
                                
                                <!-- ✅ Upload Input -->
                                <div class="relative">
                                    <input type="file" id="audio-upload-input" accept=".mp3,.wav,.m4a"
                                        onchange="uploadAudioFile(this)"
                                        class="block w-full text-sm text-gray-300
                                            file:mr-4 file:py-2 file:px-4
                                            file:rounded-lg file:border-0
                                            file:text-sm file:font-semibold
                                            file:bg-purple-600 file:text-white
                                            hover:file:bg-purple-700
                                            cursor-pointer">
                                    
                                    <!-- ✅ Progress Bar (Hidden by default) -->
                                    <div id="audio-upload-progress" style="display:none;" class="mt-2">
                                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                                            <span id="audio-progress-text">Uploading...</span>
                                            <span id="audio-progress-percent">0%</span>
                                        </div>
                                        <div class="w-full h-2 bg-gray-700 rounded-full overflow-hidden">
                                            <div id="audio-progress-bar" class="h-full bg-gradient-to-r from-purple-500 to-indigo-500 transition-all duration-300" style="width: 0%"></div>
                                        </div>
                                    </div>
                                    
                                    <!-- ✅ Success Message (Hidden by default) -->
                                    <div id="audio-upload-success" style="display:none;" class="mt-2 p-2 bg-green-900/50 border border-green-700 rounded text-green-300 text-sm">
                                        ✅ <span id="audio-success-message">Audio uploaded successfully!</span>
                                    </div>
                                    
                                    <!-- ✅ Error Message (Hidden by default) -->
                                    <div id="audio-upload-error" style="display:none;" class="mt-2 p-2 bg-red-900/50 border border-red-700 rounded text-red-300 text-sm">
                                        ❌ <span id="audio-error-message">Upload failed</span>
                                    </div>
                                </div>
                                
                                <p class="text-xs text-gray-500 mt-2">MP3, WAV, or M4A • Max 10MB • Upload happens instantly</p>
                            </div>
                        @else
                            <!-- Show locked state for Free users -->
                            <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-6 text-center">
                                <svg class="w-12 h-12 text-gray-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                <p class="text-gray-400 mb-4 text-sm">Background music is a <strong>Pro feature</strong></p>
                                <p class="text-gray-500 text-xs mb-4">Add ambient soundtracks to create immersive 3D experiences</p>
                                <a href="/pricing" class="inline-block bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-2 px-6 rounded-lg transition text-sm">
                                    Upgrade to Pro - $29
                                </a>
                            </div>
                        @endif
                    </div>

                    <!-- CHANGE #2: AJAX Logo Upload Section -->
                    <!-- Custom Branding (Studio Feature) -->
                    <div class="mb-6 mt-6 p-6 bg-gray-900/50 rounded-lg border border-gray-600">
                        <label class="block text-sm font-medium text-gray-300 mb-3">
                            🎨 Custom Logo
                            @if(auth()->user()->plan !== 'studio')
                                <span class="text-xs bg-purple-600 text-white px-2 py-0.5 rounded-full ml-2">Studio Only</span>
                            @endif
                        </label>
                        
                        @if(auth()->user()->plan === 'studio')
                            <!-- ✅ AJAX Upload Container -->
                            <div class="space-y-3">
                                <!-- Current Logo Preview -->
                                <div id="logo-preview-container" @if($gallery->custom_logo_path) @else style="display:none;" @endif class="bg-gray-700 rounded-lg p-3 mb-3 flex items-center justify-center">
                                    <img id="logo-preview-image" 
                                         src="{{ $gallery->custom_logo_path ? asset('storage/' . $gallery->custom_logo_path) : '' }}" 
                                         alt="Custom Logo" 
                                         class="max-h-20 object-contain">
                                </div>
                                
                                <!-- ✅ Upload Input -->
                                <div class="relative">
                                    <input type="file" id="logo-upload-input" accept=".png,.svg,.jpg,.jpeg"
                                        onchange="uploadLogoFile(this)"
                                        class="block w-full text-sm text-gray-300
                                            file:mr-4 file:py-2 file:px-4
                                            file:rounded-lg file:border-0
                                            file:text-sm file:font-semibold
                                            file:bg-purple-600 file:text-white
                                            hover:file:bg-purple-700
                                            cursor-pointer">
                                    
                                    <!-- ✅ Progress Bar (Hidden by default) -->
                                    <div id="logo-upload-progress" style="display:none;" class="mt-2">
                                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                                            <span id="logo-progress-text">Uploading...</span>
                                            <span id="logo-progress-percent">0%</span>
                                        </div>
                                        <div class="w-full h-2 bg-gray-700 rounded-full overflow-hidden">
                                            <div id="logo-progress-bar" class="h-full bg-gradient-to-r from-purple-500 to-indigo-500 transition-all duration-300" style="width: 0%"></div>
                                        </div>
                                    </div>
                                    
                                    <!-- ✅ Success Message (Hidden by default) -->
                                    <div id="logo-upload-success" style="display:none;" class="mt-2 p-2 bg-green-900/50 border border-green-700 rounded text-green-300 text-sm">
                                        ✅ <span id="logo-success-message">Logo uploaded successfully!</span>
                                    </div>
                                    
                                    <!-- ✅ Error Message (Hidden by default) -->
                                    <div id="logo-upload-error" style="display:none;" class="mt-2 p-2 bg-red-900/50 border border-red-700 rounded text-red-300 text-sm">
                                        ❌ <span id="logo-error-message">Upload failed</span>
                                    </div>
                                </div>
                                
                                <p class="text-xs text-gray-500 mt-2">PNG, SVG, JPG • Max 2MB • Transparent background recommended • Upload happens instantly</p>
                            </div>
                        @else
                            <!-- Locked State for Free/Pro Users -->
                            <div class="relative">
                                <div class="bg-gray-800/30 rounded-lg p-6 border-2 border-dashed border-gray-700 text-center opacity-60">
                                    <svg class="w-16 h-16 text-gray-600 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                    <p class="text-gray-500 text-sm mb-4">Replace "Exospace" branding with your own logo</p>
                                </div>
                                
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <a href="/pricing" 
                                       class="bg-gradient-to-r from-orange-600 to-red-600 hover:from-orange-700 hover:to-red-700 text-white font-bold px-8 py-3 rounded-lg shadow-lg transform hover:scale-105 transition-all">
                                        <div class="flex items-center gap-2">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                            </svg>
                                            <span>Upgrade to Studio</span>
                                        </div>
                                    </a>
                                </div>
                            </div>
                            
                            <div class="mt-4 bg-orange-900/20 border border-orange-700/30 rounded-lg p-4">
                                <h4 class="text-orange-300 font-semibold text-sm mb-2">🌟 Studio Plan Benefits</h4>
                                <ul class="text-xs text-orange-300/80 space-y-1 ml-4">
                                    <li>• White-label your galleries with custom branding</li>
                                    <li>• Remove "Exospace" watermark completely</li>
                                    <li>• Professional presentation for clients</li>
                                    <li>• Perfect for agencies and professional artists</li>
                                </ul>
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-700">
                        <a href="{{ route('admin.galleries.index') }}" class="bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white px-4 py-2 rounded-lg transition-colors">
                            Cancel
                        </a>
                        <button type="submit" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white px-6 py-2 rounded-lg font-medium shadow-lg shadow-purple-900/30 transition-all transform hover:scale-[1.02]">
                            Update Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- 2. Image Upload Area -->
            <div class="bg-gray-800 border border-gray-700 shadow-lg sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-100 mb-4">Upload Artworks</h3>
                
                <form action="{{ route('admin.images.store', $gallery) }}" 
                      class="dropzone border-dashed border-2 border-gray-600 rounded-lg bg-gray-750/50 hover:bg-gray-750 transition-all duration-300 cursor-pointer" 
                      id="image-upload-dropzone">
                    @csrf
                </form>
            </div>

            <!-- 3. Existing Images Grid -->
            <div class="bg-gray-800 border border-gray-700 shadow-lg sm:rounded-lg p-6">
                <!-- 3A: Updated Header with Bulk Action Button and Select All -->
                <div class="flex justify-between items-center mb-6">
                    <div class="flex items-center gap-4">
                        <h3 class="text-lg font-medium text-gray-100">
                            Current Images ({{ $gallery->images->count() }})
                        </h3>
                        @if($gallery->images->count() > 0)
                            <label class="flex items-center gap-2 text-sm text-gray-400 cursor-pointer hover:text-purple-400 transition-colors select-none">
                                <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll()" 
                                       class="w-4 h-4 rounded border-gray-600 bg-gray-700 text-purple-600 focus:ring-2 focus:ring-purple-500 focus:ring-offset-0 focus:ring-offset-gray-800">
                                <span>Select All</span>
                            </label>
                        @endif
                    </div>
                    <button id="bulk-delete-btn" onclick="bulkDelete()" style="display: none;" 
                            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-semibold transition-all shadow-lg shadow-red-900/20 flex items-center gap-2 transform hover:scale-[1.02]">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        Delete Selected (<span id="selected-count">0</span>)
                    </button>
                </div>
                
                @if($gallery->images->count() > 0)
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-5" id="gallery-grid">
                        @foreach($gallery->images as $image)
                            <div class="gallery-card relative group bg-gray-900 border border-gray-700 rounded-lg overflow-hidden hover:border-purple-500 hover:-translate-y-1 hover:shadow-xl hover:shadow-purple-900/20" id="image-{{ $image->id }}">
                                
                                <!-- 3B: Selection Checkbox -->
                                <div class="absolute top-3 left-3 z-20 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                                    <input type="checkbox" value="{{ $image->id }}" 
                                           onchange="updateSelection()"
                                           class="image-checkbox w-5 h-5 rounded border-gray-600 bg-gray-700 text-purple-600 shadow-lg focus:ring-2 focus:ring-purple-500 cursor-pointer transition-all">
                                </div>

                                <!-- Image: Enforced Aspect Ratio (Square) -->
                                <div class="aspect-square w-full bg-gray-950 overflow-hidden">
                                    <img src="{{ asset($image->path) }}" 
                                         alt="{{ $image->original_name }}"
                                         class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                                </div>

                                <!-- Delete Button: Pro Style -->
                                <button onclick="deleteImage({{ $image->id }})" 
                                        type="button"
                                        class="absolute top-3 right-3 bg-red-600/80 hover:bg-red-600 text-white w-8 h-8 flex items-center justify-center rounded-full shadow-lg transition-all duration-200 z-10 opacity-0 group-hover:opacity-100 transform scale-90 group-hover:scale-100"
                                        title="Delete Image">
                                    <span class="text-lg font-bold leading-none">&times;</span>
                                </button>

                                <!-- Caption -->
                                <div class="p-3 bg-gray-900 border-t border-gray-800">
                                    <p class="text-xs text-gray-500 truncate text-center font-medium">
                                        {{ $image->original_name }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-gray-500 text-center py-12 bg-gray-900/50 rounded-lg border-2 border-dashed border-gray-700">
                        <svg class="w-12 h-12 mx-auto mb-3 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <p class="text-gray-400">No images yet. Upload your first artwork above!</p>
                    </div>
                @endif
            </div>

        </div>
    </div>

    <!-- Dropzone & Scripts -->
    <script src="https://unpkg.com/dropzone@5/dist/min/dropzone.min.js"></script>
    <script>
        Dropzone.options.imageUploadDropzone = {
            paramName: "file",
            maxFilesize: 10, // 10MB
            maxFiles: 100,
            parallelUploads: 2,
            timeout: 180000, // 3 minutes
            acceptedFiles: ".jpeg,.jpg,.png,.webp",
            dictDefaultMessage: "📸 <span class='text-purple-400 font-bold text-lg'>Drag your artwork here</span> or <span class='underline cursor-pointer'>browse</span><br><span class='text-xs text-gray-500 mt-2 block'>Supports JPG, PNG, WEBP (Max 10MB)</span>",
            addRemoveLinks: true,
            uploadMultiple: false,
            autoProcessQueue: true,
            
            init: function() {
                let uploadedCount = 0;
                let totalFiles = 0;
                let hasErrors = false;
                let failedFiles = [];
                
                this.on("addedfiles", function(files) {
                    totalFiles = files.length;
                    uploadedCount = 0;
                    hasErrors = false;
                    failedFiles = [];
                    console.log(`📤 Starting upload of ${totalFiles} images...`);
                });
                
                this.on("success", function(file, response) {
                    if(response.success) {
                        uploadedCount++;
                        console.log(`✅ Uploaded ${uploadedCount}/${totalFiles}: ${file.name}`);
                    }
                });
                
                this.on("error", function(file, errorMessage, xhr) {
                    hasErrors = true;
                    
                    let cleanError = 'Unknown error';
                    
                    if (typeof errorMessage === 'object' && errorMessage.error) {
                        cleanError = errorMessage.error;
                    } else if (typeof errorMessage === 'string') {
                        cleanError = errorMessage.includes('failed to upload') 
                            ? 'Upload failed - check file size/format' 
                            : errorMessage;
                    } else if (xhr) {
                        if (xhr.status === 422) {
                            cleanError = 'Validation failed (size/format issue)';
                        } else if (xhr.status === 413) {
                            cleanError = 'File too large (server limit)';
                        } else if (xhr.status === 500) {
                            cleanError = 'Server error during processing';
                        }
                    }
                    
                    failedFiles.push({
                        name: file.name,
                        error: cleanError
                    });
                    
                    console.error(`❌ Upload failed for ${file.name}:`, cleanError);
                });
                
                this.on("queuecomplete", function() {
                    console.log(`🎉 Queue complete! Uploaded: ${uploadedCount}/${totalFiles}`);
                    
                    if (failedFiles.length > 0) {
                        let errorList = failedFiles.map(f => `• ${f.name}: ${f.error}`).join('\n');
                        alert(`⚠️ Upload Results:\n\n✅ Successful: ${uploadedCount}\n❌ Failed: ${failedFiles.length}\n\nFailed files:\n${errorList}\n\nCommon fixes:\n- Reduce image file size\n- Check PHP upload limits\n- Ensure valid JPG/PNG/WEBP format`);
                    }
                    
                    if (uploadedCount > 0) {
                        setTimeout(() => {
                            console.log('🔄 Reloading page...');
                            location.reload();
                        }, 1500);
                    }
                });
            }
        };

        // Delete single image
        function deleteImage(id) {
            if(!confirm('Delete this image permanently?')) return;

            fetch(`/admin/images/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                }
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    const element = document.getElementById(`image-${id}`);
                    element.style.transform = 'scale(0.9)';
                    element.style.opacity = '0';
                    setTimeout(() => {
                        element.remove();
                        location.reload();
                    }, 200);
                }
            })
            .catch(err => {
                console.error('Delete error:', err);
                alert('Failed to delete image');
            });
        }

        // Toggle select all checkboxes
        function toggleSelectAll() {
            const selectAllCheckbox = document.getElementById('select-all-checkbox');
            const imageCheckboxes = document.querySelectorAll('.image-checkbox');
            
            imageCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
                // Visual feedback for parent card
                const card = checkbox.closest('.gallery-card');
                if (selectAllCheckbox.checked) {
                    card.classList.add('ring-2', 'ring-purple-500', 'border-purple-500');
                } else {
                    card.classList.remove('ring-2', 'ring-purple-500', 'border-purple-500');
                }
            });
            
            updateSelection();
        }

        // Update selection count and button visibility
        function updateSelection() {
            const checkboxes = document.querySelectorAll('.image-checkbox:checked');
            const allCheckboxes = document.querySelectorAll('.image-checkbox');
            const selectAllCheckbox = document.getElementById('select-all-checkbox');
            const btn = document.getElementById('bulk-delete-btn');
            const countSpan = document.getElementById('selected-count');
            
            // Update visual state of cards
            allCheckboxes.forEach(cb => {
                const card = cb.closest('.gallery-card');
                if (cb.checked) {
                    card.classList.add('ring-2', 'ring-purple-500', 'border-purple-500');
                    card.querySelector('.image-checkbox').parentElement.style.opacity = '1';
                } else {
                    card.classList.remove('ring-2', 'ring-purple-500', 'border-purple-500');
                    card.querySelector('.image-checkbox').parentElement.style.opacity = '';
                }
            });
            
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = checkboxes.length === allCheckboxes.length && allCheckboxes.length > 0;
                selectAllCheckbox.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
            }
            
            if (checkboxes.length > 0) {
                btn.style.display = 'flex';
                countSpan.textContent = checkboxes.length;
            } else {
                btn.style.display = 'none';
            }
        }

        // Bulk delete function
        function bulkDelete() {
            const checkboxes = document.querySelectorAll('.image-checkbox:checked');
            const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
            
            if (ids.length === 0) {
                alert('Please select images to delete');
                return;
            }
            
            if (!confirm(`⚠️ Delete ${ids.length} image${ids.length > 1 ? 's' : ''} permanently?\n\nThis action cannot be undone.`)) {
                return;
            }
            
            const btn = document.getElementById('bulk-delete-btn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Deleting...';

            fetch('{{ route("admin.images.bulk_destroy") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ ids: ids })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(`✅ Successfully deleted ${data.deleted} image${data.deleted > 1 ? 's' : ''}`);
                    if (data.errors && data.errors.length > 0) {
                        console.warn('Some images failed to delete:', data.errors);
                    }
                    location.reload();
                } else {
                    alert('❌ Failed to delete images. Please try again.');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            })
            .catch(err => {
                console.error('Delete error:', err);
                alert('❌ Network error. Please check your connection and try again.');
                btn.disabled = false;
                btn.innerHTML = originalText;
            });
        }

        // CHANGE #3: New AJAX Functions

        // ✅ AJAX: Upload Audio File
        function uploadAudioFile(input) {
            const file = input.files[0];
            if (!file) return;
            
            // Hide previous messages
            document.getElementById('audio-upload-success').style.display = 'none';
            document.getElementById('audio-upload-error').style.display = 'none';
            
            // Show progress bar
            const progressDiv = document.getElementById('audio-upload-progress');
            const progressBar = document.getElementById('audio-progress-bar');
            const progressPercent = document.getElementById('audio-progress-percent');
            const progressText = document.getElementById('audio-progress-text');
            
            progressDiv.style.display = 'block';
            progressBar.style.width = '0%';
            progressPercent.textContent = '0%';
            progressText.textContent = 'Uploading audio...';
            
            // Create FormData
            const formData = new FormData();
            formData.append('audio', file);
            formData.append('_token', '{{ csrf_token() }}');
            
            // Upload via fetch
            fetch('{{ route("admin.galleries.upload-audio", $gallery) }}', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                // Simulate progress (since we don't have real progress tracking)
                let progress = 0;
                const interval = setInterval(() => {
                    progress += 10;
                    progressBar.style.width = progress + '%';
                    progressPercent.textContent = progress + '%';
                    
                    if (progress >= 100) {
                        clearInterval(interval);
                        
                        if (data.success) {
                            // Hide progress
                            progressDiv.style.display = 'none';
                            
                            // Show success
                            document.getElementById('audio-success-message').textContent = data.message;
                            document.getElementById('audio-upload-success').style.display = 'block';
                            
                            // Update preview
                            document.getElementById('audio-preview-container').style.display = 'flex';
                            document.getElementById('audio-filename').textContent = data.filename;
                            
                            const audioPlayer = document.getElementById('audio-player');
                            audioPlayer.innerHTML = `<source src="${data.audio_url}" type="audio/mpeg">`;
                            audioPlayer.load();
                            
                            // Clear file input
                            input.value = '';
                            
                            // Hide success after 5 seconds
                            setTimeout(() => {
                                document.getElementById('audio-upload-success').style.display = 'none';
                            }, 5000);
                        } else {
                            // Show error
                            progressDiv.style.display = 'none';
                            document.getElementById('audio-error-message').textContent = data.message || 'Upload failed';
                            document.getElementById('audio-upload-error').style.display = 'block';
                        }
                    }
                }, 50);
            })
            .catch(error => {
                console.error('Upload error:', error);
                progressDiv.style.display = 'none';
                document.getElementById('audio-error-message').textContent = 'Network error. Please try again.';
                document.getElementById('audio-upload-error').style.display = 'block';
            });
        }

        // ✅ AJAX: Upload Logo File
        function uploadLogoFile(input) {
            const file = input.files[0];
            if (!file) return;
            
            // Hide previous messages
            document.getElementById('logo-upload-success').style.display = 'none';
            document.getElementById('logo-upload-error').style.display = 'none';
            
            // Show progress bar
            const progressDiv = document.getElementById('logo-upload-progress');
            const progressBar = document.getElementById('logo-progress-bar');
            const progressPercent = document.getElementById('logo-progress-percent');
            const progressText = document.getElementById('logo-progress-text');
            
            progressDiv.style.display = 'block';
            progressBar.style.width = '0%';
            progressPercent.textContent = '0%';
            progressText.textContent = 'Uploading logo...';
            
            // Create FormData
            const formData = new FormData();
            formData.append('custom_logo', file);
            formData.append('_token', '{{ csrf_token() }}');
            
            // Upload via fetch
            fetch('{{ route("admin.galleries.upload-logo", $gallery) }}', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                // Simulate progress
                let progress = 0;
                const interval = setInterval(() => {
                    progress += 10;
                    progressBar.style.width = progress + '%';
                    progressPercent.textContent = progress + '%';
                    
                    if (progress >= 100) {
                        clearInterval(interval);
                        
                        if (data.success) {
                            // Hide progress
                            progressDiv.style.display = 'none';
                            
                            // Show success
                            document.getElementById('logo-success-message').textContent = data.message;
                            document.getElementById('logo-upload-success').style.display = 'block';
                            
                            // Update preview
                            document.getElementById('logo-preview-container').style.display = 'flex';
                            document.getElementById('logo-preview-image').src = data.logo_url;
                            
                            // Clear file input
                            input.value = '';
                            
                            // Hide success after 5 seconds
                            setTimeout(() => {
                                document.getElementById('logo-upload-success').style.display = 'none';
                            }, 5000);
                        } else {
                            // Show error
                            progressDiv.style.display = 'none';
                            document.getElementById('logo-error-message').textContent = data.message || 'Upload failed';
                            document.getElementById('logo-upload-error').style.display = 'block';
                        }
                    }
                }, 50);
            })
            .catch(error => {
                console.error('Upload error:', error);
                progressDiv.style.display = 'none';
                document.getElementById('logo-error-message').textContent = 'Network error. Please try again.';
                document.getElementById('logo-upload-error').style.display = 'block';
            });
        }
    </script>
</x-app-layout>