@php $isEdit = isset($artist) && $artist->exists; @endphp

<div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-5 space-y-4">

    {{-- Name + slug --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="md:col-span-2">
            <label for="artist-name" class="label-text mb-1.5">Name <span class="text-red-400" aria-hidden="true">*</span></label>
            <input type="text" id="artist-name" name="name" value="{{ old('name', $artist->name) }}" required maxlength="100" aria-required="true"
                   class="input-base {{ $errors->has('name') ? 'input-error' : '' }}" @error('name') aria-invalid="true" aria-describedby="artist-name-error" @enderror>
            @error('name')<p id="artist-name-error" class="text-sm text-red-400 mt-1" role="alert">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="artist-slug" class="label-text mb-1.5">Slug</label>
            <input type="text" id="artist-slug" name="slug" value="{{ old('slug', $artist->slug) }}"
                   placeholder="auto from name"
                   class="input-base font-mono {{ $errors->has('slug') ? 'input-error' : '' }}" @error('slug') aria-invalid="true" aria-describedby="artist-slug-error" @enderror>
            @error('slug')<p id="artist-slug-error" class="text-sm text-red-400 mt-1" role="alert">{{ $message }}</p>@enderror
        </div>
    </div>

    {{-- Portrait --}}
    <div x-data="{ fileName: '' }">
        <label for="artist-portrait" class="label-text mb-1.5">Portrait photo</label>
        <input type="file" id="artist-portrait" name="portrait" accept="image/png,image/jpeg,image/webp"
               @change="fileName = $event.target.files?.[0]?.name ?? ''"
               class="file-base {{ $errors->has('portrait') ? 'input-error' : '' }}" @error('portrait') aria-invalid="true" aria-describedby="artist-portrait-error" @enderror>
        <p class="text-xs text-gray-400 mt-1" x-cloak x-show="fileName">
            Selected: <span class="text-gray-300" x-text="fileName"></span>
        </p>
        <p class="text-xs text-gray-500 mt-1">PNG / JPG / WEBP, max 2 MB. Square aspect recommended.</p>
        @error('portrait')<p id="artist-portrait-error" class="text-sm text-red-400 mt-1" role="alert">{{ $message }}</p>@enderror
        @if($isEdit && $artist->portrait_url)
            <div class="mt-2 flex items-center gap-3">
                <img src="{{ $artist->portrait_url }}" alt="{{ $artist->name ?: 'Artist portrait' }}" class="w-16 h-16 rounded-full object-cover border border-gray-700">
                <a href="{{ $artist->portrait_url }}" target="_blank" class="text-xs text-blue-400 hover:underline">View current</a>
            </div>
        @endif
    </div>

    {{-- Bio --}}
    <div>
        <label for="artist-bio" class="label-text mb-1.5">Bio</label>
        <textarea name="bio" id="artist-bio" rows="4" maxlength="2000"
                  class="input-base {{ $errors->has('bio') ? 'input-error' : '' }}" @error('bio') aria-invalid="true" aria-describedby="artist-bio-error" @enderror
                  placeholder="Artist biography, statement, or notes…">{{ old('bio', $artist->bio) }}</textarea>
        @error('bio')<p id="artist-bio-error" class="text-sm text-red-400 mt-1" role="alert">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="artist-seo-title" class="label-text mb-1.5">
            SEO title <span class="text-gray-500 font-normal text-xs">(optional — auto-generated when empty)</span>
        </label>
        <input type="text" id="artist-seo-title" name="seo_title" value="{{ old('seo_title', $artist->seoProfile?->title_override) }}" maxlength="200"
               placeholder="{{ $artist->name }} — Artist Profile & 3D Exhibitions"
               class="input-base {{ $errors->has('seo_title') ? 'input-error' : '' }}" @error('seo_title') aria-invalid="true" aria-describedby="artist-seo-title-error" @enderror>
        @error('seo_title')<p id="artist-seo-title-error" class="text-sm text-red-400 mt-1" role="alert">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="artist-seo-description" class="label-text mb-1.5">
            SEO description <span class="text-gray-500 font-normal text-xs">(optional — max 300 chars)</span>
        </label>
        <textarea name="seo_description" id="artist-seo-description" rows="2" maxlength="300"
                  placeholder="Shown in search results. Auto-generated from the bio when empty."
                  class="input-base {{ $errors->has('seo_description') ? 'input-error' : '' }}" @error('seo_description') aria-invalid="true" aria-describedby="artist-seo-description-error" @enderror>{{ old('seo_description', $artist->seoProfile?->description_override) }}</textarea>
        @error('seo_description')<p id="artist-seo-description-error" class="text-sm text-red-400 mt-1" role="alert">{{ $message }}</p>@enderror
    </div>

    {{-- Location --}}
    <div>
        <label for="artist-location" class="label-text mb-1.5">Location</label>
        <input type="text" id="artist-location" name="location" value="{{ old('location', $artist->location) }}"
               placeholder="Berlin, Germany"
               class="input-base {{ $errors->has('location') ? 'input-error' : '' }}" @error('location') aria-invalid="true" aria-describedby="artist-location-error" @enderror>
        @error('location')<p id="artist-location-error" class="text-sm text-red-400 mt-1" role="alert">{{ $message }}</p>@enderror
    </div>

    {{-- Contact + socials --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="artist-email" class="label-text mb-1.5">Public email</label>
            <input type="email" id="artist-email" name="email" value="{{ old('email', $artist->email) }}"
                   placeholder="artist@example.com"
                   class="input-base {{ $errors->has('email') ? 'input-error' : '' }}" @error('email') aria-invalid="true" aria-describedby="artist-email-error" @enderror>
            @error('email')<p id="artist-email-error" class="text-sm text-red-400 mt-1" role="alert">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="artist-website" class="label-text mb-1.5">Website</label>
            <input type="url" id="artist-website" name="website" value="{{ old('website', $artist->website) }}"
                   placeholder="https://artist-website.com"
                   class="input-base {{ $errors->has('website') ? 'input-error' : '' }}" @error('website') aria-invalid="true" aria-describedby="artist-website-error" @enderror>
            @error('website')<p id="artist-website-error" class="text-sm text-red-400 mt-1" role="alert">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="artist-instagram" class="label-text mb-1.5">Instagram</label>
            <input type="text" id="artist-instagram" name="instagram" value="{{ old('instagram', $artist->instagram) }}"
                   placeholder="@handle (or just handle)"
                   class="input-base {{ $errors->has('instagram') ? 'input-error' : '' }}" @error('instagram') aria-invalid="true" aria-describedby="artist-instagram-error" @enderror>
            @error('instagram')<p id="artist-instagram-error" class="text-sm text-red-400 mt-1" role="alert">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="artist-twitter" class="label-text mb-1.5">Twitter / X</label>
            <input type="text" id="artist-twitter" name="twitter" value="{{ old('twitter', $artist->twitter) }}"
                   placeholder="@handle (or just handle)"
                   class="input-base {{ $errors->has('twitter') ? 'input-error' : '' }}" @error('twitter') aria-invalid="true" aria-describedby="artist-twitter-error" @enderror>
            @error('twitter')<p id="artist-twitter-error" class="text-sm text-red-400 mt-1" role="alert">{{ $message }}</p>@enderror
        </div>
    </div>
</div>
