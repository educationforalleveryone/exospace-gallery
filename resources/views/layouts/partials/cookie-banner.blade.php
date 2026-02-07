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
            // Improved cookie check - more reliable regex pattern
            const consent = this.getCookie('exospace_cookie_consent');
            if (!consent) {
                this.show = true;
            }
        },
        
        accept() {
            this.setCookie('exospace_cookie_consent', 'accepted', 365);
            this.show = false;
            // Optional: Trigger any analytics or tracking initialization here
            this.initializeTracking();
        },
        
        decline() {
            this.setCookie('exospace_cookie_consent', 'declined', 365);
            this.show = false;
            // Optional: Disable any tracking scripts here
            this.disableTracking();
        },
        
        getCookie(name) {
            // More robust cookie retrieval method
            const nameEQ = name + "=";
            const cookies = document.cookie.split(';');
            
            for (let i = 0; i < cookies.length; i++) {
                let cookie = cookies[i].trim();
                if (cookie.indexOf(nameEQ) === 0) {
                    return cookie.substring(nameEQ.length, cookie.length);
                }
            }
            return null;
        },
        
        setCookie(name, value, days) {
            const date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            const expires = "expires=" + date.toUTCString();
            
            // CRITICAL: Set cookie at root path with SameSite for security and wide compatibility
            // Secure flag should be added in production (HTTPS only)
            const secure = window.location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = name + "=" + value + "; " + expires + "; path=/; SameSite=Lax" + secure;
        },
        
        initializeTracking() {
            // Add your tracking initialization code here
            // Example: Google Analytics, Meta Pixel, etc.
            console.log('Tracking accepted - initialize analytics');
        },
        
        disableTracking() {
            // Add your tracking disable code here
            console.log('Tracking declined - analytics disabled');
        }
    }
}
</script>