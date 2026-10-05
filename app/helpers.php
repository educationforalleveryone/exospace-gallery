<?php

declare(strict_types=1);

if (! function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        $request = app(\Illuminate\Http\Request::class);

        return (string) $request->attributes->get('csp_nonce', '');
    }
}

if (! function_exists('email_subject_line')) {
    /**
     * Flatten user-controlled text for use in an email subject: line breaks
     * and other control characters never belong in a message header.
     */
    function email_subject_line(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
    }
}

if (! function_exists('address_lines')) {
    /**
     * Normalize an address configured through the environment: unquoted env
     * values keep literal "\n" sequences, so they are converted to real
     * newlines here. Used for the postal address shown on emails and the
     * unsubscribe pages.
     */
    function address_lines(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace('\n', "\n", $value);
    }
}
