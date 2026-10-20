<?php

declare(strict_types=1);

use App\Support\Project;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

const SERVICE_ID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

/**
 * A project whose brewless.yml names the gliner service in production, with its Dockerfile.
 */
function projectWithService(): void
{
    (new Project('acme', 'shop', 'laravel'))->write((string) getcwd(), ['environments' => ['production' => ['services' => ['gliner' => ['build' => 'services/gliner', 'memory' => 4096]]]]]);
    mkdir(getcwd().'/services/gliner', 0700, true);
    file_put_contents(getcwd().'/services/gliner/Dockerfile', "FROM python:3.11-slim\n");
}

/**
 * Docker on this machine, as far as a push asks it anything.
 */
function docker(bool $running = true): void
{
    Process::fake([
        'docker version *' => $running ? Process::result('29.0.0') : Process::result(errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1),
        'docker build *' => Process::result('#1 DONE'),
        'docker image inspect *' => Process::result('sha256:'.SERVICE_ID),
        'set -o pipefail; docker save *' => function ($process) {
            preg_match("/> '([^']+)'$/", $process->command, $matches);
            file_put_contents($matches[1], 'the image');

            return Process::result('');
        },
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function serviceSteps(string ...$succeeded): array
{
    return array_map(
        static fn (string $name): array => ['name' => $name, 'status' => in_array($name, $succeeded, true) ? 'succeeded' : 'pending'],
        ['source', 'push', 'image', 'container', 'record'],
    );
}

test('a service is built with Docker here, uploaded to the customer project and put live', function (): void {
    signedIn();
    projectWithService();
    docker();

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/services/gliner/upload' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/services/shop/production/gliner/a1b2c3d4e5f60718.tar.gz?X-Amz-Signature=abc', 'method' => 'PUT', 'expires_in' => 3600]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => function (Request $request) use (&$upload) {
            $upload = $request->body();

            return Http::response('', 200);
        },
        'https://acme.brewless.eu/cli/api/environments/env1/services/gliner' => Http::response(['data' => ['id' => 'op1', 'status' => 'queued', 'steps' => serviceSteps()]], 201),
        'https://acme.brewless.eu/cli/api/operations/op1' => Http::sequence()
            ->push(['data' => ['id' => 'op1', 'status' => 'running', 'steps' => serviceSteps('source', 'push')]])
            ->push(['data' => ['id' => 'op1', 'status' => 'succeeded', 'steps' => serviceSteps('source', 'push', 'image', 'container', 'record')]]),
    ]);

    $this->artisan('service:push', ['environment' => 'production', 'service' => 'gliner'])
        ->expectsOutputToContain('Building gliner from services/gliner for linux/amd64')
        ->expectsOutputToContain('Image a1b2c3d4e5f60718 built')
        ->expectsOutputToContain('Service container live')
        ->expectsOutputToContain('The next deploy gives the application GLINER_URL and GLINER_TOKEN.')
        ->assertSuccessful();

    expect($upload)->toBe('the image');

    Process::assertRan(static fn ($process): bool => str_starts_with($process->command, 'docker build --platform linux/amd64 ') && str_contains($process->command, "'brewless-shop-gliner:local'"));

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://acme.brewless.eu/cli/api/environments/env1/services/gliner'
        && $request['tag'] === 'a1b2c3d4e5f60718'
        && $request['manifest']['environments']['production']['services']['gliner']['memory'] === 4096
        && str_starts_with((string) $request['key'], 'cli-'));

    // The saved image is gone from this machine once it is uploaded.
    expect(glob(sys_get_temp_dir().'/brewless-service-*.tar.gz'))->toBe([]);
});

test('a failed push says why, and the command fails', function (): void {
    signedIn();
    projectWithService();
    docker();

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/services/gliner/upload' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/x?X-Amz-Signature=abc']]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 200),
        'https://acme.brewless.eu/cli/api/environments/env1/services/gliner' => Http::response(['data' => ['id' => 'op1', 'status' => 'failed', 'error_message' => 'The image could not be put in your registry.', 'steps' => serviceSteps('source')]], 201),
    ]);

    $this->artisan('service:push', ['environment' => 'production', 'service' => 'gliner'])
        ->expectsOutputToContain('The image could not be put in your registry.')
        ->assertFailed();
});

test('a service is only built when brewless.yml names it with a Dockerfile, and Docker answers', function (): void {
    signedIn();
    projectWithService();

    docker();
    $this->artisan('service:push', ['environment' => 'production', 'service' => 'model'])
        ->expectsOutputToContain('brewless.yml names no model service with a build directory under environments.production.services.')
        ->assertFailed();

    unlink(getcwd().'/services/gliner/Dockerfile');
    $this->artisan('service:push', ['environment' => 'production', 'service' => 'gliner'])
        ->expectsOutputToContain('There is no Dockerfile in services/gliner')
        ->assertFailed();

    file_put_contents(getcwd().'/services/gliner/Dockerfile', "FROM python:3.11-slim\n");
    docker(running: false);
    $this->artisan('service:push', ['environment' => 'production', 'service' => 'gliner'])
        ->expectsOutputToContain('Docker does not answer')
        ->assertFailed();

    Http::assertNothingSent();
});
