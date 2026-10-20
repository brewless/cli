<?php

declare(strict_types=1);

return [
    // The domain organisations live under: <organisation>.<host>.
    'host' => env('BREWLESS_HOST', 'brewless.eu'),

    // Only a developer's own installation is plain http.
    'scheme' => env('BREWLESS_SCHEME', 'https'),

    // Where the sign-ins are kept. Defaults to ~/.config/brewless.
    'home' => env('BREWLESS_HOME'),

    // A pipeline token made in the console. When set, it is used instead of
    // the sign-in of this machine, so a pipeline needs no brewless login.
    'token' => env('BREWLESS_API_TOKEN'),
];
