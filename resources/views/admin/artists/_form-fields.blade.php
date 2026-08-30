@php $isEdit = isset($artist) && $artist->exists; @endphp

<div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-5 space-y-4">

    {{-- Name + slug --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="md:col-span-2">
            <label class="label-text mb-1.5">Name <span class="text-red-400">*</span></label>
            <input type="text" name="name" value="{{ old('name', $artist->name) }}"
                   class="input-base {{ $errors->has('name') ? 'input-error' : '' }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror required maxlength="100">
            @error('name')<p id=\"name-error\" class=\"text-sm text-red-400 mt-1\">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Slug</label>
            <input type="text" name="slug" value="{{ old('slug', $artist->slug) }}"
                   placeholder="auto from name"
                   class="input-base font-mono {{ $errors->has('slug') ? 'input-error' : '' }}" @error('slug') aria-invalid="true" aria-describedby="slug-error" @enderror>
            @error('slug')<p id=\"slug-error\" class=\"text-sm text-red-400 mt-1\">{{ $message }}</p>@enderror
        </div>
    </div>

    {{-- Portrait --}}
    <div>
        <label class="label-text mb-1.5">Portrait photo</label>
        <input type="file" name="portrait" accept="image/png,image/jpeg,image/webp"
               class="file-base {{ $errors->has('portrait') ? 'input-error' : '' }}" @error('portrait') aria-invalid="true" aria-describedby="portrait-error" @enderror>
        <p class="text-xs text-gray-500 mt-1">PNG / JPG / WEBP, max 2 MB. Square aspect recommended.</p>
        @error('portrait')<p id=\"portrait-error\" class=\"text-sm text-red-400 mt-1\">{{ $message }}</p>@enderror
        @if($isEdit && $artist->portrait_url)
            <div class="mt-2 flex items-center gap-3">
                <img src="{{ $artist->portrait_url }}" alt="{{ $artist->name ?: 'Artist portrait' }}" class="w-16 h-16 rounded-full object-cover border border-gray-700">
                <a href="{{ $artist->portrait_url }}" target="_blank" class="text-xs text-blue-400 hover:underline">View current</a>
            </div>
        @endif
    </div>

    {{-- Bio --}}
    <div>
        <label class="label-text mb-1.5">Bio</label>
        <textarea name="bio" rows="4" maxlength="2000"
                  class="input-base {{ $errors->has('bio') ? 'input-error' : '' }}" @error('bio') aria-invalid="true" aria-describedby="bio-error" @enderror
                  placeholder="Artist biography, statement, or notes…">{{ old('bio', $artist->bio) }}</textarea>
        @error('bio')<p id=\"bio-error\" class=\"text-sm text-red-400 mt-1\">{{ $message }}</p>@enderror
    </div>

    {{-- SEO OS (Iteration 6): curator-facing SEO overrides. Leave blank to
         use the automatic title/description generated from real data. --}}
    <div>
        <label class="label-text mb-1.5">
            SEO title <span class="text-gray-500 font-normal text-xs">(optional — auto-generated when empty)</span>
        </label>
        <input type="text" name="seo_title" value="{{ old('seo_title', $artist->seoProfile?->title_override) }}" maxlength="200"
               placeholder="{{ $artist->name }} — Artist Profile & 3D Exhibitions"
               class="input-base {{ $errors->has('seo_title') ? 'input-error' : '' }}" @error('seo_title') aria-invalid="true" aria-describedby="seo_title-error" @enderror>
        @error('seo_title')<p id=\"seo_title-error\" class=\"text-sm text-red-400 mt-1\">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="label-text mb-1.5">
            SEO description <span class="text-gray-500 font-normal text-xs">(optional — max 300 chars)</span>
        </label>
        <textarea name="seo_description" rows="2" maxlength="300"
                  placeholder="Shown in search results. Auto-generated from the bio when empty."
                  class="input-base {{ $errors->has('seo_description') ? 'input-error' : '' }}" @error('seo_description') aria-invalid="true" aria-describedby="seo_description-error" @enderror>{{ old('seo_description', $artist->seoProfile?->description_override) }}</textarea>
        @error('seo_description')<p id=\"seo_description-error\" class=\"text-sm text-red-400 mt-1\">{{ $message }}</p>@enderror
    </div>

    {{-- Location --}}
    <div>
        <label class="label-text mb-1.5">Location</label>
        <input type="text" name="location" value="{{ old('location', $artist->location) }}"
               placeholder="Berlin, Germany"
               class="input-base {{ $errors->has('location') ? 'input-error' : '' }}" @error('location') aria-invalid="true" aria-describedby="location-error" @enderror>
        @error('location')<p id=\"location-error\" class=\"text-sm text-red-400 mt-1\">{{ $message }}</p>@enderror
    </div>

    {{-- Contact + socials --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="label-text mb-1.5">Public email</label>
            <input type="email" name="email" value="{{ old('email', $artist->email) }}"
                   placeholder="artist@example.com"
                   class="input-base {{ $errors->has('email') ? 'input-error' : '' }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')<p id=\"email-error\" class=\"text-sm text-red-400 mt-1\">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Website</label>
            <input type="url" name="website" value="{{ old('website', $artist->website) }}"
                   placeholder="https://artist-website.com"
                   class="input-base {{ $errors->has('website') ? 'input-error' : '' }}" @error('website') aria-invalid="true" aria-describedby="website-error" @enderror>
            @error('website')<p id=\"website-error\" class=\"text-sm text-red-400 mt-1\">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Instagram</label>
            <input type="text" name="instagram" value="{{ old('instagram', $artist->instagram) }}"
                   placeholder="@handle (or just handle)"
                   class="input-base {{ $errors->has('instagram') ? 'input-error' : '' }}" @error('instagram') aria-invalid="true" aria-describedby="instagram-error" @enderror>
            @error('instagram')<p id=\"instagram-error\" class=\"text-sm text-red-400 mt-1\">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-text mb-1.5">Twitter / X</label>
            <input type="text" name="twitter" value="{{ old('twitter', $artist->twitter) }}"
                   placeholder="@handle (or just handle)"
                   class="input-base {{ $errors->has('twitter') ? 'input-error' : '' }}" @error('twitter') aria-invalid="true" aria-describedby="twitter-error" @enderror>
            @error('twitter')<p id=\"twitter-error\" class=\"text-sm text-red-400 mt-1\">{{ $message }}</p>@enderror
        </div>
    </div>
</div>
