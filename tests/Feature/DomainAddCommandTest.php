<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function domainOperation(string $status, array $steps, ?string $error = null): array
{
    return ['data' => ['id' => 'op1', 'status' => $status, 'error_message' => $error, 'steps' => $steps]];
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
