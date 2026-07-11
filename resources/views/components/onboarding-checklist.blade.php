{{--
    Curator onboarding checklist (Task H49 / audit MX3).

    Shows a dismissible checklist on the dashboard for new users who
    haven't completed key activation steps. Each step links to the
    relevant admin page. The checklist auto-hides once all steps are
    done or the user dismisses it (stored in localStorage).

    Steps:
      1. Verify your email (links to /verify-email)
      2. Create your first gallery (links to /admin/galleries/create)
      3. Upload your first artwork (links to the gallery edit page)
      4. Publish your gallery (activate it)
      5. Share your gallery link

    Usage:
        <x-onboarding-checklist
            :user="$user"
            :galleries-count="$galleriesCount"
            :total-images="$totalImages"
            :has-published-gallery="$hasPublishedGallery"
        />
--}}
@php
    $emailVerified = ! is_null($user->email_verified_at);
    $hasGallery = $galleriesCount > 0;
    $hasImages = $totalImages > 0;
    $hasPublished = $hasPublishedGallery;
    $allDone = $emailVerified && $hasGallery && $hasImages && $hasPublished;
@endphp

@if(! $allDone)
<div x-data="{ dismissed: localStorage.getItem('exospace_onboarded') === '1' }"
     x-show="!dismissed"
     x-cloak
     class="bg-gradient-to-br from-purple-900/30 to-indigo-900/20 border border-purple-700/30 rounded-2xl p-5 mb-6">

    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            <h3 class="text-sm font-semibold text-purple-300">Get started with Exospace</h3>
        </div>
        <button @click="dismissed = true; localStorage.setItem('exospace_onboarded', '1')"
                class="text-gray-500 hover:text-gray-300 transition"
                aria-label="Dismiss onboarding checklist">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <div class="space-y-2">
        {{-- Step 1: Verify email --}}
        <div class="flex items-center gap-3 text-sm">
            @if($emailVerified)
                <svg class="w-4 h-4 text-green-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                <span class="text-gray-400 line-through">Verify your email</span>
            @else
                <svg class="w-4 h-4 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>
                <a href="{{ route('verification.notice') }}" class="text-purple-400 hover:text-purple-300 transition">Verify your email</a>
            @endif
        </div>

        {{-- Step 2: Create gallery --}}
        <div class="flex items-center gap-3 text-sm">
            @if($hasGallery)
                <svg class="w-4 h-4 text-green-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                <span class="text-gray-400 line-through">Create your first gallery</span>
            @else
                <svg class="w-4 h-4 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>
                <a href="{{ route('admin.galleries.create') }}" class="text-purple-400 hover:text-purple-300 transition">Create your first gallery</a>
            @endif
        </div>

        {{-- Step 3: Upload artwork --}}
        <div class="flex items-center gap-3 text-sm">
            @if($hasImages)
                <svg class="w-4 h-4 text-green-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                <span class="text-gray-400 line-through">Upload your first artwork</span>
            @else
                <svg class="w-4 h-4 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>
                @if($hasGallery)
                    <a href="{{ route('admin.galleries.edit', $user->galleries()->first()) }}" class="text-purple-400 hover:text-purple-300 transition">Upload your first artwork</a>
                @else
                    <span class="text-gray-500">Upload your first artwork (create a gallery first)</span>
                @endif
            @endif
        </div>

        {{-- Step 4: Publish gallery --}}
        <div class="flex items-center gap-3 text-sm">
            @if($hasPublished)
                <svg class="w-4 h-4 text-green-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                <span class="text-gray-400 line-through">Publish your gallery</span>
            @else
                <svg class="w-4 h-4 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>
                @if($hasGallery)
                    <span class="text-gray-300">Publish your gallery <span class="text-gray-500 text-xs">(toggle "Active" in gallery settings)</span></span>
                @else
                    <span class="text-gray-500">Publish your gallery (create one first)</span>
                @endif
            @endif
        </div>

        {{-- Step 5: Share --}}
        @if($hasPublished)
        <div class="flex items-center gap-3 text-sm">
            @if($user->galleries()->where('is_active', true)->first()?->view_count > 0)
                <svg class="w-4 h-4 text-green-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                <span class="text-gray-400 line-through">Share your gallery link</span>
            @else
                <svg class="w-4 h-4 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>
                <span class="text-purple-400 hover:text-purple-300 transition cursor-pointer"
                      data-click="copyGalleryLink" data-arg="{{ request()->schemeAndHttpHost() }}/gallery/{{ $user->galleries()->where('is_active', true)->first()?->slug }}">
                    Share your gallery link
                </span>
            @endif
        </div>
        @endif
    </div>

    @if($allDone)
    <div class="mt-4 pt-3 border-t border-purple-700/20">
        <p class="text-xs text-green-400 flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            All set! Your gallery is live.
        </p>
    </div>
    @endif
</div>

{{-- CSP-safe helper for the copy-to-clipboard step (replaced inline onclick) --}}
<script nonce="@nonce">
window.copyGalleryLink = function(url, e) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            if (window.toast) window.toast('Gallery link copied!', 'success');
        });
    }
};
</script>
@endif
