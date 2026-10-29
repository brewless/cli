<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A domain whose record the customer keeps elsewhere, as the API lists it.
 */
function tracked(array $overrides = []): array
{
    return [
        'id' => 'd1',
        'hostname' => 'shop.example.eu',
        'status' => 'awaiting_dns',
        'kept_elsewhere' => true,
        'record' => ['type' => 'CNAME', 'name' => 'shop.example.eu', 'value' => 'shop.b-cdn.net'],
        'found' => 'CNAME oldhost.example.net',
        'reason' => 'points_elsewhere',
        'gives_up_at' => '2026-10-30T22:00:00+01:00',
        ...$overrides,
    ];
}

function domainList(array $domains): array
{
    return [
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/domains' => Http::response(['data' => ['route' => 'bunny', 'target' => 'shop.b-cdn.net', 'domains' => $domains, 'unavailable' => null]]),
    ];
}

beforeEach(function (): void {
    signedIn();
    project();
});

test('domain:list shows each domain with its state, and the record to make for one kept elsewhere', function (): void {
    Http::fake(domainList([
        ['hostname' => 'example.eu', 'certificate' => true, 'tracked' => null],
        ['hostname' => 'shop.example.eu', 'certificate' => false, 'tracked' => tracked()],
    ]));

    $this->artisan('domain:list', ['environment' => 'production'])
        ->expectsTable(['Domain', 'State', 'DNS', 'Certificate'], [
            ['example.eu', 'Live', 'through Brewless', 'yes'],
            ['shop.example.eu', 'Waiting for DNS', 'your own provider', 'not yet'],
        ])
        ->expectsOutputToContain('shop.example.eu: make this record at your DNS provider')
        ->expectsOutputToContain('CNAME  shop.example.eu  ->  shop.b-cdn.net')
        ->expectsOutputToContain('Found the last time: CNAME oldhost.example.net')
        ->expectsOutputToContain('Brewless looks it up by itself until')
        ->assertSuccessful();
});

test('domain:list without domains says how to add one', function (): void {
    Http::fake(domainList([]));

    $this->artisan('domain:list', ['environment' => 'production'])
        ->expectsOutputToContain('has no domain of its own yet')
        ->assertSuccessful();
});

test('domain:check looks the record up now and says what it found', function (): void {
    Http::fake([
        ...domainList([['hostname' => 'shop.example.eu', 'certificate' => false, 'tracked' => tracked(['status' => 'failed'])]]),
        '*/domains/d1/check' => Http::response(['data' => tracked()]),
    ]);

    $this->artisan('domain:check', ['environment' => 'production', 'hostname' => 'Shop.Example.eu'])
        ->expectsOutputToContain('does not point the right way yet')
        ->expectsOutputToContain('Wanted: CNAME  shop.example.eu  ->  shop.b-cdn.net')
        ->expectsOutputToContain('Found:  CNAME oldhost.example.net')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/domains/d1/check'));
});

test('domain:check of a record that points the right way says the certificate is on its way', function (): void {
    Http::fake([
        ...domainList([['hostname' => 'shop.example.eu', 'certificate' => false, 'tracked' => tracked()]]),
        '*/domains/d1/check' => Http::response(['data' => tracked(['reason' => 'certificate_pending', 'found' => 'CNAME shop.b-cdn.net'])]),
    ]);

    $this->artisan('domain:check', ['environment' => 'production', 'hostname' => 'shop.example.eu'])
        ->expectsOutputToContain('The certificate is being issued')
        ->assertSuccessful();
});

test('domain:check of a domain whose dns brewless manages says there is nothing to look up', function (): void {
    Http::fake(domainList([['hostname' => 'shop.example.eu', 'certificate' => true, 'tracked' => tracked(['kept_elsewhere' => false])]]));

    $this->artisan('domain:check', ['environment' => 'production', 'hostname' => 'shop.example.eu'])
        ->expectsOutputToContain('is not a domain of production whose DNS you keep at your own provider')
        ->assertFailed();
});
