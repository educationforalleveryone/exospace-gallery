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
@php
    // Iteration 5 "Authoring" (§9.3): structured inputs for the stable flat
    // keys, raw-JSON textarea for everything else. Per-key old() keeps the
    // admin's typed values across validation failures.
    $vc = $venue->visual_config ?? [];
    $mc = $venue->material_config ?? [];
    $vcv = fn (string $key) => old('visual_config.'.$key, $vc[$key] ?? '');
    $mcv = fn (string $key) => old('material_config.'.$key, $mc[$key] ?? '');
    // Iteration 6 curation (P2.3): nested placement block accessor.
    $pl = $vc['placement'] ?? [];
    $plv = fn (string $key) => old('visual_config.placement.'.$key, $pl[$key] ?? '');

    // Advanced prefill: existing keys the structured form does not model
    // (structure descriptors, gates, placement, tier_fallbacks…). After a
    // validation failure the posted JSON string (or decoded array) wins so
    // nothing the admin typed is lost.
    $advancedOld = old('visual_config_advanced');
    $advancedJson = is_string($advancedOld)
        ? $advancedOld
        : json_encode($advancedOld !== null && $advancedOld !== [] ? $advancedOld : $venue->advancedVisualConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
@endphp
<div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-5">
    <h3 class="text-gray-200 font-semibold mb-1 flex items-center gap-2">
        <span class="w-5 h-5 rounded-full bg-brand-600/30 text-brand-400 text-xs flex items-center justify-center font-bold">4</span>
        Visual configuration
    </h3>
    <p class="text-xs text-gray-500 mb-4">The common keys get real inputs — leave a field blank to inherit the viewer default. Structure descriptors, tier gates and pipeline pastes live in the advanced JSON box below and are preserved verbatim on save.</p>

    {{-- Geometry --}}
    <p class="text-xs uppercase tracking-wider text-gray-500 mb-2">Geometry</p>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
        <div>
            <label class="label-text mb-1.5">Wall height (m)</label>
            <input type="number" step="0.01" min="1" max="50" name="visual_config[wall_height]" value="{{ $vcv('wall_height') }}" placeholder="4"
                   class="input-base {{ $errors->has('visual_config.wall_height') ? 'input-error' : '' }}">
            @error('visual_config.wall_height')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Wall depth (m)</label>
            <input type="number" step="0.01" min="0.05" max="5" name="visual_config[wall_depth]" value="{{ $vcv('wall_depth') }}" placeholder="0.2"
                   class="input-base {{ $errors->has('visual_config.wall_depth') ? 'input-error' : '' }}">
            @error('visual_config.wall_depth')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Ceiling height (m)</label>
            <input type="number" step="0.01" min="1" max="50" name="visual_config[ceiling_height]" value="{{ $vcv('ceiling_height') }}" placeholder="4.6"
                   class="input-base {{ $errors->has('visual_config.ceiling_height') ? 'input-error' : '' }}">
            @error('visual_config.ceiling_height')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="vc-ceiling-type" class="label-text mb-1.5">Ceiling type</label>
            <select id="vc-ceiling-type" name="visual_config[ceiling_type]" class="input-base">
                <option value="">— inherit —</option>
                @foreach(['flat', 'beamed', 'glass', 'none'] as $ct)
                    <option value="{{ $ct }}" {{ (string) $vcv('ceiling_type') === $ct ? 'selected' : '' }}>{{ $ct }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Colors --}}
    <p class="text-xs uppercase tracking-wider text-gray-500 mb-2">Colors <span class="normal-case tracking-normal text-gray-600">(0xRRGGBB)</span></p>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
        <div>
            <label class="label-text mb-1.5">Background</label>
            <input type="text" name="visual_config[background_color]" value="{{ $vcv('background_color') }}" placeholder="0x0f0f0f"
                   class="input-base font-mono {{ $errors->has('visual_config.background_color') ? 'input-error' : '' }}">
            @error('visual_config.background_color')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Fog</label>
            <input type="text" name="visual_config[fog_color]" value="{{ $vcv('fog_color') }}" placeholder="0x0f0f0f"
                   class="input-base font-mono {{ $errors->has('visual_config.fog_color') ? 'input-error' : '' }}">
            @error('visual_config.fog_color')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Ambient</label>
            <input type="text" name="visual_config[ambient_color]" value="{{ $vcv('ambient_color') }}" placeholder="0xffffff"
                   class="input-base font-mono {{ $errors->has('visual_config.ambient_color') ? 'input-error' : '' }}">
            @error('visual_config.ambient_color')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
    </div>

    {{-- Fog + light --}}
    <p class="text-xs uppercase tracking-wider text-gray-500 mb-2">Fog &amp; light</p>
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-5">
        <div>
            <label class="label-text mb-1.5">Fog near</label>
            <input type="number" step="0.1" min="0" name="visual_config[fog_near]" value="{{ $vcv('fog_near') }}" placeholder="1"
                   class="input-base {{ $errors->has('visual_config.fog_near') ? 'input-error' : '' }}">
            @error('visual_config.fog_near')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Fog far <span class="text-gray-500 text-xs">(&gt; near)</span></label>
            <input type="number" step="0.1" min="0" name="visual_config[fog_far]" value="{{ $vcv('fog_far') }}" placeholder="18"
                   class="input-base {{ $errors->has('visual_config.fog_far') ? 'input-error' : '' }}">
            @error('visual_config.fog_far')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Ambient int.</label>
            <input type="number" step="0.01" min="0" max="5" name="visual_config[ambient_intensity]" value="{{ $vcv('ambient_intensity') }}" placeholder="0.35"
                   class="input-base">
        </div>
        <div>
            <label class="label-text mb-1.5">Spot int.</label>
            <input type="number" step="0.01" min="0" max="10" name="visual_config[spot_intensity]" value="{{ $vcv('spot_intensity') }}" placeholder="2.2"
                   class="input-base">
        </div>
        <div>
            <label class="label-text mb-1.5">Fill int.</label>
            <input type="number" step="0.01" min="0" max="5" name="visual_config[fill_intensity]" value="{{ $vcv('fill_intensity') }}" placeholder="0.6"
                   class="input-base">
        </div>
    </div>

    {{-- Exposure + frame --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="label-text mb-1.5">Tone-mapping exposure</label>
            <input type="number" step="0.01" min="0" max="3" name="visual_config[tone_mapping_exposure]" value="{{ $vcv('tone_mapping_exposure') }}" placeholder="1.0"
                   class="input-base">
        </div>
        <div>
            <label for="vc-frame-override" class="label-text mb-1.5">Frame override</label>
            <select id="vc-frame-override" name="visual_config[frame_override]" class="input-base">
                <option value="">— inherit —</option>
                @foreach(['gold', 'silver', 'bronze', 'black', 'white'] as $frame)
                    <option value="{{ $frame }}" {{ (string) $vcv('frame_override') === $frame ? 'selected' : '' }}>{{ ucfirst($frame) }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- ── Curation (Iteration 6, P2.3 §6.3–§6.5) — opt-in placement power ── --}}
    <p class="text-xs uppercase tracking-wider text-gray-500 mb-2 mt-5">Curation <span class="normal-case tracking-normal text-gray-600">— optional; blank = the calm uniform default</span></p>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label for="vc-placement-density" class="block text-xs text-gray-400 mb-1">Density rhythm (§6.3)</label>
            <select id="vc-placement-density" name="visual_config[placement][density]" class="input-base">
                <option value="">Standard · 3.5 m (default)</option>
                @foreach (['intimate' => 'Intimate · 2.8 m', 'standard' => 'Standard · 3.5 m', 'generous' => 'Generous · 4.5 m'] as $dk => $dlabel)
                    <option value="{{ $dk }}" {{ (string) $plv('density') === $dk ? 'selected' : '' }}>{{ $dlabel }}</option>
                @endforeach
            </select>
            @error('visual_config.placement.density')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="vc-placement-focal" class="block text-xs text-gray-400 mb-1">Focal wall (§6.5, square rooms)</label>
            <select id="vc-placement-focal" name="visual_config[placement][focal_wall]" class="input-base">
                <option value="">None — every piece equal (default)</option>
                @foreach (['front' => 'Front wall', 'back' => 'Back wall', 'left' => 'Left wall', 'right' => 'Right wall'] as $fw => $flabel)
                    <option value="{{ $fw }}" {{ (string) $plv('focal_wall') === $fw ? 'selected' : '' }}>{{ $flabel }}</option>
                @endforeach
            </select>
            <p class="text-xs text-gray-500 mt-1">The first piece on that wall gets the hero treatment (slightly larger, stronger light). One hero per hang.</p>
            @error('visual_config.placement.focal_wall')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="vc-placement-pair" class="block text-xs text-gray-400 mb-1">Orientation pairing (§6.4)</label>
            <label class="flex items-center gap-3 bg-gray-900/60 border border-gray-700 rounded-lg px-3 min-h-11 cursor-pointer">
                <input id="vc-placement-pair" type="checkbox" name="visual_config[placement][pair_orientation]" value="1"
                       {{ ($plv('pair_orientation') == 1 || $plv('pair_orientation') === true) ? 'checked' : '' }}
                       class="h-4 w-4 rounded border-gray-600 bg-gray-900 text-brand-600 focus:ring-brand-500">
                <span class="text-sm text-gray-300">Interleave portrait/landscape so mixed walls read composed</span>
            </label>
            <p class="text-xs text-gray-500 mt-1">Unchecked = the historical hang order, untouched.</p>
        </div>
    </div>

    {{-- Advanced: everything the structured form does not model --}}
    <div class="mt-5 pt-5 border-t border-gray-700/60">
        <label class="label-text mb-1.5">visual_config — advanced <span class="text-gray-500 text-xs">(raw JSON; structure, gates, placement…)</span></label>
        <textarea name="visual_config_advanced" rows="7"
                  class="input-base font-mono text-xs {{ $errors->has('visual_config_advanced') ? 'input-error' : '' }}" @error('visual_config_advanced') aria-invalid="true" aria-describedby="visual_config_advanced-error" @enderror
                  placeholder='{"structure": [], "structure_pass": "rooms", "tier_fallbacks": {}}'>{{ $advancedJson }}</textarea>
        @error('visual_config_advanced')<p id="visual_config_advanced-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        <p class="text-xs text-gray-500 mt-1">Merged OVER the structured fields on save (advanced wins on conflict). Saved blank on a new venue = no extra keys. Existing venue keys not modeled above are prefilled here — clearing this box on save removes them.</p>
        @error('visual_config')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
    </div>
</div>

{{-- ─────────────── Section: Material config ─────────────── --}}
<div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-5">
    <h3 class="text-gray-200 font-semibold mb-1 flex items-center gap-2">
        <span class="w-5 h-5 rounded-full bg-brand-600/30 text-brand-400 text-xs flex items-center justify-center font-bold">5</span>
        Material configuration
    </h3>
    <p class="text-xs text-gray-500 mb-4">PBR wall and floor parameters. Blank = viewer default. Roughness/metalness are 0–1.</p>

    <p class="text-xs uppercase tracking-wider text-gray-500 mb-2">Wall</p>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
        <div>
            <label class="label-text mb-1.5">Color</label>
            <input type="text" name="material_config[wall_color]" value="{{ $mcv('wall_color') }}" placeholder="0xf5f5f5"
                   class="input-base font-mono {{ $errors->has('material_config.wall_color') ? 'input-error' : '' }}">
            @error('material_config.wall_color')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Roughness</label>
            <input type="number" step="0.01" min="0" max="1" name="material_config[wall_roughness]" value="{{ $mcv('wall_roughness') }}" placeholder="0.9"
                   class="input-base">
        </div>
        <div>
            <label class="label-text mb-1.5">Metalness</label>
            <input type="number" step="0.01" min="0" max="1" name="material_config[wall_metalness]" value="{{ $mcv('wall_metalness') }}" placeholder="0"
                   class="input-base">
        </div>
        <div>
            <label class="label-text mb-1.5">Normal strength</label>
            <input type="number" step="0.01" min="0" max="5" name="material_config[wall_normal_strength]" value="{{ $mcv('wall_normal_strength') }}" placeholder="0.5"
                   class="input-base">
        </div>
    </div>

    <p class="text-xs uppercase tracking-wider text-gray-500 mb-2">Floor</p>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div>
            <label class="label-text mb-1.5">Color</label>
            <input type="text" name="material_config[floor_color]" value="{{ $mcv('floor_color') }}" placeholder="0x2a2a2a"
                   class="input-base font-mono {{ $errors->has('material_config.floor_color') ? 'input-error' : '' }}">
            @error('material_config.floor_color')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Roughness</label>
            <input type="number" step="0.01" min="0" max="1" name="material_config[floor_roughness]" value="{{ $mcv('floor_roughness') }}" placeholder="0.6"
                   class="input-base">
        </div>
        <div>
            <label class="label-text mb-1.5">Metalness</label>
            <input type="number" step="0.01" min="0" max="1" name="material_config[floor_metalness]" value="{{ $mcv('floor_metalness') }}" placeholder="0.1"
                   class="input-base">
        </div>
        <div>
            <label class="label-text mb-1.5">Normal strength</label>
            <input type="number" step="0.01" min="0" max="5" name="material_config[floor_normal_strength]" value="{{ $mcv('floor_normal_strength') }}" placeholder="0.8"
                   class="input-base">
        </div>
    </div>
</div>

{{-- ─────────────── Section: Decorations & lighting ─────────────── --}}
<div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-5">
    <h3 class="text-gray-200 font-semibold mb-1 flex items-center gap-2">
        <span class="w-5 h-5 rounded-full bg-brand-600/30 text-brand-400 text-xs flex items-center justify-center font-bold">6</span>
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
        <span class="w-5 h-5 rounded-full bg-brand-600/30 text-brand-400 text-xs flex items-center justify-center font-bold">7</span>
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
