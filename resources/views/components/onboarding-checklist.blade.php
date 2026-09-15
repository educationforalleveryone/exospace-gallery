@php
    $emailVerified = ! is_null($user->email_verified_at);
    $hasGallery = $galleriesCount > 0;
    $hasImages = $totalImages > 0;
    $hasPublished = $hasPublishedGallery;
    $allDone = $emailVerified && $hasGallery && $hasImages && $hasPublished;

    $personalGalleries = fn () => $user->galleries()->whereNull('team_id');
    $uploadTarget = $personalGalleries()
        ->whereDoesntHave('images')
        ->orderBy('created_at')
        ->first()
        ?? $personalGalleries()->orderBy('created_at')->first();
    $draftTarget = $personalGalleries()->where('is_active', false)
        ->orderBy('created_at')->first();
    $liveTarget = $personalGalleries()->where('is_active', true)
        ->orderBy('created_at')->first();
@endphp

@if(! $allDone)
<div x-data="{ dismissed: window.exospaceStorage?.get('exospace_onboarded') === '1' }"
     x-show="!dismissed"
     x-cloak
     class="bg-gradient-to-br from-brand-900/30 to-brand-900/20 border border-brand-700/30 rounded-xl p-5 mb-6">

    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            <h3 class="text-sm font-semibold text-brand-300">Get started with Exospace</h3>
        </div>
        <button type="button"
                @click="dismissed = true; window.exospaceStorage?.set('exospace_onboarded', '1')"
                class="flex items-center justify-center w-8 h-8 -me-2 rounded-lg text-gray-500 hover:text-gray-300 hover:bg-white/[0.06] transition"
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
                <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                <span class="text-gray-400 line-through">Verify your email</span>
            @else
                <svg class="w-4 h-4 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>
                <a href="{{ route('verification.notice') }}" class="text-brand-400 hover:text-brand-300 transition">Verify your email</a>
            @endif
        </div>

        {{-- Step 2: Create gallery --}}
        <div class="flex items-center gap-3 text-sm">
            @if($hasGallery)
                <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                <span class="text-gray-400 line-through">Create your first gallery</span>
            @else
                <svg class="w-4 h-4 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>
                <a href="{{ route('admin.galleries.create') }}" class="text-brand-400 hover:text-brand-300 transition">Create your first gallery</a>
            @endif
        </div>

        {{-- Step 3: Upload artwork --}}
        <div class="flex items-center gap-3 text-sm">
            @if($hasImages)
                <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                <span class="text-gray-400 line-through">Upload your first artwork</span>
            @else
                <svg class="w-4 h-4 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>
                @if($uploadTarget)
                    <a href="{{ route('admin.galleries.edit', $uploadTarget) }}" class="text-brand-400 hover:text-brand-300 transition">Upload your first artwork</a>
                @else
                    <span class="text-gray-500">Upload your first artwork (create a gallery first)</span>
                @endif
            @endif
        </div>

        {{-- Step 4: Publish gallery --}}
        <div class="flex items-center gap-3 text-sm">
            @if($hasPublished)
                <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                <span class="text-gray-400 line-through">Publish your gallery</span>
            @else
                <svg class="w-4 h-4 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>
                @if($draftTarget)
                    <a href="{{ route('admin.galleries.edit', $draftTarget) }}" class="text-brand-400 hover:text-brand-300 transition">Publish your gallery</a>
                    <span class="text-gray-500 text-xs">(hit “Publish” at the top of the gallery page)</span>
                @elseif($hasGallery)
                    <span class="text-gray-300">Publish your gallery</span>
                    <span class="text-gray-500 text-xs">(already live — nice)</span>
                @else
                    <span class="text-gray-500">Publish your gallery (create one first)</span>
                @endif
            @endif
        </div>

        {{-- Step 5: Share --}}
        @if($hasPublished && $liveTarget)
        <div class="flex items-center gap-3 text-sm">
            @if($liveTarget->view_count > 0)
                <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                <span class="text-gray-400 line-through">Share your gallery link</span>
            @else
                <svg class="w-4 h-4 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>
                <button type="button"
                        data-click="copyGalleryLink" data-arg="{{ route('gallery.view', $liveTarget->slug) }}"
                        class="text-brand-400 hover:text-brand-300 transition text-left p-0 bg-transparent border-0 cursor-pointer">
                    Share your gallery link
                </button>
            @endif
        </div>
        @endif
    </div>
</div>

<script nonce="@nonce">
window.copyGalleryLink = function(url) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            if (window.toast) window.toast('Link copied', 'success');
        }, function() {
            window.location.href = url;
        });
    } else {
        window.location.href = url;
    }
};
</script>
@endif
