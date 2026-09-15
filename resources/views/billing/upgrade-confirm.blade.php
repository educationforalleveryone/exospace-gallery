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
