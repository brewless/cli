<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    signedIn();
    project();
});

test('command runs a line in an environment, prints what came back and ends with its exit code', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env2/commands' => Http::response(['data' => ['exit_code' => 3, 'output' => "Migration name ... Batch\n", 'seconds' => 0.4]]),
    ]);

    $this->artisan('command', ['environment' => 'staging', 'line' => 'migrate:status --pending'])
        ->expectsOutputToContain('Migration name ... Batch')
        ->assertExitCode(3);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env2/commands') && $request['command'] === 'migrate:status --pending');
});

test('on production a command asks first, unless forced', function (): void {
    $withKind = applications();
    $withKind['data'][0]['environments'][0]['kind'] = 'production';

    Http::fake([
        '*/cli/api/applications' => Http::response($withKind),
        '*/environments/env1/commands' => Http::response(['data' => ['exit_code' => 0, 'output' => 'done', 'seconds' => 0.1]]),
    ]);

    $this->artisan('command', ['environment' => 'production', 'line' => 'cache:clear'])
        ->expectsConfirmation('Run "cache:clear" on production?', 'no')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/commands'));

    $this->artisan('command', ['environment' => 'production', 'line' => 'cache:clear', '--force' => true])
        ->expectsOutputToContain('done')
        ->assertSuccessful();
});

test('a command the server refuses is said in its words', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env2/commands' => Http::response(['errors' => ['command' => ['That command never ends by itself (a worker, a server, a shell), so it is not run here.']]], 422),
    ]);

    $this->artisan('command', ['environment' => 'staging', 'line' => 'tinker'])
        ->expectsOutputToContain('never ends by itself')
        ->assertFailed();
});

test('logs prints the lines, and following asks only for what came after the last one', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env2/logs*' => Http::sequence()
            ->push(['data' => ['unavailable' => null, 'lines' => [
                ['id' => '1791300000000000001', 'at' => '2026-10-06T15:00:00Z', 'message' => 'GET /up 200'],
                ['id' => '1791300000000000002', 'at' => '2026-10-06T15:00:01Z', 'message' => 'tick'],
            ]]])
            ->push(['data' => ['unavailable' => null, 'lines' => [
                ['id' => '1791300000000000003', 'at' => '2026-10-06T15:00:04Z', 'message' => 'queue empty'],
            ]]]),
    ]);

    config(['brewless.tail_rounds' => 2]);

    $this->artisan('logs', ['environment' => 'staging', '--tail' => true, '--search' => 'up'])
        ->expectsOutputToContain('GET /up 200')
        ->expectsOutputToContain('queue empty')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/logs') && ($request['after'] ?? null) === '1791300000000000002' && $request['search'] === 'up');
});

test('logs that cannot be read say why', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env2/logs*' => Http::response(['data' => ['unavailable' => 'not_connected', 'lines' => []]]),
    ]);

    $this->artisan('logs', ['environment' => 'staging'])
        ->expectsOutputToContain('The logs cannot be read right now (not_connected).')
        ->assertFailed();
});
