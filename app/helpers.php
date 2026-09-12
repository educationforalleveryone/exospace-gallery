<?php

declare(strict_types=1);

if (! function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        $request = app(\Illuminate\Http\Request::class);

        return (string) $request->attributes->get('csp_nonce', '');
    }
}