<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The domains of production as Brewless lists them, with one kept elsewhere.
 */
function keptElsewhere(): array
{
    return ['data' => ['route' => 'bunny', 'target' => 'shop.b-cdn.net', 'domains' => [
        ['hostname' => 'shop.example.eu', 'tracked' => ['id' => 'd1', 'kept_elsewhere' => true]],
    ]]];
}

beforeEach(function (): void {
    signedIn();
    project();
    config(['brewless.timezone' => 'Europe/Amsterdam']);
});

test('domain:check says the record is not there yet and until when Brewless looks every quarter of an hour', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/domains' => Http::response(keptElsewhere()),
        '*/domains/d1/check' => Http::response(['data' => [
            'status' => 'awaiting_dns',
            'reason' => 'record_missing',
            'found' => null,
            'record' => ['type' => 'CNAME', 'name' => 'shop.example.eu', 'value' => 'shop.b-cdn.net'],
            'manual_until' => '2026-11-12T21:24:00.000000Z',
        ]]),
    ]);

    $this->artisan('domain:check', ['environment' => 'production', 'hostname' => 'shop.example.eu'])
        ->expectsOutputToContain('does not point the right way yet')
        ->expectsOutputToContain('Wanted: CNAME  shop.example.eu  ->  shop.b-cdn.net')
        ->expectsOutputToContain('Brewless looks again every 15 minutes until 22:24 (Europe/Amsterdam), and stops as soon as it finds the record.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/domains/d1/check'));
});

test('domain:check says nothing of the quarter-hour looks once the record is found', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/domains' => Http::response(keptElsewhere()),
        '*/domains/d1/check' => Http::response(['data' => ['status' => 'awaiting_dns', 'reason' => 'certificate_pending', 'manual_until' => null]]),
    ]);

    $this->artisan('domain:check', ['environment' => 'production', 'hostname' => 'shop.example.eu'])
        ->expectsOutputToContain('points the right way. The certificate is being issued')
        ->doesntExpectOutputToContain('every 15 minutes')
        ->assertSuccessful();
});
