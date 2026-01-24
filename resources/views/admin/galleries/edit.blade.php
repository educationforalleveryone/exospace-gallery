<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Edit Gallery: {{ $gallery->title }}
        </h2>
    </x-slot>

    <!-- Dropzone CSS -->
    <link rel="stylesheet" href="https://unpkg.com/dropzone@5/dist/min/dropzone.min.css" type="text/css" />

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            @if(session('status'))
                <div class="bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-200 p-4 rounded">
                    {{ session('status') }}
                </div>
            @endif

            <!-- 1. Gallery Settings -->
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Gallery Settings</h3>
                
                <form action="{{ route('admin.galleries.update', $gallery) }}" method="POST">
                    @csrf
                    @method('PUT')
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Title -->
                        <div class="mb-4 md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Title</label>
                            <input type="text" name="title" value="{{ old('title', $gallery->title) }}" required
                                class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm">
                        </div>

                        <!-- Description -->
                        <div class="mb-4 md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
                            <textarea name="description" rows="3"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm">{{ old('description', $gallery->description) }}</textarea>
                        </div>

                        <!-- Wall Texture -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Wall Texture</label>
                            <select name="wall_texture" required
                                class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm">
                                <option value="white" {{ $gallery->wall_texture == 'white' ? 'selected' : '' }}>White Museum</option>
                                <option value="concrete" {{ $gallery->wall_texture == 'concrete' ? 'selected' : '' }}>Concrete</option>
                                <option value="brick" {{ $gallery->wall_texture == 'brick' ? 'selected' : '' }}>Brick</option>
                                <option value="wood" {{ $gallery->wall_texture == 'wood' ? 'selected' : '' }}>Wood</option>
                            </select>
                        </div>

                        <!-- Floor Material -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Floor Material</label>
                            <select name="floor_material" required
                                class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm">
                                <option value="wood" {{ $gallery->floor_material == 'wood' ? 'selected' : '' }}>Wood</option>
                                <option value="marble" {{ $gallery->floor_material == 'marble' ? 'selected' : '' }}>Marble</option>
                                <option value="concrete" {{ $gallery->floor_material == 'concrete' ? 'selected' : '' }}>Concrete</option>
                            </select>
                        </div>

                        <!-- Frame Style -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Frame Style</label>
                            <select name="frame_style" required
                                class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm">
                                <option value="modern" {{ $gallery->frame_style == 'modern' ? 'selected' : '' }}>Modern (Black)</option>
                                <option value="classic" {{ $gallery->frame_style == 'classic' ? 'selected' : '' }}>Classic (Gold)</option>
                                <option value="minimal" {{ $gallery->frame_style == 'minimal' ? 'selected' : '' }}>Minimal (Frameless)</option>
                            </select>
                        </div>

                        <!-- Lighting -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Lighting</label>
                            <select name="lighting_preset" required
                                class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm">
                                <option value="bright" {{ $gallery->lighting_preset == 'bright' ? 'selected' : '' }}>Bright</option>
                                <option value="moody" {{ $gallery->lighting_preset == 'moody' ? 'selected' : '' }}>Moody</option>
                                <option value="dramatic" {{ $gallery->lighting_preset == 'dramatic' ? 'selected' : '' }}>Dramatic</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 mt-4">
                        <a href="{{ route('admin.galleries.index') }}" class="bg-gray-300 dark:bg-gray-600 hover:bg-gray-400 dark:hover:bg-gray-500 text-gray-800 dark:text-gray-100 px-4 py-2 rounded">
                            Cancel
                        </a>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                            Update Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- 2. Image Upload Area -->
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Upload Artworks</h3>
                
                <form action="{{ route('admin.images.store', $gallery) }}" 
                      class="dropzone border-dashed border-2 border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700" 
                      id="image-upload-dropzone">
                    @csrf
                </form>
            </div>

            <!-- 3. Existing Images Grid -->
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
                <!-- 3A: Updated Header with Bulk Action Button and Select All -->
                <div class="flex justify-between items-center mb-4">
                    <div class="flex items-center gap-4">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                            Current Images ({{ $gallery->images->count() }})
                        </h3>
                        @if($gallery->images->count() > 0)
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer hover:text-gray-900 dark:hover:text-gray-200">
                                <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll()" 
                                       class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500">
                                <span>Select All</span>
                            </label>
                        @endif
                    </div>
                    <button id="bulk-delete-btn" onclick="bulkDelete()" style="display: none;" 
                            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-semibold transition-colors shadow-lg flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        Delete Selected (<span id="selected-count">0</span>)
                    </button>
                </div>
                
                @if($gallery->images->count() > 0)
                    <!-- CHANGED: Added specific width constraints and better gap -->
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-6" id="gallery-grid">
                        @foreach($gallery->images as $image)
                            <div class="relative group border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden" id="image-{{ $image->id }}">
                                
                                <!-- 3B: Selection Checkbox -->
                                <div class="absolute top-2 left-2 z-20">
                                    <input type="checkbox" value="{{ $image->id }}" 
                                           onchange="updateSelection()"
                                           class="w-5 h-5 rounded border-gray-300 text-blue-600 shadow-lg focus:ring-2 focus:ring-blue-500 image-checkbox cursor-pointer">
                                </div>

                                <!-- Image: Enforced Aspect Ratio (Square) -->
                                <div class="aspect-square w-full bg-gray-100 dark:bg-gray-900">
                                    <img src="{{ asset($image->path) }}" 
                                         alt="{{ $image->original_name }}"
                                         class="w-full h-full object-cover">
                                </div>

                                <!-- Delete Button: Always Visible (Red X at top right) -->
                                <button onclick="deleteImage({{ $image->id }})" 
                                        type="button"
                                        class="absolute top-2 right-2 bg-red-600 hover:bg-red-700 text-white w-8 h-8 flex items-center justify-center rounded-full shadow-md transition-colors z-10"
                                        title="Delete Image">
                                    <span class="text-xl font-bold line-height-none">&times;</span>
                                </button>

                                <!-- Caption -->
                                <div class="p-2 bg-white dark:bg-gray-800">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate text-center">
                                        {{ $image->original_name }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-gray-500 dark:text-gray-400 text-center py-10 bg-gray-50 dark:bg-gray-900 rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-700">
                        No images yet. Upload your first artwork above!
                    </p>
                @endif
            </div>

        </div>
    </div>

    <!-- Dropzone & Scripts -->
    <script src="https://unpkg.com/dropzone@5/dist/min/dropzone.min.js"></script>
    <script>
        // 3C: Updated Dropzone configuration - FIX FOR RACE CONDITION
        Dropzone.options.imageUploadDropzone = {
            paramName: "file",
            maxFilesize: 5, // MB
            maxFiles: 100,
            parallelUploads: 2, // Reduced to 2 for stability
            acceptedFiles: ".jpeg,.jpg,.png,.webp",
            dictDefaultMessage: "📸 Drop images here or click to upload (Max 5MB per image, 100 images total)",
            addRemoveLinks: true,
            uploadMultiple: false,
            autoProcessQueue: true,
            
            init: function() {
                let uploadedCount = 0;
                let totalFiles = 0;
                let hasErrors = false;
                
                this.on("addedfiles", function(files) {
                    totalFiles = files.length;
                    uploadedCount = 0;
                    hasErrors = false;
                    console.log(`📤 Starting upload of ${totalFiles} images...`);
                });
                
                this.on("success", function(file, response) {
                    if(response.success) {
                        uploadedCount++;
                        console.log(`✅ Uploaded ${uploadedCount}/${totalFiles}`);
                    }
                });
                
                this.on("error", function(file, response) {
                    hasErrors = true;
                    console.error('❌ Upload error:', response);
                    alert(`Upload failed for ${file.name}: ` + (response.error || 'Unknown error'));
                });
                
                this.on("queuecomplete", function() {
                    console.log(`🎉 Queue complete! Uploaded: ${uploadedCount}/${totalFiles}`);
                    
                    // Only reload after ALL uploads finish
                    if (uploadedCount > 0) {
                        setTimeout(() => {
                            console.log('🔄 Reloading page...');
                            location.reload();
                        }, 1000); // Give server time to process
                    }
                    
                    if (hasErrors) {
                        alert(`⚠️ ${uploadedCount} of ${totalFiles} images uploaded successfully. Check console for errors.`);
                    }
                });
            }
        };

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
                    document.getElementById(`image-${id}`).remove();
                    // Update count
                    location.reload();
                }
            })
            .catch(err => {
                console.error('Delete error:', err);
                alert('Failed to delete image');
            });
        }

        // ============================================
        // 3D: BULK DELETE FUNCTIONS
        // ============================================
        
        // ============================================
        // SELECT ALL FUNCTION
        // ============================================
        function toggleSelectAll() {
            const selectAllCheckbox = document.getElementById('select-all-checkbox');
            const imageCheckboxes = document.querySelectorAll('.image-checkbox');
            
            imageCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            
            updateSelection();
        }

        function updateSelection() {
            const checkboxes = document.querySelectorAll('.image-checkbox:checked');
            const allCheckboxes = document.querySelectorAll('.image-checkbox');
            const selectAllCheckbox = document.getElementById('select-all-checkbox');
            const btn = document.getElementById('bulk-delete-btn');
            const countSpan = document.getElementById('selected-count');
            
            // Update select-all checkbox state
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
            
            // Disable button during deletion
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
                    // Show success message
                    alert(`✅ Successfully deleted ${data.deleted} image${data.deleted > 1 ? 's' : ''}`);
                    
                    // Show errors if any
                    if (data.errors && data.errors.length > 0) {
                        console.warn('Some images failed to delete:', data.errors);
                    }
                    
                    // Reload page to update gallery
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
    </script>
</x-app-layout>