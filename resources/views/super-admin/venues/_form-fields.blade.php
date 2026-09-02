@php
    // Shared form fields partial for venue template create + edit.
    // Expects: $venue (VenueTemplate instance, may be new/unfilled), $categories, $layouts
    $isEdit = isset($venue) && $venue->exists;
@endphp

{{-- ─────────────── Section: Identity ─────────────── --}}
<div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-5">
    <h3 class="text-gray-200 font-semibold mb-4 flex items-center gap-2">
        <span class="w-5 h-5 rounded-full bg-brand-600/30 text-brand-400 text-xs flex items-center justify-center font-bold">1</span>
        Identity
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="md:col-span-2">
            <label class="label-text mb-1.5">Name <span class="text-red-400">*</span></label>
            <input type="text" name="name" value="{{ old('name', $venue->name) }}"
                   class="input-base {{ $errors->has('name') ? 'input-error' : '' }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror
                   required maxlength="100">
            @error('name')<p id="name-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Slug</label>
            <input type="text" name="slug" value="{{ old('slug', $venue->slug) }}"
                   placeholder="auto-generated from name"
                   class="input-base font-mono {{ $errors->has('slug') ? 'input-error' : '' }}" @error('slug') aria-invalid="true" aria-describedby="slug-error" @enderror>
            @error('slug')<p id="slug-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="mt-4">
        <label class="label-text mb-1.5">Description <span class="text-red-400">*</span></label>
        <textarea name="description" rows="3" maxlength="1000"
                  class="input-base {{ $errors->has('description') ? 'input-error' : '' }}" @error('description') aria-invalid="true" aria-describedby="description-error" @enderror
                  required>{{ old('description', $venue->description) }}</textarea>
        @error('description')<p id="description-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
        <div>
            <label for="vf-category" class="label-text mb-1.5">Category <span class="text-red-400">*</span></label>
            <select id="vf-category" name="category" class="input-base {{ $errors->has('category') ? 'input-error' : '' }}" @error('category') aria-invalid="true" aria-describedby="category-error" @enderror>
                @foreach($categories as $key => $label)
                    <option value="{{ $key }}" {{ old('category', $venue->category) === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            @error('category')<p id="category-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="vf-plan-required" class="label-text mb-1.5">Plan required <span class="text-red-400">*</span></label>
            <select id="vf-plan-required" name="plan_required" class="input-base">
                @foreach(['free','pro','studio'] as $plan)
                    <option value="{{ $plan }}" {{ old('plan_required', $venue->plan_required) === $plan ? 'selected' : '' }}>{{ ucfirst($plan) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label-text mb-1.5">Version</label>
            <input type="text" name="version" value="{{ old('version', $venue->version ?? '1.0.0') }}"
                   placeholder="1.0.0"
                   class="input-base font-mono {{ $errors->has('version') ? 'input-error' : '' }}" @error('version') aria-invalid="true" aria-describedby="version-error" @enderror>
            @error('version')<p id="version-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="mt-4">
        <label class="label-text mb-1.5">Tags <span class="text-gray-500 text-xs">(JSON array of strings)</span></label>
        <textarea name="tags" rows="2"
                  class="input-base font-mono {{ $errors->has('tags') ? 'input-error' : '' }}" @error('tags') aria-invalid="true" aria-describedby="tags-error" @enderror
                  placeholder='["contemporary", "minimal"]'>{{ old('tags', $venue->tags ? json_encode($venue->tags, JSON_PRETTY_PRINT) : '') }}</textarea>
        @error('tags')<p id="tags-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
    </div>
</div>

{{-- ─────────────── Section: Capacity & status ─────────────── --}}
<div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-5">
    <h3 class="text-gray-200 font-semibold mb-4 flex items-center gap-2">
        <span class="w-5 h-5 rounded-full bg-brand-600/30 text-brand-400 text-xs flex items-center justify-center font-bold">2</span>
        Capacity & status
    </h3>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div>
            <label class="label-text mb-1.5">Min capacity <span class="text-red-400">*</span></label>
            <input type="number" name="capacity_min" value="{{ old('capacity_min', $venue->capacity_min) }}" min="1"
                   class="input-base {{ $errors->has('capacity_min') ? 'input-error' : '' }}" @error('capacity_min') aria-invalid="true" aria-describedby="capacity_min-error" @enderror required>
            @error('capacity_min')<p id="capacity_min-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Max capacity <span class="text-gray-500 text-xs">(blank = unlimited)</span></label>
            <input type="number" name="capacity_max" value="{{ old('capacity_max', $venue->capacity_max) }}" min="1"
                   class="input-base {{ $errors->has('capacity_max') ? 'input-error' : '' }}" @error('capacity_max') aria-invalid="true" aria-describedby="capacity_max-error" @enderror>
            @error('capacity_max')<p id="capacity_max-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Sort order</label>
            <input type="number" name="sort_order" value="{{ old('sort_order', $venue->sort_order) }}" min="0"
                   class="input-base">
        </div>
        <div class="flex flex-col justify-end gap-2 pb-1">
            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $venue->is_active) ? 'checked' : '' }}
                       class="checkbox-base">
                Active
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox" name="is_featured" value="1" {{ old('is_featured', $venue->is_featured) ? 'checked' : '' }}
                       class="checkbox-base">
                Featured
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox" name="is_draft" value="1" {{ old('is_draft', $venue->is_draft) ? 'checked' : '' }}
                       class="checkbox-base">
                Draft <span class="text-gray-500 text-xs">(hidden from galleries)</span>
            </label>
        </div>
    </div>
</div>

{{-- ─────────────── Section: 3D Assets ─────────────── --}}
<div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-5">
    <h3 class="text-gray-200 font-semibold mb-4 flex items-center gap-2">
        <span class="w-5 h-5 rounded-full bg-brand-600/30 text-brand-400 text-xs flex items-center justify-center font-bold">3</span>
        3D assets & media
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        {{-- Thumbnail --}}
        <div>
            <label class="label-text mb-1.5">Thumbnail image</label>
            <input type="file" name="thumbnail_image" accept="image/png,image/jpeg,image/webp"
                   class="file-base {{ $errors->has('thumbnail_image') ? 'input-error' : '' }}" @error('thumbnail_image') aria-invalid="true" aria-describedby="thumbnail_image-error" @enderror>
            <p class="text-xs text-gray-500 mt-1">PNG / JPG / WEBP, max 2 MB. Shown in the admin venue list and on the create-gallery venue picker.</p>
            @error('thumbnail_image')<p id="thumbnail_image-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
            @if($isEdit && $venue->thumbnail_url)
                <div class="mt-2"><img src="{{ $venue->thumbnail_url }}" alt="{{ $venue->name ?: 'Venue thumbnail' }}" class="h-16 rounded border border-gray-700"></div>
            @endif
        </div>

        {{-- 3D preview model --}}
        <div>
            <label class="label-text mb-1.5">3D preview model (GLB / GLTF)</label>
            <input type="file" name="preview_model" accept=".glb,.gltf"
                   class="file-base {{ $errors->has('preview_model') ? 'input-error' : '' }}" @error('preview_model') aria-invalid="true" aria-describedby="preview_model-error" @enderror>
            <p class="text-xs text-gray-500 mt-1">GLB or GLTF, max 50 MB. Used for the admin 3D preview. Generated by your external 3D-model pipeline.</p>
            @error('preview_model')<p id="preview_model-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
            @if($isEdit && $venue->preview_model_path)
                <p class="text-xs text-blue-400 mt-2">Current: <a href="{{ $venue->preview_model_url }}" target="_blank" class="underline">{{ basename($venue->preview_model_path) }}</a></p>
            @endif
        </div>

        {{-- HDRI --}}
        <div>
            <label class="label-text mb-1.5">Custom HDRI environment</label>
            <input type="file" name="hdri_file" accept=".hdr,.exr"
                   class="file-base {{ $errors->has('hdri_file') ? 'input-error' : '' }}" @error('hdri_file') aria-invalid="true" aria-describedby="hdri_file-error" @enderror>
            <p class="text-xs text-gray-500 mt-1">HDR or EXR, max 50 MB. Overrides the preset-based HDRI in the viewer.</p>
            @error('hdri_file')<p id="hdri_file-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
            @if($isEdit && $venue->hdri_path)
                <p class="text-xs text-brand-400 mt-2">Current: <a href="{{ $venue->hdri_url }}" target="_blank" class="underline">{{ basename($venue->hdri_path) }}</a></p>
            @endif
        </div>

        {{-- Default ambient audio --}}
        <div>
            <label class="label-text mb-1.5">Default ambient audio</label>
            <input type="file" name="default_audio" accept=".mp3,.wav,.m4a"
                   class="file-base {{ $errors->has('default_audio') ? 'input-error' : '' }}" @error('default_audio') aria-invalid="true" aria-describedby="default_audio-error" @enderror>
            <p class="text-xs text-gray-500 mt-1">MP3 / WAV / M4A, max 10 MB. Applied to galleries using this venue unless they upload their own.</p>
            @error('default_audio')<p id="default_audio-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
            @if($isEdit && $venue->default_audio_path)
                <p class="text-xs text-pink-400 mt-2">Current: <a href="{{ $venue->default_audio_url }}" target="_blank" class="underline">{{ basename($venue->default_audio_path) }}</a></p>
            @endif
        </div>
    </div>
</div>

{{-- ─────────────── Section: Visual config ─────────────── --}}
<div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-5">
    <h3 class="text-gray-200 font-semibold mb-1 flex items-center gap-2">
        <span class="w-5 h-5 rounded-full bg-brand-600/30 text-brand-400 text-xs flex items-center justify-center font-bold">4</span>
        Visual configuration
    </h3>
    <p class="text-xs text-gray-500 mb-4">This JSON drives the 3D viewer. Edit values directly or paste a config from your 3D-model pipeline. All fields are optional — the viewer falls back to defaults.</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        {{-- visual_config JSON --}}
        <div class="md:col-span-2">
            <label class="label-text mb-1.5">visual_config <span class="text-gray-500 text-xs">(JSON)</span></label>
            <textarea name="visual_config" rows="14"
                      class="input-base font-mono text-xs {{ $errors->has('visual_config') ? 'input-error' : '' }}" @error('visual_config') aria-invalid="true" aria-describedby="visual_config-error" @enderror
                      placeholder='{"wall_height": 4, "fog_color": "0x0f0f0f", ...}'>{{ old('visual_config', $venue->visual_config ? json_encode($venue->visual_config, JSON_PRETTY_PRINT) : '') }}</textarea>
            @error('visual_config')<p id="visual_config-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
            <p class="text-xs text-gray-500 mt-1">Fields: wall_height, wall_depth, ceiling_type (flat|beamed|glass|none), ceiling_height, background_color (0xRRGGBB), fog_color, fog_near, fog_far, ambient_color, ambient_intensity, spot_intensity, fill_intensity, tone_mapping_exposure, frame_override (gold|silver|bronze|black|white|null)</p>
        </div>

        {{-- material_config JSON --}}
        <div class="md:col-span-2">
            <label class="label-text mb-1.5">material_config <span class="text-gray-500 text-xs">(JSON)</span></label>
            <textarea name="material_config" rows="10"
                      class="input-base font-mono text-xs {{ $errors->has('material_config') ? 'input-error' : '' }}" @error('material_config') aria-invalid="true" aria-describedby="material_config-error" @enderror
                      placeholder='{"wall_roughness": 0.9, "floor_metalness": 0.2, ...}'>{{ old('material_config', $venue->material_config ? json_encode($venue->material_config, JSON_PRETTY_PRINT) : '') }}</textarea>
            @error('material_config')<p id="material_config-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
    </div>
</div>

{{-- ─────────────── Section: Decorations & lighting ─────────────── --}}
<div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-5">
    <h3 class="text-gray-200 font-semibold mb-1 flex items-center gap-2">
        <span class="w-5 h-5 rounded-full bg-brand-600/30 text-brand-400 text-xs flex items-center justify-center font-bold">5</span>
        Decorations & custom lighting
    </h3>
    <p class="text-xs text-gray-500 mb-4">Decorations are 3D props (GLB files) placed in the room. Custom lighting fixtures are added on top of the preset lighting.</p>

    <div class="grid grid-cols-1 gap-4">
        <div>
            <label class="label-text mb-1.5">decorations <span class="text-gray-500 text-xs">(JSON array)</span></label>
            <textarea name="decorations" rows="8"
                      class="input-base font-mono text-xs {{ $errors->has('decorations') ? 'input-error' : '' }}" @error('decorations') aria-invalid="true" aria-describedby="decorations-error" @enderror
                      placeholder='[{"type": "pedestal", "model_path": "venue-models/pedestal.glb", "position": [0,0,0], "scale": 1}]'>{{ old('decorations', $venue->decorations ? json_encode($venue->decorations, JSON_PRETTY_PRINT) : '') }}</textarea>
            @error('decorations')<p id="decorations-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">lighting_fixtures <span class="text-gray-500 text-xs">(JSON array)</span></label>
            <textarea name="lighting_fixtures" rows="8"
                      class="input-base font-mono text-xs {{ $errors->has('lighting_fixtures') ? 'input-error' : '' }}" @error('lighting_fixtures') aria-invalid="true" aria-describedby="lighting_fixtures-error" @enderror
                      placeholder='[{"type": "point", "position": [0,6,0], "color": "0xffd9a8", "intensity": 0.4, "cast_shadow": false}]'>{{ old('lighting_fixtures', $venue->lighting_fixtures ? json_encode($venue->lighting_fixtures, JSON_PRETTY_PRINT) : '') }}</textarea>
            @error('lighting_fixtures')<p id="lighting_fixtures-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
    </div>
</div>

{{-- ─────────────── Section: Layouts & defaults ─────────────── --}}
<div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-5">
    <h3 class="text-gray-200 font-semibold mb-4 flex items-center gap-2">
        <span class="w-5 h-5 rounded-full bg-brand-600/30 text-brand-400 text-xs flex items-center justify-center font-bold">6</span>
        Layouts & default settings
    </h3>

    <div class="mb-4">
        <label class="label-text mb-1.5">Supported room layouts</label>
        <div class="flex flex-wrap gap-3">
            @foreach($layouts as $layout)
                <label class="flex items-center gap-2 text-sm text-gray-300 px-3 py-2 rounded-lg bg-gray-700/50 border border-gray-600 cursor-pointer hover:border-brand-500">
                    <input type="checkbox" name="supported_layouts[]" value="{{ $layout }}"
                           {{ old('supported_layouts', $venue->supported_layouts ?? $layouts) && in_array($layout, old('supported_layouts', $venue->supported_layouts ?? $layouts)) ? 'checked' : '' }}
                           class="checkbox-base">
                    <span class="font-mono">{{ $layout }}</span>
                </label>
            @endforeach
        </div>
        <p class="text-xs text-gray-500 mt-1">If none are checked, all four layouts are allowed (backward compat).</p>
        @error('supported_layouts')<p id="supported_layouts-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="label-text mb-1.5">default_settings <span class="text-gray-500 text-xs">(JSON — legacy field, kept for back-compat)</span></label>
        <textarea name="default_settings" rows="6"
                  class="input-base font-mono text-xs"
                  placeholder='{"wall_texture": "white", "floor_material": "concrete", ...}'>{{ old('default_settings', $venue->default_settings ? json_encode($venue->default_settings, JSON_PRETTY_PRINT) : '') }}</textarea>
        <p class="text-xs text-gray-500 mt-1">These five fields (wall_texture, floor_material, lighting_preset, frame_style, room_layout) are applied to a new gallery when this venue is picked, and can be overridden per-gallery.</p>
    </div>
</div>
