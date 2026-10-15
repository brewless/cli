<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

const COMMIT = '1a2b3c4d5e6f7a8b9c0d1a2b3c4d5e6f7a8b9c0d';

/**
 * A repository at one commit, with nothing uncommitted unless said otherwise.
 */
function repository(string $status = ''): void
{
    Process::fake([
        'git rev-parse HEAD' => Process::result(COMMIT),
        'git log -1 --format=%ct HEAD' => Process::result('1791200000'),
        'git status --porcelain --untracked-files=no' => Process::result($status),
        'git archive *' => function ($process) {
            preg_match("/-o '([^']+)'/", $process->command, $matches);
            file_put_contents($matches[1], 'the source');

            return Process::result('');
        },
    ]);
}

/**
 * What applying brewless.yml answers: by default an environment that runs and has all the file asks.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function applied(array $overrides = []): array
{
    return [...['environment' => ['slug' => 'production', 'kind' => 'production', 'region' => 'fr-par'], 'created' => false, 'running' => true, 'operation' => null, 'domains' => [], 'released' => []], ...$overrides];
}

/**
 * @return list<array<string, mixed>>
 */
function steps(string ...$succeeded): array
{
    return array_map(
        static fn (string $name): array => ['name' => $name, 'status' => in_array($name, $succeeded, true) ? 'succeeded' : 'pending'],
        ['source', 'build', 'image', 'migrate', 'activate', 'workers'],
    );
}

test('deploy uploads the commit to the customer project, starts a release and follows it until it is live', function (): void {
    signedIn();
    project();
    repository();

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/source/shop/'.COMMIT.'.tar.gz?X-Amz-Signature=abc', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => function (Request $request) use (&$upload) {
            // Read while the command still holds the file open.
            $upload = [
                'body' => $request->body(),
                'streamed' => str_ends_with((string) $request->toPsrRequest()->getBody()->getMetadata('uri'), '.tar.gz'),
                'size' => $request->toPsrRequest()->getBody()->getSize(),
            ];

            return Http::response('', 200);
        },
        'https://acme.brewless.eu/cli/api/environments/env1/releases' => Http::response(['data' => release(['operation' => ['steps' => steps()]])], 201),
        'https://acme.brewless.eu/cli/api/releases/r2' => Http::sequence()
            ->push(['data' => release(['status' => 'building', 'operation' => ['steps' => steps('source', 'build')]])])
            ->push(['data' => release(['status' => 'active', 'operation' => ['steps' => steps('source', 'build', 'image', 'migrate', 'activate', 'workers')]])]),
        'https://acme.brewless.eu/cli/api/environments/env1/addresses' => Http::response(['data' => [['host' => 'shop.example', 'url' => 'https://shop.example', 'source' => 'domain']]]),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])
        ->expectsOutputToContain('Deploying shop to production at commit 1a2b3c4d5e6f')
        ->expectsOutputToContain('Image built and pushed')
        ->expectsOutputToContain('Web container live and healthy')
        ->expectsOutputToContain('Release 2 is live.')
        ->expectsOutputToContain('https://shop.example')
        ->assertSuccessful();

    // The source went to the bucket, not to Brewless, and carried no token.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_starts_with($request->url(), 'https://bucket.s3.fr-par.scw.cloud/')
        && ! $request->hasHeader('Authorization'));

    // Streamed from the packed file rather than read into memory, with a known size: the bucket takes no chunked upload.
    expect($upload)->toBe(['body' => 'the source', 'streamed' => true, 'size' => strlen('the source')]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/environments/env1/releases')
        && $request['commit'] === COMMIT
        && $request['commit_time'] === 1791200000
        && strlen($request['key']) >= 8
        // The project names no PHP version, so none is asked for.
        && ! array_key_exists('php', $request->data()));
});

