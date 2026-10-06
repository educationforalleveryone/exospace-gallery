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

if (! function_exists('json_canonical')) {
    /**
     * Return a key-order-independent representation of a decoded JSON value.
     *
     * Associative arrays are sorted recursively (list order is preserved, and
     * nested objects inside lists are canonicalised too) so two JSON documents
     * that differ only in object key order compare equal.
     *
     * Why this exists: MySQL re-serialises JSON columns on storage (shortest
     * key first, then alphabetical, and it normalises spacing), while SQLite
     * and Laravel's json_encode() preserve insertion order. Anything that
     * hashes or compares the JSON a column RETURNS must canonicalise it first,
     * otherwise every fresh MySQL database behaves differently from SQLite.
     */
    function json_canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map('json_canonical', $value);
    }
}

if (! function_exists('json_arrays_equal')) {
    /**
     * Order-insensitive equality for decoded JSON values. Use this instead of
     * `===` whenever one side was decoded from a JSON column on MySQL: the
     * engine reorders object keys, which makes `===` fail on identical data.
     */
    function json_arrays_equal(mixed $a, mixed $b): bool
    {
        return json_canonical($a) === json_canonical($b);
    }
}
