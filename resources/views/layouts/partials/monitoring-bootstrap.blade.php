{{-- Injects the shared browser error-reporting config before any app bundle. --}}
<script nonce="@nonce">
    window.EXOSPACE_SENTRY_DSN = @js(config('sentry.dsn'));
    window.EXOSPACE_RELEASE = @js(config('sentry.release'));
    window.EXOSPACE_ENVIRONMENT = @js(config('sentry.environment'));
</script>
