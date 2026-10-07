<?php

declare(strict_types=1);

use App\Support\Credentials;
use App\Support\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/*
| Every test gets its own place for sign-ins and its own project directory,
| never talks to a real server and never really waits.
*/
uses()->beforeEach(function (): void {
    $this->home = sys_get_temp_dir().'/brewless-cli-test-'.bin2hex(random_bytes(6));
    $this->project = $this->home.'/project';
    mkdir($this->project, 0700, true);

    config(['brewless.home' => $this->home, 'brewless.host' => 'brewless.eu', 'brewless.scheme' => 'https']);

    $this->previousDirectory = getcwd();
    chdir($this->project);

    // Questions are answered by the test: a prompt falls back to one that can be expected.
    app()->instance('env', 'testing');

    Http::preventStrayRequests();
    Sleep::fake();
})->afterEach(function (): void {
    chdir($this->previousDirectory);

    exec('rm -rf '.escapeshellarg($this->home));
})->in('Feature');

/**
 * Sign this machine in to an organisation, as `brewless login` leaves it.
 */
function signedIn(string $organisation = 'acme'): void
{
    resolve(Credentials::class)->store('brewless.eu', $organisation, 'bl_test-token', 'ada@example.test', null);
}

/**
 * Put a brewless.yml in the project the test runs in.
 */
function project(string $organisation = 'acme', string $application = 'shop'): void
{
    (new Project($organisation, $application, 'laravel'))->write((string) getcwd());
}

/**
 * The applications answer of an organisation with one application and its environments.
 *
 * @return array<string, mixed>
 */
function applications(): array
{
    return ['data' => [[
        'id' => 'app1',
        'name' => 'Shop',
        'slug' => 'shop',
        'framework' => 'laravel',
        'environments' => [
            ['id' => 'env1', 'slug' => 'production', 'name' => 'Production'],
            ['id' => 'env2', 'slug' => 'staging', 'name' => 'Staging'],
        ],
    ]]];
}

/**
 * A release as the api returns it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function release(array $overrides = []): array
{
    return [
        'id' => 'r2',
        'number' => 2,
        'status' => 'queued',
        'source' => 'build',
        'commit' => str_repeat('a', 40),
        'actor_name' => 'Ada',
        'can_roll_back' => false,
        'migrations_since' => false,
        'migration_output' => null,
        'created_at' => '2026-10-06T14:00:00Z',
        'operation' => ['error_message' => null, 'steps' => []],
        ...$overrides,
    ];
}