test('deploy asks for the PHP version brewless.yml names, quoted or not, and passes on a refusal', function (string $line): void {
    signedIn();
    project();
    repository();
    file_put_contents(getcwd().'/brewless.yml', file_get_contents(getcwd().'/brewless.yml').$line."\n");

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/source/shop/'.COMMIT.'.tar.gz?X-Amz-Signature=abc', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 200),
        'https://acme.brewless.eu/cli/api/environments/env1/releases' => Http::response(['errors' => ['php' => ['That PHP version cannot be built. Choose one of: 8.3, 8.4, 8.5.']]], 422),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])
        ->expectsOutputToContain('Choose one of: 8.3, 8.4, 8.5.')
        ->assertFailed();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env1/releases') && $request['php'] === '8.2');
})->with(['php: "8.2"', 'php: 8.2']);

test('a PHP version that is not a version is refused before anything is uploaded', function (): void {
    signedIn();
    project();
    repository();
    file_put_contents(getcwd().'/brewless.yml', file_get_contents(getcwd().'/brewless.yml')."php: latest\n");

    Http::fake();

    $this->artisan('deploy', ['environment' => 'production'])
        ->expectsOutputToContain('Write it like: php: "8.4"')
        ->assertFailed();

    Http::assertNothingSent();
});

test('deploy warns about changes that are not committed', function (): void {
    signedIn();
    project();
    repository(' M app/Models/User.php');

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/x', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 200),
        '*/environments/env1/releases' => Http::response(['data' => release(['status' => 'active'])], 201),
        '*/addresses' => Http::response(['data' => []]),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])
        ->expectsOutputToContain('changes that are not committed')
        ->assertSuccessful();
});

test('a release that fails says why, shows what the release commands printed and exits with a failure', function (): void {
    signedIn();
    project();
    repository();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/x', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 200),
        '*/environments/env1/releases' => Http::response(['data' => release([
            'status' => 'failed',
            'migration_output' => 'SQLSTATE[42P07]: relation already exists',
            'operation' => ['error_message' => 'The release commands failed. Nothing was changed for visitors.', 'steps' => [
                ['name' => 'migrate', 'status' => 'failed'],
            ]],
        ])], 201),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])
        ->expectsOutputToContain('Release 2 did not go live: The release commands failed.')
        ->expectsOutputToContain('relation already exists')
        ->assertFailed();
});

test('a failed build prints the last lines of its log under the error', function (): void {
    signedIn();
    project();
    repository();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/x', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 200),
        '*/environments/env1/releases' => Http::response(['data' => release([
            'status' => 'failed',
            'build_output' => "== source unpacked (1s)\n<error>npm error</error> Missing script: \"build\"",
            'operation' => ['error_message' => 'The build failed. Nothing was changed for visitors.', 'steps' => [
                ['name' => 'source', 'status' => 'succeeded'],
                ['name' => 'build', 'status' => 'succeeded'],
                ['name' => 'image', 'status' => 'failed'],
            ]],
        ])], 201),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])
        ->expectsOutputToContain('Image built and pushed')
        ->expectsOutputToContain('Release 2 did not go live: The build failed.')
        ->expectsOutputToContain('Last lines of the build log:')
        ->expectsOutputToContain('== source unpacked (1s)')
        // Printed as it was written: what looks like terminal markup is shown, not obeyed.
        ->expectsOutputToContain('<error>npm error</error> Missing script: "build"')
        ->assertFailed();
});

test('a release that fails before the build prints no build log', function (): void {
    signedIn();
    project();
    repository();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/x', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 200),
        '*/environments/env1/releases' => Http::response(['data' => release([
            'status' => 'failed',
            'build_output' => null,
            'operation' => ['error_message' => 'The source of this commit is not in the project\'s bucket.', 'steps' => [
                ['name' => 'source', 'status' => 'failed'],
            ]],
        ])], 201),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])
        ->doesntExpectOutputToContain('Last lines of the build log')
        ->assertFailed();
});

test('a failed upload deploys nothing', function (): void {
    signedIn();
    project();
    repository();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/x', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 403),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])
        ->expectsOutputToContain('Uploading the source to your project failed (status 403). Nothing was deployed.')
        ->assertFailed();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env1/releases'));
});

