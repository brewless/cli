<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function domainOperation(string $status, array $steps, ?string $error = null, array $result = []): array
{
    return ['data' => ['id' => 'op1', 'status' => $status, 'error_message' => $error, 'steps' => $steps, 'result' => $result]];
}

/**
 * A domain that was added, with what Brewless wants the customer to know.
 */
function addedWithNotices(): array
{
    $done = [['name' => 'attach_domain', 'status' => 'succeeded'], ['name' => 'dns', 'status' => 'succeeded'], ['name' => 'certificate', 'status' => 'succeeded']];

    return [
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/domains' => Http::sequence()
            ->push(['data' => ['route' => 'bunny', 'target' => 'shop.b-cdn.net', 'domains' => []]])
            ->push(domainOperation('succeeded', $done, null, [
                'zone' => 'example.eu',
                'zone_taken_over' => true,
                'address' => ['variable' => 'APP_URL', 'current' => 'https://shop.b-cdn.net', 'suggested' => 'https://example.eu'],
                'notices' => ['Brewless now manages the DNS zone example.eu at Bunny DNS for this environment.', 'APP_URL is still https://shop.b-cdn.net, the provider\'s own address.'],
            ]), 201),
        '*/environments/env1/secrets/APP_URL' => Http::response(['data' => ['revision' => 3]]),
    ];
}

beforeEach(function (): void {
    signedIn();
    project();
});

test('domain:add follows the operation, names the record when it has to wait, and ends with the address', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/domains' => Http::sequence()
            ->push(['data' => ['route' => 'bunny', 'target' => 'shop.b-cdn.net', 'domains' => []]])
            ->push(domainOperation('queued', [['name' => 'attach_domain', 'status' => 'pending'], ['name' => 'dns', 'status' => 'pending'], ['name' => 'certificate', 'status' => 'pending']]), 201),
        '*/cli/api/operations/op1' => Http::sequence()
            ->push(domainOperation('queued', [['name' => 'attach_domain', 'status' => 'succeeded'], ['name' => 'dns', 'status' => 'succeeded'], ['name' => 'certificate', 'status' => 'pending', 'error_message' => 'Waiting for the certificate of docs.example.eu.']]))
            ->push(domainOperation('succeeded', [['name' => 'attach_domain', 'status' => 'succeeded'], ['name' => 'dns', 'status' => 'succeeded'], ['name' => 'certificate', 'status' => 'succeeded']])),
    ]);

    $this->artisan('domain:add', ['environment' => 'production', 'hostname' => 'Docs.Example.eu'])
        ->expectsOutputToContain('Domain added at your provider')
        ->expectsOutputToContain('Waiting for the certificate of docs.example.eu.')
        ->expectsOutputToContain('CNAME docs.example.eu -> shop.b-cdn.net')
        ->expectsOutputToContain('Certificate issued')
        ->expectsOutputToContain('https://docs.example.eu is live on production.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/environments/env1/domains') && $request['hostname'] === 'docs.example.eu' && strlen($request['key']) >= 8);
});

test('a domain that cannot be added says why and fails', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/domains' => Http::sequence()
            ->push(['data' => ['route' => 'bunny', 'target' => 'shop.b-cdn.net', 'domains' => []]])
            ->push(domainOperation('failed', [['name' => 'attach_domain', 'status' => 'succeeded'], ['name' => 'dns', 'status' => 'failed']], 'docs.example.eu already has an address record in your zone at Bunny DNS that leads somewhere else.'), 201),
    ]);

    $this->artisan('domain:add', ['environment' => 'production', 'hostname' => 'docs.example.eu'])
        ->expectsOutputToContain('already has an address record')
        ->assertFailed();
});

test('domain:add tells what Brewless took over and offers to set the address, which then takes a deploy', function (): void {
    Http::fake(addedWithNotices());

    $this->artisan('domain:add', ['environment' => 'production', 'hostname' => 'example.eu'])
        ->expectsOutputToContain('Brewless now manages the DNS zone example.eu')
        ->expectsOutputToContain('APP_URL is still https://shop.b-cdn.net')
        ->expectsConfirmation('Set APP_URL to https://example.eu now? It takes effect with the next deploy.', 'yes')
        ->expectsOutputToContain('APP_URL is https://example.eu now. Run brewless deploy production to put it live.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/environments/env1/secrets/APP_URL') && $request['value'] === 'https://example.eu');
});

test('domain:add never sets the address without a yes', function (): void {
    Http::fake(addedWithNotices());

    $this->artisan('domain:add', ['environment' => 'production', 'hostname' => 'example.eu'])
        ->expectsConfirmation('Set APP_URL to https://example.eu now? It takes effect with the next deploy.', 'no')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

test('domain:add without a terminal to ask in only says what to set', function (): void {
    Http::fake(addedWithNotices());

    $this->artisan('domain:add', ['environment' => 'production', 'hostname' => 'example.eu', '--no-interaction' => true])
        ->expectsOutputToContain('APP_URL is still https://shop.b-cdn.net')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

test('with the dns kept at a provider of your own, domain:add ends with the record to make, and does not wait for it', function (): void {
    $done = [['name' => 'attach_domain', 'status' => 'succeeded'], ['name' => 'dns', 'status' => 'succeeded'], ['name' => 'certificate', 'status' => 'succeeded']];

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/domains' => Http::sequence()
            ->push(['data' => ['route' => 'bunny', 'target' => 'shop.b-cdn.net', 'domains' => []]])
            ->push(domainOperation('succeeded', $done, null, [
                'awaiting_dns' => true,
                'apex' => true,
                'found' => '203.0.113.7',
                'record' => ['type' => 'ALIAS', 'name' => 'example.eu', 'value' => 'shop.b-cdn.net'],
                'notices' => [],
            ]), 201),
    ]);

    $this->artisan('domain:add', ['environment' => 'production', 'hostname' => 'example.eu'])
        ->expectsOutputToContain('kept where Brewless does not reach')
        ->expectsOutputToContain('ALIAS  example.eu  ->  shop.b-cdn.net')
        ->expectsOutputToContain('where a CNAME may not stand')
        ->expectsOutputToContain('Found there now: 203.0.113.7')
        ->expectsOutputToContain('brewless domain:check production example.eu')
        ->doesntExpectOutputToContain('is live on production')
        ->assertSuccessful();
});
