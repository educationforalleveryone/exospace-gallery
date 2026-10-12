<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Confirm checkout" description="One quick confirmation before we hand you over to our payment provider."/>
    </x-slot>

    <div class="page-shell">
        <div class="max-w-lg mx-auto">
            <div class="card card-pad">
                <h2 class="eyebrow mb-4">{{ $isRecurring ? 'Subscription' : 'One-time purchase' }}</h2>

                <div class="mb-6 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Plan</span>
                        <span class="text-lg font-semibold text-gray-50 capitalize">{{ ucfirst($plan) }}</span>
                    </div>
                    @php
                        $price = $isRecurring
                            ? ($plan === 'pro' ? config('services.2checkout.recurring_price_pro_monthly') : config('services.2checkout.recurring_price_studio_monthly'))
                            : ($plan === 'pro' ? config('services.2checkout.price_pro') : config('services.2checkout.price_studio'));
                    @endphp
                    @if($price !== null)
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Amount</span>
                        <span class="text-lg font-semibold text-gray-50">${{ $price }}{{ $isRecurring ? '/month' : '' }}</span>
                    </div>
                    @endif
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Account</span>
                        <span class="text-gray-50">{{ $user->email }}</span>
                    </div>
                </div>

                <form action="{{ route('billing.upgrade.start', $plan) }}" method="POST"
                      data-busy data-busy-label="Redirecting…">
                    @csrf
                    @if($isRecurring)
                    <input type="hidden" name="recurring" value="1">
                    @endif

                    {{-- Order terms, shown right before payment. Wording differs
                         for subscriptions (auto-renewal must be disclosed). --}}
                    <div class="mb-4 rounded-lg border border-gray-700 bg-gray-900/40 p-4 text-sm text-gray-300 leading-relaxed" data-testid="order-terms">
                        @if($isRecurring)
                            <p>
                                This is a <strong class="text-gray-100">monthly subscription</strong>
                                @if($price !== null) of <strong class="text-gray-100">${{ $price }}/month</strong>@endif.
                                It renews automatically every month until you cancel. You can cancel at any time
                                from your Billing page; cancelling stops future charges and your plan stays active
                                until the end of the period you have paid for.
                            </p>
                        @else
                            <p>
                                This is a <strong class="text-gray-100">one-time payment</strong>
                                @if($price !== null) of <strong class="text-gray-100">${{ $price }}</strong>@endif
                                with no renewal and nothing to cancel. Access is delivered instantly after payment.
                            </p>
                        @endif
                        <p class="mt-2">
                            Payment is processed by 2Checkout (a Verifone company). You can request a refund within
                            14 days of payment under our <a href="{{ route('refund') }}" target="_blank" rel="noopener" class="text-brand-400 hover:text-brand-300 underline">Refund Policy</a>.
                        </p>
                    </div>

                    <label class="flex items-start gap-3 mb-5 cursor-pointer">
                        <input type="checkbox" name="accept_terms" value="1" id="accept_terms" required
                               class="checkbox-base mt-1" @checked(old('accept_terms')) />
                        <span class="text-sm text-gray-300">
                            I have read and agree to the
                            <a href="{{ route('terms') }}" target="_blank" rel="noopener" class="text-brand-400 hover:text-brand-300 underline">Terms of Service</a>,
                            the
                            <a href="{{ route('refund') }}" target="_blank" rel="noopener" class="text-brand-400 hover:text-brand-300 underline">Refund Policy</a>
                            and the
                            <a href="{{ route('privacy') }}" target="_blank" rel="noopener" class="text-brand-400 hover:text-brand-300 underline">Privacy Policy</a>.
                        </span>
                    </label>
                    @error('accept_terms')
                        <p class="text-sm text-red-400 -mt-3 mb-4" role="alert">{{ $message }}</p>
                    @enderror

                    <button type="submit" class="btn btn-primary w-full">
                        Continue to secure checkout
                    </button>
                </form>

                <a href="{{ route('billing.index') }}" class="btn btn-secondary w-full mt-3">
                    Cancel
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