test('what the server refuses is passed on in its own words', function (int $status, array $body, string $expected): void {
    signedIn();
    project();
    repository();

    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/releases/source' => Http::response($body, $status),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])->expectsOutputToContain($expected)->assertFailed();
})->with([
    'no provider' => [422, ['errors' => ['environment' => ['Connect Scaleway on the Providers page before deploying.']]], 'Connect Scaleway on the Providers page before deploying.'],
    'ended sign-in' => [401, ['message' => 'Unauthenticated.'], 'Run: brewless login acme'],
    'no rights' => [403, ['message' => 'This action is unauthorized.'], 'Deploying takes the owner or admin role.'],
]);

test('deploy needs a brewless.yml, a sign-in and an environment that exists', function (): void {
    $this->artisan('deploy', ['environment' => 'production'])->expectsOutputToContain('Run: brewless init')->assertFailed();

    project();

    $this->artisan('deploy', ['environment' => 'production'])->expectsOutputToContain('Run: brewless login acme')->assertFailed();

    signedIn();
    Http::fake(['*/cli/api/applications' => Http::response(applications())]);

    $this->artisan('deploy', ['environment' => 'preview'])
        ->expectsOutputToContain('has no environment "preview". It has: production, staging.')
        ->assertFailed();
});

test('a live release passes on what Brewless wants the customer to know', function (): void {
    signedIn();
    project();
    repository();

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/source/shop/'.COMMIT.'.tar.gz?X-Amz-Signature=abc', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 200),
        'https://acme.brewless.eu/cli/api/environments/env1/releases' => Http::response(['data' => release(['operation' => ['steps' => steps()]])], 201),
        'https://acme.brewless.eu/cli/api/releases/r2' => Http::response(['data' => release(['status' => 'active', 'operation' => [
            'steps' => steps('source', 'build', 'image', 'migrate', 'activate', 'workers'),
            'result' => ['notices' => ['APP_URL is still https://shop.b-cdn.net, the provider\'s own address.']],
        ]])]),
        'https://acme.brewless.eu/cli/api/environments/env1/addresses' => Http::response(['data' => []]),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])
        ->expectsOutputToContain('Release 2 is live.')
        ->expectsOutputToContain('APP_URL is still https://shop.b-cdn.net')
        ->assertSuccessful();
});

test('deploy sends the whole of brewless.yml along, for Brewless to apply what it asks of the environment', function (): void {
    signedIn();
    repository();
    file_put_contents(getcwd().'/brewless.yml', <<<'YAML'
        organisation: acme
        application: shop
        framework: laravel
        release:
          - migrate --force
        environments:
          production:
            web:
              memory: 1024
              max: 5
            variables:
              APP_LOCALE: nl
        YAML);

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/source/shop/'.COMMIT.'.tar.gz?X-Amz-Signature=abc', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 200),
        'https://acme.brewless.eu/cli/api/environments/env1/releases' => Http::response(['data' => release(['operation' => ['steps' => steps()]])], 201),
        'https://acme.brewless.eu/cli/api/releases/r2' => Http::response(['data' => release(['status' => 'active', 'operation' => ['steps' => steps('source', 'build', 'image', 'migrate', 'activate', 'workers')]])]),
        'https://acme.brewless.eu/cli/api/environments/env1/addresses' => Http::response(['data' => []]),
        'https://acme.brewless.eu/cli/api/applications/app1/manifest/apply' => Http::response(['data' => applied()]),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])->assertSuccessful();

    // The resources first, for the environment the deploy is for.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/manifest/apply')
        && $request['environment'] === 'production'
        && $request['manifest']['environments']['production']['web'] === ['memory' => 1024, 'max' => 5]);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env1/releases')
        && $request['manifest']['release'] === ['migrate --force']
        && $request['manifest']['environments']['production']['web'] === ['memory' => 1024, 'max' => 5]
        && $request['manifest']['environments']['production']['variables'] === ['APP_LOCALE' => 'nl']);
});

