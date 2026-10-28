<?php

/*
|--------------------------------------------------------------------------
| Security Headers
|--------------------------------------------------------------------------
|
| Only the settings that differ from bepsvpt/secure-headers' defaults. The
| package merges its own config (vendor/bepsvpt/secure-headers/config) for
| every key not set here, so the rest keeps up with package updates.
|
*/

return [

    // Keeps the full Referer on same-origin requests. url()->previous(), and
    // so redirect()->back() after a validation error, reads it first, and its
    // session fallback skips Inertia visits. The default, no-referrer, would
    // send people back to the wrong page. Other sites see only the origin.
    'referrer-policy' => 'strict-origin-when-cross-origin',

    // Windows from other sites can't reach ours, and ours can't reach theirs.
    'cross-origin-opener-policy' => 'same-origin',

    // No include-sub-domains or preload until the custom domain is set up.
    // The header is sent over HTTP too, where browsers ignore it.
    'hsts' => [
        'enable' => true,
        'max-age' => 31536000,
        'include-sub-domains' => false,
        'preload' => false,
    ],

    // The Content Security Policy comes from spatie/laravel-csp in sub-plan 11.
    'csp' => [
        'enable' => false,
    ],

];
