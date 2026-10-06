<?php

declare(strict_types=1);

use App\Support\Credentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('login shows the code, waits for approval and keeps the token', function (): void {
    Http::fake([
        'https://acme.brewless.eu/cli/auth/device' => Http::response([
            'device_code' => str_repeat('d', 64),
            'user_code' => 'ABCD-2345',
            'verification_url' => 'https://acme.brewless.eu/device?code=ABCD-2345',
            'expires_in' => 600,
            'interval' => 3,
        ], 201),
        'https://acme.brewless.eu/cli/auth/token' => Http::sequence()
            ->push(['error' => 'authorization_pending', 'message' => 'Nobody approved this sign-in yet.'], 400)
            ->push([
                'token' => 'bl_fresh',
                'expires_at' => '2027-01-04T12:00:00Z',
                'user' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.test'],
                'organisation' => ['id' => 'acme', 'name' => 'Acme Coffee'],
            ]),
    ]);

    $this->artisan('login', ['organisation' => 'Acme', '--no-browser' => true])
        ->expectsOutputToContain('https://acme.brewless.eu/device?code=ABCD-2345')
        ->expectsOutputToContain('Signed in to Acme Coffee as Ada Lovelace.')
        ->assertSuccessful();

    expect(resolve(Credentials::class)->for('brewless.eu', 'acme'))->toBe(['token' => 'bl_fresh', 'user' => 'ada@example.test', 'expires_at' => '2027-01-04T12:00:00Z']);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/auth/device') && str_starts_with($request['machine'], 'CLI on '));
    Http::assertSentCount(3);
});

test('a sign-in that expired or was used stops the waiting', function (): void {
    Http::fake([
        '*/cli/auth/device' => Http::response(['device_code' => str_repeat('d', 64), 'user_code' => 'ABCD-2345', 'verification_url' => 'https://acme.brewless.eu/device', 'expires_in' => 600, 'interval' => 3], 201),
        '*/cli/auth/token' => Http::response(['error' => 'expired_token', 'message' => 'This sign-in is no longer open. Run "brewless login" again.'], 400),
    ]);

    $this->artisan('login', ['organisation' => 'acme', '--no-browser' => true])
        ->expectsOutputToContain('This sign-in is no longer open.')
        ->assertFailed();

    expect(resolve(Credentials::class)->for('brewless.eu', 'acme'))->toBeNull();
});

test('an organisation that cannot be reached is said plainly', function (): void {
    Http::fake(fn () => throw new ConnectionException('no route'));

    $this->artisan('login', ['organisation' => 'nowhere', '--no-browser' => true])
        ->expectsOutputToContain('Could not reach nowhere.brewless.eu')
        ->assertFailed();
});

test('logout forgets the sign-in on this machine and says where to end it for good', function (): void {
    signedIn('acme');

    $this->artisan('logout', ['organisation' => 'acme'])
        ->expectsOutputToContain('Signed out of acme on this machine.')
        ->assertSuccessful();

    expect(resolve(Credentials::class)->for('brewless.eu', 'acme'))->toBeNull();
});
