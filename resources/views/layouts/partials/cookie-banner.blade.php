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