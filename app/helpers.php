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