{{-- resources/views/layouts/partials/cookie-banner.blade.php --}}
<div
    x-data="cookieBanner()"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-4"
    x-transition:enter-end="opacity-100 translate-y-0"
    class="fixed bottom-0 left-0 right-0 z-50 bg-gray-900 border-t border-gray-700 shadow-lg"
>
    <div class="max-w-5xl mx-auto px-4 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <p class="text-gray-300 text-sm">
            We use cookies to enhance your experience, analyse site traffic, and personalise content.
            By continuing to use this site, you accept our
            <a href="/privacy" class="text-purple-400 hover:text-purple-300 underline">Privacy Policy</a>.
        </p>
        <div class="flex items-center gap-3 flex-shrink-0">
            <button
                x-on:click="decline()"
                class="text-gray-400 hover:text-gray-200 text-sm px-4 py-2 rounded-lg border border-gray-700 hover:border-gray-500 transition"
            >
                Decline
            </button>
            <button
                x-on:click="accept()"
                class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white text-sm font-semibold px-5 py-2 rounded-lg transition"
            >
                Accept
            </button>
        </div>
    </div>
</div>

<script>
function cookieBanner() {
    return {
        show: false,
        init() {
            // Show banner only if no consent cookie exists
            if (!this.getCookie('exospace_cookie_consent')) {
                this.show = true;
            }
        },
        accept() {
            this.setCookie('exospace_cookie_consent', 'accepted', 365);
            this.show = false;
        },
        decline() {
            this.setCookie('exospace_cookie_consent', 'declined', 365);
            this.show = false;
        },
        getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        },
        setCookie(name, value, days) {
            const expires = new Date(Date.now() + days * 86400000).toUTCString();
            document.cookie = `${name}=${value}; expires=${expires}; path=/; SameSite=Lax`;
        }
    }
}
</script>