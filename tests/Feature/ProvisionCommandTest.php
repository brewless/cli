<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    signedIn();
    project();
});

test('provision sets the environment up, prints each step and says what is left to do', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/provision' => Http::response(['data' => ['id' => 'op1', 'status' => 'queued', 'steps' => [['name' => 'registry', 'status' => 'pending']]]], 201),
        '*/cli/api/operations/op1' => Http::sequence()
            ->push(['data' => ['id' => 'op1', 'status' => 'running', 'steps' => [['name' => 'registry', 'status' => 'succeeded'], ['name' => 'namespace', 'status' => 'running']]]])
            ->push(['data' => [
                'id' => 'op1',
                'status' => 'succeeded',
                'steps' => [['name' => 'registry', 'status' => 'succeeded'], ['name' => 'namespace', 'status' => 'succeeded']],
                'result' => ['address' => 'https://acme-shop-production.b-cdn.net', 'variables_to_set' => ['DB_USERNAME', 'DB_PASSWORD']],
            ]]),
    ]);

    $this->artisan('provision', ['environment' => 'production'])
        ->expectsOutputToContain('registry')
        ->expectsOutputToContain('namespace')
        ->expectsOutputToContain('After your first deploy it answers on https://acme-shop-production.b-cdn.net.')
        ->expectsOutputToContain('DB_USERNAME, DB_PASSWORD')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env1/provision') && $request['database'] === true && strlen($request['key']) >= 8);
});

test('provision can leave the database out, and passes on what stops it', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/provision' => Http::response(['data' => ['id' => 'op1', 'status' => 'failed', 'steps' => [], 'error_message' => 'Scaleway is not connected any more.']], 201),
    ]);

    $this->artisan('provision', ['environment' => 'production', '--no-database' => true])
        ->expectsOutputToContain('Scaleway is not connected any more.')
        ->assertFailed();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/provision') && $request['database'] === false);
});

test('an environment that has containers already is refused by the server', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/provision' => Http::response(['errors' => ['environment' => ['This environment has containers already. Nothing was made.']]], 422),
    ]);

    $this->artisan('provision', ['environment' => 'production'])
        ->expectsOutputToContain('This environment has containers already.')
        ->assertFailed();
});
