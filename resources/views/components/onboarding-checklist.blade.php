{{--
    Curator onboarding checklist (Task H49 / audit MX3 — resurrected ITERATION-2).

    Shows a dismissible checklist on the dashboard for users mid-journey
    (galleries exist but the first exhibition isn't published/shared yet).
    Each step links to the relevant admin page. The checklist auto-hides
    once all steps are done or the user dismisses it (localStorage).

    ITERATION-2 fixes:
      - Step 4 previously pointed at a nonexistent "Active" toggle in
        gallery settings. Galleries now have a real publish flow: the
        Publish button on the gallery edit page (POST
        /admin/galleries/{id}/publish, requires at least one artwork).
      - Steps are driven by the data the DashboardController already
        computes (totalImages, hasPublishedGallery) — previously these
        props were passed but the component was never rendered anywhere
        (dead code since it was written).

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

    // Best targets for the mid-journey links: a gallery still missing
    // artwork (upload step), else the newest gallery; and the first
    // draft (publish step), else the newest live gallery (share step).
    $uploadTarget = $user->galleries()
        ->whereDoesntHave('images')
        ->orderBy('created_at')
        ->first()
        ?? $user->galleries()->orderBy('created_at')->first();
    $draftTarget = $user->galleries()->where('is_active', false)
        ->orderBy('created_at')->first();
    $liveTarget = $user->galleries()->where('is_active', true)
        ->orderBy('created_at')->first();
@endphp

@if(! $allDone)
<div x-data="{ dismissed: localStorage.getItem('exospace_onboarded') === '1' }"
     x-show="!dismissed"
     x-cloak
     class="bg-gradient-to-br from-brand-900/30 to-brand-900/20 border border-brand-700/30 rounded-2xl p-5 mb-6">

    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            <h3 class="text-sm font-semibold text-brand-300">Get started with Exospace</h3>
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

        {{-- Step 4: Publish gallery (ITERATION-2: real publish flow) --}}
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
                <span class="text-brand-400 hover:text-brand-300 transition cursor-pointer"
                      data-click="copyGalleryLink" data-arg="{{ route('gallery.view', $liveTarget->slug) }}">
                    Share your gallery link
                </span>
            @endif
        </div>
        @endif
    </div>

    @if($allDone)
    <div class="mt-4 pt-3 border-t border-brand-700/20">
        <p class="text-xs text-emerald-400 flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            All set! Your gallery is live.
        </p>
    </div>
    @endif
</div>

{{-- CSP-safe helper for the copy-to-clipboard step (replaced inline onclick) --}}
<script nonce="@nonce">
window.copyGalleryLink = function(url) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            if (window.toast) window.toast('Gallery link copied!', 'success');
        });
    }
};
</script>
@endif
