{{--
    Custom-domain DNS verification panel (Task C06).

    Include this from admin/galleries/edit.blade.php inside the custom-domain
    form section. It renders three possible states:

    1. No custom_domain set:
       Nothing rendered.

    2. Custom_domain set but NOT verified (custom_domain_verified_at is null):
       Shows the TXT record the user must add to their DNS, plus a "Verify
       domain" button that POSTs to the galleries.verify-domain route.

    3. Custom_domain set AND verified:
       Shows a "Verified" badge + the date the domain was verified.

    Required variable: $gallery (the App\Models\Gallery being edited)
--}}
@if(! empty($gallery->custom_domain))
    @php
        $isVerified = $gallery->isCustomDomainVerified();
        $txtHost = $gallery->domainVerificationTxtHost();
        $txtValue = $gallery->domainVerificationTxtValue();
    @endphp

    <div class="mt-4 rounded-lg border border-slate-700 bg-slate-900/50 p-4">
        <div class="flex items-center justify-between">
            <h4 class="text-sm font-semibold text-slate-200">
                DNS Verification
            </h4>
            @if($isVerified)
                <span class="inline-flex items-center rounded-full bg-emerald-500/20 px-2.5 py-0.5 text-xs font-medium text-emerald-400">
                    Verified {{ $gallery->custom_domain_verified_at?->diffForHumans() }}
                </span>
            @else
                <span class="inline-flex items-center rounded-full bg-amber-500/20 px-2.5 py-0.5 text-xs font-medium text-amber-400">
                    Pending verification
                </span>
            @endif
        </div>

        @if($isVerified)
            <p class="mt-2 text-xs text-slate-400">
                Your custom domain is verified and serving traffic via Coolify.
                SSL is provisioned automatically (may take 1–5 minutes after
                first verification).
            </p>
        @else
            <p class="mt-2 text-xs text-slate-400">
                To prove you own <code class="text-slate-200">{{ $gallery->custom_domain }}</code>,
                add the following TXT record to your DNS:
            </p>

            <dl class="mt-3 space-y-2 text-xs">
                <div>
                    <dt class="text-slate-500">Type</dt>
                    <dd class="font-mono text-slate-200">TXT</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Host / Name</dt>
                    <dd class="font-mono text-slate-200 break-all">{{ $txtHost }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Value</dt>
                    <dd class="font-mono text-slate-200 break-all">{{ $txtValue }}</dd>
                </div>
            </dl>

            <p class="mt-3 text-xs text-slate-500">
                DNS propagation can take 5–60 minutes. We automatically retry
                every hour, or you can click below to check now.
            </p>

            <form method="POST" action="{{ route('admin.galleries.verify-domain', $gallery) }}" class="mt-3">
                @csrf
                <button type="submit"
                        class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">
                    Verify domain now
                </button>
            </form>
        @endif
    </div>
@endif
