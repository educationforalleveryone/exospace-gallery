@php $isEdit = isset($artist) && $artist->exists; @endphp

<div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-5 space-y-4">

    {{-- Name + slug --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-300 mb-1">Name <span class="text-red-400">*</span></label>
            <input type="text" name="name" value="{{ old('name', $artist->name) }}"
                   class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500" required maxlength="100">
            @error('name')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Slug</label>
            <input type="text" name="slug" value="{{ old('slug', $artist->slug) }}"
                   placeholder="auto from name"
                   class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500 font-mono text-sm">
            @error('slug')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
        </div>
    </div>

    {{-- Portrait --}}
    <div>
        <label class="block text-sm font-medium text-gray-300 mb-1">Portrait photo</label>
        <input type="file" name="portrait" accept="image/png,image/jpeg,image/webp"
               class="w-full text-sm text-gray-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-gray-700 file:text-gray-200 hover:file:bg-gray-600">
        <p class="text-xs text-gray-500 mt-1">PNG / JPG / WEBP, max 2 MB. Square aspect recommended.</p>
        @error('portrait')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
        @if($isEdit && $artist->portrait_url)
            <div class="mt-2 flex items-center gap-3">
                <img src="{{ $artist->portrait_url }}" alt="{{ $artist->name ?: 'Artist portrait' }}" class="w-16 h-16 rounded-full object-cover border border-gray-700">
                <a href="{{ $artist->portrait_url }}" target="_blank" class="text-xs text-blue-400 hover:underline">View current</a>
            </div>
        @endif
    </div>

    {{-- Bio --}}
    <div>
        <label class="block text-sm font-medium text-gray-300 mb-1">Bio</label>
        <textarea name="bio" rows="4" maxlength="2000"
                  class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500"
                  placeholder="Artist biography, statement, or notes…">{{ old('bio', $artist->bio) }}</textarea>
        @error('bio')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
    </div>

    {{-- SEO OS (Iteration 6): curator-facing SEO overrides. Leave blank to
         use the automatic title/description generated from real data. --}}
    <div>
        <label class="block text-sm font-medium text-gray-300 mb-1">
            SEO title <span class="text-gray-600 font-normal text-xs">(optional — auto-generated when empty)</span>
        </label>
        <input type="text" name="seo_title" value="{{ old('seo_title', $artist->seoProfile?->title_override) }}" maxlength="200"
               placeholder="{{ $artist->name }} — Artist Profile & 3D Exhibitions"
               class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
        @error('seo_title')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-300 mb-1">
            SEO description <span class="text-gray-600 font-normal text-xs">(optional — max 300 chars)</span>
        </label>
        <textarea name="seo_description" rows="2" maxlength="300"
                  placeholder="Shown in search results. Auto-generated from the bio when empty."
                  class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">{{ old('seo_description', $artist->seoProfile?->description_override) }}</textarea>
        @error('seo_description')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
    </div>

    {{-- Location --}}
    <div>
        <label class="block text-sm font-medium text-gray-300 mb-1">Location</label>
        <input type="text" name="location" value="{{ old('location', $artist->location) }}"
               placeholder="Berlin, Germany"
               class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
        @error('location')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
    </div>

    {{-- Contact + socials --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Public email</label>
            <input type="email" name="email" value="{{ old('email', $artist->email) }}"
                   placeholder="artist@example.com"
                   class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
            @error('email')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Website</label>
            <input type="url" name="website" value="{{ old('website', $artist->website) }}"
                   placeholder="https://artist-website.com"
                   class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
            @error('website')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Instagram</label>
            <input type="text" name="instagram" value="{{ old('instagram', $artist->instagram) }}"
                   placeholder="@handle (or just handle)"
                   class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
            @error('instagram')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Twitter / X</label>
            <input type="text" name="twitter" value="{{ old('twitter', $artist->twitter) }}"
                   placeholder="@handle (or just handle)"
                   class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
            @error('twitter')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
        </div>
    </div>
</div>