test('what Brewless refuses in brewless.yml at a deploy is shown with its line, and nothing is deployed', function (): void {
    signedIn();
    repository();
    file_put_contents(getcwd().'/brewless.yml', <<<'YAML'
        organisation: acme
        application: shop
        environments:
          production:
            web:
              memory: 4096
              cpu: 500
        YAML);

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/source/shop/'.COMMIT.'.tar.gz?X-Amz-Signature=abc', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 200),
        'https://acme.brewless.eu/cli/api/applications/app1/manifest/apply' => Http::response(['message' => 'invalid', 'errors' => ['manifest.environments.production.web.memory' => ['Give memory from 500 to 2000 megabytes with this cpu.']]], 422),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])
        ->expectsOutputToContain('brewless.yml cannot be used as it is.')
        ->expectsOutputToContain('line 6: Give memory from 500 to 2000 megabytes with this cpu.')
        ->expectsOutputToContain('Nothing was deployed.')
        ->assertFailed();

    // Refused before anything left this machine.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/releases'));
});

test('an environment the file names that does not exist yet is made and set up before the release goes onto it', function (): void {
    signedIn();
    repository();
    file_put_contents(getcwd().'/brewless.yml', <<<'YAML'
        organisation: acme
        application: shop
        environments:
          preview:
            kind: development
            database: shop-preview
        YAML);

    $withPreview = applications();
    $withPreview['data'][0]['environments'][] = ['id' => 'env3', 'slug' => 'preview', 'name' => 'Preview'];
    $setup = static fn (string ...$succeeded): array => ['id' => 'op1', 'status' => count($succeeded) === 9 ? 'succeeded' : 'running', 'steps' => array_map(
        static fn (string $name): array => ['name' => $name, 'status' => in_array($name, $succeeded, true) ? 'succeeded' : 'pending'],
        ['registry', 'namespace', 'database', 'queue', 'bucket', 'containers', 'edge', 'variables', 'record'],
    )];

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::sequence()->push(applications())->push($withPreview),
        'https://acme.brewless.eu/cli/api/applications/app1/manifest/apply' => Http::sequence()
            ->push(['data' => applied(['created' => true, 'running' => false, 'environment' => ['slug' => 'preview', 'kind' => 'development', 'region' => 'fr-par'], 'operation' => $setup()])])
            // Applied again once it runs: the database Brewless manages already is linked then.
            ->push(['data' => applied(['environment' => ['slug' => 'preview', 'kind' => 'development', 'region' => 'fr-par'], 'database_link' => ['id' => 'op9', 'status' => 'succeeded', 'steps' => [['name' => 'access', 'status' => 'succeeded'], ['name' => 'variables', 'status' => 'succeeded']]]])]),
        'https://acme.brewless.eu/cli/api/operations/op1' => Http::response(['data' => $setup('registry', 'namespace', 'database', 'queue', 'bucket', 'containers', 'edge', 'variables', 'record')]),
        'https://acme.brewless.eu/cli/api/environments/env3/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/source/shop/'.COMMIT.'.tar.gz?X-Amz-Signature=abc', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 200),
        'https://acme.brewless.eu/cli/api/environments/env3/releases' => Http::response(['data' => release(['operation' => ['steps' => steps()]])], 201),
        'https://acme.brewless.eu/cli/api/releases/r2' => Http::response(['data' => release(['status' => 'active', 'operation' => ['steps' => steps('source', 'build', 'image', 'migrate', 'activate', 'workers')]])]),
        'https://acme.brewless.eu/cli/api/environments/env3/addresses' => Http::response(['data' => []]),
    ]);

    $this->artisan('deploy', ['environment' => 'preview'])
        ->expectsOutputToContain('Made the environment preview (development, fr-par)')
        ->expectsOutputToContain('Setting preview up from brewless.yml')
        ->expectsOutputToContain('Web, release and worker container')
        ->expectsOutputToContain('Database')
        ->expectsOutputToContain('Linking the database shop-preview to preview, with a login of its own')
        ->expectsOutputToContain('Login of its own in the database')
        // Nothing asked for a bucket: no line about one.
        ->doesntExpectOutputToContain('Bucket for files')
        ->expectsOutputToContain('Release 2 is live.')
        ->assertSuccessful();
});

test('a set-up that fails deploys nothing', function (): void {
    signedIn();
    repository();
    file_put_contents(getcwd().'/brewless.yml', "organisation: acme\napplication: shop\nenvironments:\n  production:\n    storage: shop-files\n");

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/applications/app1/manifest/apply' => Http::response(['data' => applied(['operation' => ['id' => 'op1', 'status' => 'failed', 'error_message' => 'The bucket could not be made.', 'steps' => []]])]),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])
        ->expectsOutputToContain('The bucket could not be made.')
        ->expectsOutputToContain('Nothing was deployed.')
        ->assertFailed();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/releases'));
});

