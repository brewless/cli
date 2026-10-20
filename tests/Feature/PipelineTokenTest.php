<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('with BREWLESS_API_TOKEN a pipeline reaches Brewless without brewless login', function (): void {
    config(['brewless.token' => ' bl_pipeline ']);
    project();

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/releases' => Http::response(['data' => [release(['status' => 'active'])]]),
    ]);

    $this->artisan('releases', ['environment' => 'production'])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer bl_pipeline'));
});

test('BREWLESS_API_TOKEN goes before the sign-in of the machine', function (): void {
    signedIn();
    config(['brewless.token' => 'bl_pipeline']);
    project();

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/releases' => Http::response(['data' => []]),
    ]);

    $this->artisan('releases', ['environment' => 'production'])->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer bl_test-token'));
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer bl_pipeline'));
});

test('a refused pipeline token is named as such, not answered with brewless login', function (): void {
    config(['brewless.token' => 'bl_revoked']);
    project();

    Http::fake(['https://acme.brewless.eu/cli/api/applications' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('releases', ['environment' => 'production'])
        ->expectsOutputToContain('Brewless refused the token in BREWLESS_API_TOKEN for acme')
        ->doesntExpectOutputToContain('brewless login')
        ->assertFailed();
});

test('without a token or a sign-in the client says how to sign in', function (): void {
    config(['brewless.token' => '']);
    project();

    $this->artisan('releases', ['environment' => 'production'])
        ->expectsOutputToContain('You are not signed in to acme. Run: brewless login acme')
        ->assertFailed();
});
