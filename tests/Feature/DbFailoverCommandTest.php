<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * The switch as Brewless reports it: every step done, and the release it started.
 *
 * @return array<string, mixed>
 */
function failoverOperation(string $status = 'succeeded'): array
{
    $steps = array_map(static fn (string $name): array => ['name' => $name, 'status' => $status === 'succeeded' ? 'succeeded' : 'pending'], ['promote', 'address', 'variables', 'records', 'release']);

    return ['id' => 'op1', 'status' => $status, 'steps' => $steps, 'result' => $status === 'succeeded' ? ['release_id' => 'r4'] : [], 'error_message' => $status === 'failed' ? 'The standby copy of the database this needs is not at the provider any more.' : null];
}

beforeEach(function (): void {
    Sleep::fake();
});

test('db:failover says what it does, asks, switches and follows the release with the new variables', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/database/failover' => Http::response(['data' => failoverOperation()], 201),
        '*/releases/r4' => Http::response(['data' => release(['id' => 'r4', 'number' => 4, 'status' => 'active', 'source' => 'restart'])]),
        '*/addresses' => Http::response(['data' => [['url' => 'https://shop.example']]]),
    ]);

    $this->artisan('db:failover', ['environment' => 'production'])
        ->expectsOutputToContain('a few seconds behind')
        ->expectsOutputToContain('keeps running, and costing, at Scaleway')
        ->expectsConfirmation('Switch production to the standby database?', 'yes')
        ->expectsOutputToContain('Standby copy made a database of its own')
        ->expectsOutputToContain('DB_HOST and DB_PORT point at it')
        ->expectsOutputToContain('Release 4 is live.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env1/database/failover') && strlen($request['key']) >= 8);
});

test('saying no switches nothing', function (): void {
    signedIn();
    project();

    Http::fake(['*/cli/api/applications' => Http::response(applications())]);

    $this->artisan('db:failover', ['environment' => 'production'])
        ->expectsConfirmation('Switch production to the standby database?', 'no')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/failover'));
});

test('a switch that fails says why and fails', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/database/failover' => Http::response(['data' => failoverOperation('failed')], 201),
    ]);

    $this->artisan('db:failover', ['environment' => 'production', '--force' => true])
        ->expectsOutputToContain('is not at the provider any more')
        ->assertFailed();
});

test('without a standby copy Brewless refuses, and the reason is shown', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/database/failover' => Http::response(['message' => 'This environment has no standby copy of its database.', 'errors' => ['environment' => ['This environment has no standby copy of its database.']]], 422),
    ]);

    $this->artisan('db:failover', ['environment' => 'production', '--force' => true])
        ->expectsOutputToContain('no standby copy')
        ->assertFailed();
});
