@if(! empty($gallery->custom_domain))
    @php
        $isVerified = $gallery->isCustomDomainVerified();
        $txtHost = $gallery->domainVerificationTxtHost();
        $txtValue = $gallery->domainVerificationTxtValue();
    @endphp

    <div class="mt-4 rounded-lg border border-gray-700 bg-gray-900/50 p-4">
        <div class="flex items-center justify-between">
            <h4 class="text-sm font-semibold text-gray-200">
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
            <p class="mt-2 text-xs text-gray-400">
                Your custom domain is verified and serving traffic via Coolify.
                SSL is provisioned automatically (may take 1–5 minutes after
                first verification).
            </p>
        @else
            <p class="mt-2 text-xs text-gray-400">
                To prove you own <code class="text-gray-200">{{ $gallery->custom_domain }}</code>,
                add the following TXT record to your DNS:
            </p>

            <dl class="mt-3 space-y-2 text-xs">
                <div>
                    <dt class="text-gray-500">Type</dt>
                    <dd class="font-mono text-gray-200">TXT</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Host / Name</dt>
                    <dd class="font-mono text-gray-200 break-all">{{ $txtHost }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Value</dt>
                    <dd class="font-mono text-gray-200 break-all">{{ $txtValue }}</dd>
                </div>
            </dl>

            <p class="mt-3 text-xs text-gray-500">
                DNS propagation can take 5–60 minutes. We automatically retry
                every hour, or you can click below to check now.
            </p>

            <form method="POST" action="{{ route('admin.galleries.verify-domain', $gallery) }}" class="mt-3">
                @csrf
                <button type="submit"
                        class="btn btn-sm btn-primary">
                    Verify domain now
                </button>
            </form>
        @endif
    </div>
@endif
