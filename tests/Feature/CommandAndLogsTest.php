<?php

declare(strict_types=1);

use App\Commands\TinkerCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;

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

test('logs of another container are read by its name', function (): void {
    $sources = [['id' => 'res-web', 'name' => 'web', 'kind' => 'container'], ['id' => 'res-release', 'name' => 'release', 'kind' => 'container'], ['id' => 'res-worker', 'name' => 'worker', 'kind' => 'container']];

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env2/logs*' => fn (Request $request) => Http::response(['data' => ['sources' => $sources, 'unavailable' => null, 'lines' => [
            ['id' => '1791300000000000001', 'at' => '2026-10-06T15:00:00Z', 'message' => ($request['source'] ?? null) === 'res-release' ? 'SQLSTATE[08006] fe_sendauth: no password supplied' : 'GET /up 200'],
        ]]]),
    ]);

    $this->artisan('logs', ['environment' => 'staging', '--source' => 'release'])
        ->expectsOutputToContain('fe_sendauth: no password supplied')
        ->doesntExpectOutputToContain('GET /up 200')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/logs') && ($request['source'] ?? null) === 'res-release');
});

test('without a source the web container is read, and nothing else is asked for', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env2/logs*' => Http::response(['data' => ['sources' => [], 'unavailable' => null, 'lines' => []]]),
    ]);

    $this->artisan('logs', ['environment' => 'staging'])->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => isset($request['source']));
});

test('a source the environment does not have names the ones it has', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env2/logs*' => Http::response(['data' => ['sources' => [['id' => 'res-web', 'name' => 'web', 'kind' => 'container'], ['id' => 'res-release', 'name' => 'release', 'kind' => 'container']], 'unavailable' => null, 'lines' => []]]),
    ]);

    $this->artisan('logs', ['environment' => 'staging', '--source' => 'worker'])
        ->expectsOutputToContain('The environment has no logs named "worker". It has: web, release.')
        ->assertFailed();
});

test('tinker runs a line of PHP in an environment, quoted so it arrives as typed', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env2/commands' => Http::response(['data' => ['exit_code' => 0, 'output' => "= 42\n", 'seconds' => 0.3]]),
    ]);

    $this->artisan('tinker', ['environment' => 'staging', 'code' => 'App\Models\User::where("name", \'Ada\')->count()'])
        ->expectsOutputToContain('= 42')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env2/commands')
        && $request['command'] === 'tinker --execute="App\\\\Models\\\\User::where(\"name\", \'Ada\')->count()"');
});

test('the quoted tinker line reads back as the code that was typed', function (string $code): void {
    $input = new StringInput(TinkerCommand::consoleLine($code));
    $input->bind(new InputDefinition([
        new InputArgument('command'),
        new InputOption('execute', null, InputOption::VALUE_OPTIONAL),
    ]));

    expect($input->getOption('execute'))->toBe($code);
})->with([
    'App\Models\User::count()',
    '$u = App\Models\User::first(); echo $u?->email;',
    'echo "a \"quoted\" word", \'single\';',
    'echo "\\\\n stays two characters";',
]);

test('on production tinker asks first and shows the code, and a throw ends with exit code 1', function (): void {
    $withKind = applications();
    $withKind['data'][0]['environments'][0]['kind'] = 'production';

    Http::fake([
        '*/cli/api/applications' => Http::response($withKind),
        '*/environments/env1/commands' => Http::response(['data' => ['exit_code' => 1, 'output' => "RuntimeException  out of coffee.\n", 'seconds' => 0.2]]),
    ]);

    $this->artisan('tinker', ['environment' => 'production', 'code' => 'cache()->flush()'])
        ->expectsConfirmation('Run `cache()->flush()` on production?', 'no')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/commands'));

    $this->artisan('tinker', ['environment' => 'production', 'code' => 'throw new RuntimeException("out of coffee")', '--force' => true])
        ->expectsOutputToContain('out of coffee')
        ->assertExitCode(1);
});
