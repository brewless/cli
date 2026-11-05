<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Replacing the edge secret as Brewless reports it: every step done, and the
 * version of the secret the environment is on now.
 *
 * @return array<string, mixed>
 */
function rotationOperation(string $status = 'succeeded'): array
{
    $steps = array_map(
        static fn (string $name): array => ['name' => $name, 'status' => $status === 'succeeded' ? 'succeeded' : 'pending'],
        ['support', 'mint', 'both', 'edge', 'only', 'retire'],
    );

    return [
        'id' => 'op1',
        'status' => $status,
        'steps' => $steps,
        'result' => $status === 'succeeded' ? ['revision' => 2, 'retired' => 1] : [],
        'error_message' => $status === 'failed'
            ? 'The application cannot hold two edge secrets at once, so replacing the secret would leave it answering as the wrong host from the moment the edge moved. Update brewless/laravel to 1.2 or later, deploy, and try again.'
            : null,
    ];
}

beforeEach(function (): void {
    Sleep::fake();
});

test('edge:rotate-secret says what it does, asks, and names every step it took', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/edge/secret' => Http::response(['data' => rotationOperation()], 201),
    ]);

    $this->artisan('edge:rotate-secret', ['environment' => 'production'])
        ->expectsOutputToContain('two sides that cannot change at the same moment')
        ->expectsOutputToContain('two releases')
        ->expectsConfirmation('Replace the edge secret of production?', 'yes')
        ->expectsOutputToContain('New secret made in your Secret Manager')
        ->expectsOutputToContain('Containers hold the new secret and the one before it')
        ->expectsOutputToContain('The edge sends the new secret')
        ->expectsOutputToContain('version 2 in your Secret Manager')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env1/edge/secret') && strlen($request['key']) >= 8);
});

test('saying no replaces nothing', function (): void {
    signedIn();
    project();

    Http::fake(['*/cli/api/applications' => Http::response(applications())]);

    $this->artisan('edge:rotate-secret', ['environment' => 'production'])
        ->expectsConfirmation('Replace the edge secret of production?', 'no')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/edge/secret'));
});

test('a replacement that is refused says why and fails', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/edge/secret' => Http::response(['data' => rotationOperation('failed')], 201),
    ]);

    $this->artisan('edge:rotate-secret', ['environment' => 'production', '--force' => true])
        ->expectsOutputToContain('cannot hold two edge secrets')
        ->assertFailed();
});
