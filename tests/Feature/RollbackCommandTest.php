<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Releases 3 (live), 2 and 1 (both put-back-able), newest first.
 *
 * @return array<string, mixed>
 */
function history(bool $migrationsSince = false): array
{
    return ['data' => [
        release(['id' => 'r3', 'number' => 3, 'status' => 'active']),
        release(['id' => 'r2', 'number' => 2, 'status' => 'superseded', 'can_roll_back' => true, 'commit' => str_repeat('b', 40), 'migrations_since' => $migrationsSince]),
        release(['id' => 'r1', 'number' => 1, 'status' => 'superseded', 'can_roll_back' => true, 'commit' => str_repeat('c', 40)]),
    ]];
}

test('rollback goes to the release before the live one, after asking', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/releases' => Http::response(history(migrationsSince: true)),
        '*/releases/r2/rollback' => Http::response(['data' => release(['id' => 'r4', 'number' => 4, 'status' => 'active', 'source' => 'rollback'])], 201),
        '*/addresses' => Http::response(['data' => [['url' => 'https://shop.example']]]),
    ]);

    $this->artisan('rollback', ['environment' => 'production'])
        ->expectsOutputToContain('back to release 2 (commit bbbbbbbbbbbb)')
        ->expectsOutputToContain('nothing is migrated back')
        ->expectsOutputToContain('changed the database')
        ->expectsConfirmation('Put release 2 live again?', 'yes')
        ->expectsOutputToContain('Release 4 is live.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/releases/r2/rollback') && strlen($request['key']) >= 8);
});

test('saying no rolls nothing back', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/releases' => Http::response(history()),
    ]);

    $this->artisan('rollback', ['environment' => 'production'])
        ->expectsConfirmation('Put release 2 live again?', 'no')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/rollback'));
});

test('a release can be named, and one that cannot be put back is refused before anything is sent', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/releases' => Http::response(history()),
        '*/releases/r1/rollback' => Http::response(['data' => release(['id' => 'r4', 'number' => 4, 'status' => 'active'])], 201),
        '*/addresses' => Http::response(['data' => []]),
    ]);

    $this->artisan('rollback', ['environment' => 'production', '--release' => '1', '--force' => true])
        ->expectsOutputToContain('back to release 1')
        ->assertSuccessful();

    $this->artisan('rollback', ['environment' => 'production', '--release' => '3', '--force' => true])
        ->expectsOutputToContain('Release 3 cannot be put back: it is live now, or it never was.')
        ->assertFailed();

    $this->artisan('rollback', ['environment' => 'production', '--release' => '9', '--force' => true])
        ->expectsOutputToContain('has no release 9')
        ->assertFailed();
});

test('without an earlier release there is nothing to go back to', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/releases' => Http::response(['data' => [release(['status' => 'active'])]]),
    ]);

    $this->artisan('rollback', ['environment' => 'production'])
        ->expectsOutputToContain('There is no earlier release to go back to.')
        ->assertFailed();
});

test('releases lists what was deployed', function (): void {
    signedIn();
    project();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/releases' => Http::response(history()),
    ]);

    $this->artisan('releases', ['environment' => 'production'])
        ->expectsOutputToContain('bbbbbbbbbbbb')
        ->assertSuccessful();
});
