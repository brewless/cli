<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    signedIn();
    project();
});

/**
 * The zones answer of an organisation with one managed zone.
 *
 * @return array<string, mixed>
 */
function dnsZones(): array
{
    return ['data' => [[
        'id' => 'z1',
        'name' => 'example.eu',
        'provider' => 'bunny',
        'external_id' => '9',
        'environments' => [['id' => 'env1', 'name' => 'Production', 'application_id' => 'app1', 'application' => 'Shop']],
    ]], 'connected' => true, 'can_manage' => true];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dnsRecord(array $overrides = []): array
{
    return [
        'id' => 1, 'type' => 'a', 'name' => '', 'value' => '203.0.113.7', 'ttl' => 300,
        'priority' => null, 'weight' => null, 'port' => null, 'flags' => null, 'tag' => null,
        'disabled' => false, 'linked_to' => null, 'editable' => true,
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $records
 * @return array<string, mixed>
 */
function dnsZone(array $records): array
{
    return ['data' => ['zone' => dnsZones()['data'][0], 'domain' => 'example.eu', 'records' => $records, 'unavailable' => null], 'can_manage' => true];
}

test('dns:list lists the zones, and with a zone its records', function (): void {
    Http::fake([
        '*/cli/api/dns' => Http::response(dnsZones()),
        '*/cli/api/dns/z1' => Http::response(dnsZone([
            dnsRecord(),
            dnsRecord(['id' => 4, 'type' => 'mx', 'value' => 'mx.example.eu', 'priority' => 10]),
            dnsRecord(['id' => 2, 'type' => 'pull_zone', 'name' => 'shop', 'value' => 'shop', 'linked_to' => '4242', 'editable' => false]),
        ])),
    ]);

    $this->artisan('dns:list')
        ->expectsTable(['Zone', 'Used by'], [['example.eu', 'Shop · Production']])
        ->assertSuccessful();

    $this->artisan('dns:list', ['zone' => 'Example.eu.'])
        ->expectsTable(['Id', 'Name', 'Type', 'Value', 'TTL', ''], [
            [1, 'example.eu', 'A', '203.0.113.7', 300, ''],
            [4, 'example.eu', 'MX', '10 mx.example.eu', 300, ''],
            [2, 'shop.example.eu', 'PULL ZONE', 'shop', 300, 'domain of an environment'],
        ])
        ->assertSuccessful();
});

test('a zone brewless does not manage is named with the ones it does', function (): void {
    Http::fake(['*/cli/api/dns' => Http::response(dnsZones())]);

    $this->artisan('dns:list', ['zone' => 'other.eu'])
        ->expectsOutputToContain('Brewless manages no DNS zone "other.eu". It manages: example.eu.')
        ->assertFailed();
});

test('dns:add sends the record with the fields its type has', function (): void {
    Http::fake([
        '*/cli/api/dns' => Http::response(dnsZones()),
        '*/cli/api/dns/z1' => Http::response(dnsZone([])),
        '*/cli/api/dns/z1/records' => Http::response(['data' => dnsRecord(['id' => 10, 'type' => 'mx', 'value' => 'mx.example.eu', 'priority' => 20])], 201),
    ]);

    $this->artisan('dns:add', ['zone' => 'example.eu', 'type' => 'MX', 'name' => '@', 'value' => 'mx.example.eu', '--priority' => '20'])
        ->expectsOutputToContain('MX example.eu → 20 mx.example.eu (id 10)')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/dns/z1/records')
        && $request->data() === ['type' => 'mx', 'name' => '@', 'value' => 'mx.example.eu', 'ttl' => 300, 'priority' => 20]);
});

test('dns:add passes on what the server refuses', function (): void {
    Http::fake([
        '*/cli/api/dns' => Http::response(dnsZones()),
        '*/cli/api/dns/z1' => Http::response(dnsZone([])),
        '*/cli/api/dns/z1/records' => Http::response(['errors' => ['value' => ['An A record points at an IPv4 address, such as 203.0.113.7.']]], 422),
    ]);

    $this->artisan('dns:add', ['zone' => 'example.eu', 'type' => 'a', 'name' => 'www', 'value' => 'shop.example.eu'])
        ->expectsOutputToContain('An A record points at an IPv4 address')
        ->assertFailed();
});

test('dns:update changes what is given and keeps the rest', function (): void {
    Http::fake([
        '*/cli/api/dns' => Http::response(dnsZones()),
        '*/cli/api/dns/z1' => Http::response(dnsZone([dnsRecord(['id' => 3, 'type' => 'txt', 'value' => 'v=spf1 -all', 'ttl' => 3600])])),
        '*/cli/api/dns/z1/records/3' => Http::response(['data' => dnsRecord(['id' => 3, 'type' => 'txt', 'value' => 'v=spf1 include:lettermint.co -all', 'ttl' => 3600])]),
    ]);

    $this->artisan('dns:update', ['zone' => 'example.eu', 'id' => '3', '--value' => 'v=spf1 include:lettermint.co -all'])
        ->expectsOutputToContain('TXT example.eu → v=spf1 include:lettermint.co -all')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->data() === ['type' => 'txt', 'name' => '@', 'value' => 'v=spf1 include:lettermint.co -all', 'ttl' => 3600]);
});

test('dns:update refuses a record brewless does not set, before asking the server', function (): void {
    Http::fake([
        '*/cli/api/dns' => Http::response(dnsZones()),
        '*/cli/api/dns/z1' => Http::response(dnsZone([dnsRecord(['id' => 2, 'type' => 'pull_zone', 'name' => 'shop', 'linked_to' => '4242', 'editable' => false])])),
    ]);

    $this->artisan('dns:update', ['zone' => 'example.eu', 'id' => '2', '--value' => 'other'])
        ->expectsOutputToContain('PULL ZONE records are changed at bunny.net')
        ->assertFailed();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

test('dns:remove asks first, and removes only after a yes', function (): void {
    Http::fake([
        '*/cli/api/dns' => Http::response(dnsZones()),
        '*/cli/api/dns/z1' => Http::response(dnsZone([dnsRecord(['id' => 5, 'name' => 'www'])])),
        '*/cli/api/dns/z1/records/5' => Http::response('', 204),
    ]);

    $this->artisan('dns:remove', ['zone' => 'example.eu', 'id' => '5'])
        ->expectsConfirmation('Remove A www.example.eu → 203.0.113.7?', 'no')
        ->expectsOutputToContain('Nothing was removed.')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');

    $this->artisan('dns:remove', ['zone' => 'example.eu', 'id' => '5', '--force' => true])
        ->expectsOutputToContain('Removed A www.example.eu → 203.0.113.7.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns/z1/records/5'));
});

test('an unknown record id says where the ids are', function (): void {
    Http::fake([
        '*/cli/api/dns' => Http::response(dnsZones()),
        '*/cli/api/dns/z1' => Http::response(dnsZone([])),
    ]);

    $this->artisan('dns:remove', ['zone' => 'example.eu', 'id' => '99', '--force' => true])
        ->expectsOutputToContain('The zone has no record with id 99. Run brewless dns:list to see the ids.')
        ->assertFailed();
});
