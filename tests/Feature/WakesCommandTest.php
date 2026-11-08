<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

test('wakes lists what reached the container, most first', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/wakers' => Http::response(['data' => [
            'hours' => 24,
            'origin_requests' => 1234,
            'storage_requests' => 12,
            'complete' => false,
            'paths' => [
                ['path' => '/?utm_source=…', 'requests' => 800, 'statuses' => ['MISS' => 790, 'EXPIRED' => 10]],
                ['path' => '/contact', 'requests' => 34, 'statuses' => ['-' => 34]],
            ],
            'unavailable' => null,
        ]]),
    ]);

    $this->artisan('wakes', ['environment' => 'production'])
        ->expectsOutputToContain('1,234 requests reached the web container of production in the last 24 hours.')
        ->expectsTable(['Address', 'Requests', 'Cache status'], [
            ['/?utm_source=…', '800', 'MISS 790, EXPIRED 10'],
            ['/contact', '34', 'no cache 34'],
        ])
        ->expectsOutputToContain('counted from the newest')
        ->expectsOutputToContain('12 requests for built and versioned files went to your storage zone instead')
        ->assertSuccessful();
});

test('a log that cannot be read says why', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/wakers' => Http::response(['data' => ['hours' => 24, 'origin_requests' => 0, 'storage_requests' => 0, 'complete' => true, 'paths' => [], 'unavailable' => 'logging_off']]),
    ]);

    $this->artisan('wakes', ['environment' => 'production'])
        ->expectsOutputToContain('Logging is off for this pull zone at bunny.net')
        ->assertFailed();
});

test('pages the edge took from the storage zone are named apart (BL-271)', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/wakers' => Http::response(['data' => [
            'hours' => 24,
            'origin_requests' => 615,
            'storage_requests' => 0,
            'page_requests' => 6186,
            'pages_known' => false,
            'complete' => true,
            'paths' => [['path' => '/mcp', 'requests' => 48, 'statuses' => ['-' => 48]]],
            'unavailable' => null,
        ]]),
    ]);

    $this->artisan('wakes', ['environment' => 'production'])
        ->expectsOutputToContain('615 requests reached the web container of production')
        ->expectsOutputToContain('6,186 requests were pages the edge took from your storage zone (edge.keep_pages)')
        ->expectsOutputToContain('more folders of pages than are read at once')
        ->assertSuccessful();
});