test('domains the environment lacks are added only after a yes or with --domains, because a domain can move visitors', function (array $arguments, ?bool $answer, bool $added): void {
    signedIn();
    repository();
    file_put_contents(getcwd().'/brewless.yml', "organisation: acme\napplication: shop\nenvironments:\n  production:\n    database: false\n    domains: [shop.example.eu]\n");

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/applications/app1/manifest/apply' => fn (Request $request) => Http::response(['data' => ($request['domains'] ?? false) === true
            ? applied(['domains' => [['hostname' => 'shop.example.eu', 'id' => 'op2', 'status' => 'queued']]])
            : applied(['missing_domains' => ['shop.example.eu'], 'released' => ['shop-main']])]),
        'https://acme.brewless.eu/cli/api/environments/env1/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/source/shop/'.COMMIT.'.tar.gz?X-Amz-Signature=abc', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 200),
        'https://acme.brewless.eu/cli/api/environments/env1/releases' => Http::response(['data' => release(['operation' => ['steps' => steps()]])], 201),
        'https://acme.brewless.eu/cli/api/releases/r2' => Http::response(['data' => release(['status' => 'active', 'operation' => ['steps' => steps('source', 'build', 'image', 'migrate', 'activate', 'workers')]])]),
        'https://acme.brewless.eu/cli/api/environments/env1/addresses' => Http::response(['data' => []]),
    ]);

    $command = $this->artisan('deploy', ['environment' => 'production', ...$arguments]);

    if ($answer !== null) {
        $command->expectsConfirmation('Add it now?', $answer ? 'yes' : 'no');
    }

    $command->expectsOutputToContain('shop-main is no longer part of production. It keeps running at Scaleway')
        ->expectsOutputToContain($added ? 'Adding the domain shop.example.eu.' : 'Not added: shop.example.eu. Deploy with --domains to add them')
        ->expectsOutputToContain('Release 2 is live.')
        ->assertSuccessful()
        ->run();

    $asked = fn (Request $request): bool => str_ends_with($request->url(), '/manifest/apply') && ($request['domains'] ?? false) === true;
    $added ? Http::assertSent($asked) : Http::assertNotSent($asked);
})->with([
    'a yes' => [[], true, true],
    'a no' => [[], false, false],
    'without a terminal' => [['--no-interaction' => true], null, false],
    '--domains' => [['--domains' => true], null, true],
]);

test('a Brewless that does not set environments up from the file yet still deploys', function (): void {
    signedIn();
    repository();
    file_put_contents(getcwd().'/brewless.yml', "organisation: acme\napplication: shop\nenvironments:\n  production:\n    database: shop-main\n");

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/applications/app1/manifest/apply' => Http::response(['message' => 'Not Found'], 404),
        'https://acme.brewless.eu/cli/api/environments/env1/releases/source' => Http::response(['data' => ['url' => 'https://bucket.s3.fr-par.scw.cloud/source/shop/'.COMMIT.'.tar.gz?X-Amz-Signature=abc', 'method' => 'PUT', 'expires_in' => 900]]),
        'https://bucket.s3.fr-par.scw.cloud/*' => Http::response('', 200),
        'https://acme.brewless.eu/cli/api/environments/env1/releases' => Http::response(['data' => release(['operation' => ['steps' => steps()]])], 201),
        'https://acme.brewless.eu/cli/api/releases/r2' => Http::response(['data' => release(['status' => 'active', 'operation' => ['steps' => steps('source', 'build', 'image', 'migrate', 'activate', 'workers')]])]),
        'https://acme.brewless.eu/cli/api/environments/env1/addresses' => Http::response(['data' => []]),
    ]);

    $this->artisan('deploy', ['environment' => 'production'])
        ->expectsOutputToContain('does not set environments up from brewless.yml yet')
        ->expectsOutputToContain('Release 2 is live.')
        ->assertSuccessful();
});
