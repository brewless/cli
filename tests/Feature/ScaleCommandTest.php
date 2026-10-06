<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    signedIn();
    project();
});

test('scale sets the bounds and waits until the provider has them', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/scale' => Http::response(['data' => ['id' => 'op1', 'status' => 'queued']], 201),
        '*/cli/api/operations/op1' => Http::sequence()
            ->push(['data' => ['id' => 'op1', 'status' => 'running']])
            ->push(['data' => ['id' => 'op1', 'status' => 'succeeded']]),
    ]);

    $this->artisan('scale', ['environment' => 'production', '--min' => '1', '--max' => '20'])
        ->expectsOutputToContain('production now scales between 1 and 20 replicas.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env1/scale') && $request['min'] === 1 && $request['max'] === 20 && strlen($request['key']) >= 8);
});

test('scale wants both bounds, and passes on what the server refuses', function (): void {
    $this->artisan('scale', ['environment' => 'production', '--max' => '20'])
        ->expectsOutputToContain('Give both bounds: brewless scale production --min=1 --max=20')
        ->assertFailed();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/scale' => Http::response(['errors' => ['min' => ['Give a minimum of 0 to 50 and a maximum of 1 to 50, with the minimum not above the maximum.']]], 422),
    ]);

    $this->artisan('scale', ['environment' => 'production', '--min' => '9', '--max' => '2'])
        ->expectsOutputToContain('with the minimum not above the maximum')
        ->assertFailed();
});
