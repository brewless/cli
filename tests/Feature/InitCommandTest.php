<?php

declare(strict_types=1);

use App\Support\Project;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

test('init connects the project to an application that exists', function (): void {
    signedIn('acme');
    file_put_contents($this->project.'/composer.json', json_encode(['require' => ['laravel/framework' => '^13.0']]));
    Http::fake(['https://acme.brewless.eu/cli/api/applications' => Http::response(applications())]);

    $this->artisan('init', ['--application' => 'shop', '--environments' => 'none'])
        ->expectsOutputToContain('Framework: laravel')
        ->expectsOutputToContain('Wrote brewless.yml')
        ->assertSuccessful();

    $project = Project::read($this->project);

    expect([$project->organisation, $project->application, $project->framework])->toBe(['acme', 'shop', 'laravel']);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer bl_test-token'));
});

test('init refuses an application the organisation does not have and a framework it does not know', function (): void {
    signedIn('acme');
    Http::fake(['*/cli/api/applications' => Http::response(applications())]);

    $this->artisan('init', ['--application' => 'blog', '--environments' => 'none'])->expectsOutputToContain('has no application "blog"')->assertFailed();
    $this->artisan('init', ['--application' => 'shop', '--framework' => 'rails'])->expectsOutputToContain('Unknown framework "rails"')->assertFailed();

    expect(Project::exists($this->project))->toBeFalse();
});

test('a script creates a new application by name, and is told which option is missing when it names none', function (): void {
    signedIn('acme');
    freshOrganisation();

    $this->artisan('init', ['--environments' => 'none', '--no-interaction' => true])
        ->expectsOutputToContain('Say which application this is: --application=<slug> for one that exists, or --name=<name> to create a new one.')
        ->assertFailed();

    expect(Project::exists($this->project))->toBeFalse();

    $this->artisan('init', ['--name' => 'PrivaSearch', '--environments' => 'none', '--no-interaction' => true])
        ->expectsOutputToContain('Created the application privasearch.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/cli/api/applications') && $request['name'] === 'PrivaSearch');
    expect(Project::read($this->project)->application)->toBe('privasearch');
});

test('init needs a sign-in first', function (): void {
    $this->artisan('init', ['--application' => 'shop'])
        ->expectsOutputToContain('not signed in to any organisation')
        ->assertFailed();
});

/**
 * An organisation in which a new application gets its environments made and set up.
 */
function freshOrganisation(): void
{
    Http::fake([
        '*/cli/api/applications' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['data' => ['id' => 'app9', 'slug' => 'privasearch', 'environments' => []]], 201)
            : Http::response(['data' => []]),
        '*/applications/app9/environments' => fn (Request $request) => Http::response(['data' => ['id' => 'env-'.strtolower($request['name']), 'slug' => strtolower($request['name']), 'name' => $request['name'], 'kind' => $request['kind'], 'region' => $request['region']]], 201),
        '*/databases' => Http::response(['data' => [], 'unavailable' => []]),
        '*/provision' => Http::response(['data' => ['id' => 'op1', 'status' => 'succeeded', 'steps' => [['name' => 'containers', 'status' => 'succeeded']], 'result' => ['address' => 'https://acme-privasearch.b-cdn.net', 'variables_to_set' => []]]], 201),
    ]);
}

test('init adds production and staging, asks what they should have and sets them up after one yes', function (): void {
    signedIn('acme');
    freshOrganisation();

    $this->artisan('init')
        ->expectsQuestion('Which application is this?', '+ a new application')
        ->expectsQuestion('What is the application called?', 'privasearch')
        ->expectsQuestion('Which environments should it have?', ['production', 'staging'])
        ->expectsQuestion('In which region?', 'nl-ams')
        ->expectsQuestion('Which database should production use?', 'new')
        ->expectsQuestion('Where should the jobs of production wait?', 'sqs')
        ->expectsConfirmation('A private bucket at Scaleway Object Storage for the files of production? A container keeps no files of its own.', 'yes')
        ->expectsConfirmation('Set staging up the same way (database: a new one, queue: Scaleway Queues, bucket: yes)?', 'yes')
        ->expectsOutputToContain('Added production and staging in Amsterdam.')
        ->expectsConfirmation('Make this now?', 'yes')
        ->expectsOutputToContain('staging is set up.')
        ->expectsOutputToContain('Next: brewless deploy production')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/applications/app9/environments') && $request->data() === ['name' => 'Staging', 'kind' => 'staging', 'region' => 'nl-ams']);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env-production/provision') && $request['database'] === 'new' && $request['queue'] === 'sqs' && $request['bucket'] === true);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env-staging/provision') && $request['queue'] === 'sqs');
});

test('saying no at the end makes nothing and says how to do it later', function (): void {
    signedIn('acme');
    freshOrganisation();

    $this->artisan('init')
        ->expectsQuestion('Which application is this?', '+ a new application')
        ->expectsQuestion('What is the application called?', 'privasearch')
        ->expectsQuestion('Which environments should it have?', ['production'])
        ->expectsQuestion('In which region?', 'fr-par')
        ->expectsQuestion('Which database should production use?', 'none')
        ->expectsQuestion('Where should the jobs of production wait?', 'none')
        ->expectsConfirmation('A private bucket at Scaleway Object Storage for the files of production? A container keeps no files of its own.', 'no')
        ->expectsConfirmation('Make this now?', 'no')
        ->expectsOutputToContain('Later: brewless provision production --database=none --queue=none')
        ->expectsOutputToContain('Next: brewless provision production')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/provision'));
});

test('a script names everything in options and is asked nothing', function (): void {
    signedIn('acme');
    Http::fake([
        '*/cli/api/applications' => Http::response(['data' => [['id' => 'app9', 'slug' => 'shop', 'environments' => [['id' => 'env1', 'slug' => 'production']]]]]),
        '*/applications/app9/environments' => Http::response(['data' => ['id' => 'env-staging', 'slug' => 'staging']], 201),
        '*/provision' => Http::response(['data' => ['id' => 'op1', 'status' => 'succeeded', 'steps' => [], 'result' => []]], 201),
    ]);

    // Production is there already: only staging is added.
    $this->artisan('init', ['--application' => 'shop', '--environments' => 'production,staging', '--region' => 'fr-par', '--database' => 'new', '--queue' => 'database', '--yes' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Added staging in Paris.')
        ->assertSuccessful();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env-staging/provision') && $request['database'] === 'new' && $request['bucket'] === false);

    // Without options a script adds nothing.
    $this->artisan('init', ['--application' => 'shop', '--force' => true, '--no-interaction' => true])->assertSuccessful();

    Http::assertSentCount(4);
});

test('a vapor.yml can be the starting point: its environments, its PHP version and what each one had', function (): void {
    signedIn('acme');
    freshOrganisation();
    file_put_contents($this->project.'/vapor.yml', <<<'YAML'
        id: 1234
        name: privasearch
        environments:
            production:
                runtime: 'php-8.3:al2'
                database: privasearch-db
                storage: privasearch-files
                domain: privasearch.example
            staging:
                runtime: 'php-8.4:al2'
                queues: false
        YAML);

    $this->artisan('init')
        ->expectsConfirmation('There is a vapor.yml here (production, staging). Start from it, to move this application from Vapor to Brewless?', 'yes')
        ->expectsQuestion('Which application is this?', '+ a new application')
        ->expectsQuestion('What is the application called?', 'privasearch')
        ->expectsQuestion('Which environments should it have?', ['production', 'staging'])
        ->expectsQuestion('In which region?', 'fr-par')
        ->expectsConfirmation('Make this now?', 'yes')
        ->expectsOutputToContain('brewless domain:add production privasearch.example')
        ->expectsOutputToContain('`brewless env:pull <environment>`, add yours from Vapor, then `brewless env:push <environment>`')
        ->expectsOutputToContain('brewless.yml names the databases and buckets as vapor.yml does')
        ->assertSuccessful();

    expect(Yaml::parseFile($this->project.'/brewless.yml')['environments']['production'])->toMatchArray(['region' => 'fr-par', 'database' => 'privasearch-db', 'storage' => 'privasearch-files', 'domains' => ['privasearch.example']]);

    expect(Project::read($this->project)->php)->toBe('8.3');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env-production/provision') && $request['database'] === 'new' && $request['queue'] === 'sqs' && $request['bucket'] === true);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env-staging/provision') && $request['database'] === 'none' && $request['queue'] === 'none' && $request['bucket'] === false);
});

test('a vapor.yml that is declined changes nothing about the questions', function (): void {
    signedIn('acme');
    Http::fake(['*/cli/api/applications' => Http::response(applications())]);
    file_put_contents($this->project.'/vapor.yml', "id: 1\nenvironments:\n    production:\n        runtime: 'php-8.2:al2'\n");

    $this->artisan('init', ['--application' => 'shop', '--environments' => 'none'])
        ->expectsConfirmation('There is a vapor.yml here (production). Start from it, to move this application from Vapor to Brewless?', 'no')
        ->assertSuccessful();

    expect(Project::read($this->project)->php)->toBeNull();
});

test('the same way is not offered when the next environment\'s region cannot make it', function (): void {
    signedIn('acme');
    // The first stub that matches answers: staging's region goes before the rest of the fresh organisation.
    Http::fake([
        '*/environments/env-staging/databases' => Http::response(['data' => [], 'unavailable' => [], 'offers' => ['serverless_database' => false, 'queue' => true]]),
    ]);
    freshOrganisation();

    $this->artisan('init')
        ->expectsQuestion('Which application is this?', '+ a new application')
        ->expectsQuestion('What is the application called?', 'privasearch')
        ->expectsQuestion('Which environments should it have?', ['production', 'staging'])
        ->expectsQuestion('In which region?', 'fr-par')
        ->expectsQuestion('Which database should production use?', 'new')
        ->expectsQuestion('Where should the jobs of production wait?', 'database')
        ->expectsConfirmation('A private bucket at Scaleway Object Storage for the files of production? A container keeps no files of its own.', 'no')
        ->doesntExpectOutputToContain('Set staging up the same way')
        ->expectsChoice('Which database should staging use?', 'none', ['none', 'No database'])
        ->expectsQuestion('Where should the jobs of staging wait?', 'none')
        ->expectsConfirmation('A private bucket at Scaleway Object Storage for the files of staging? A container keeps no files of its own.', 'no')
        ->expectsConfirmation('Make this now?', 'no')
        ->assertSuccessful();
});

test('a plan the server refuses for the region is said in its words, with how to try again', function (): void {
    signedIn('acme');
    Http::fake([
        '*/cli/api/applications' => Http::response(['data' => [['id' => 'app9', 'slug' => 'shop', 'environments' => []]]]),
        '*/applications/app9/environments' => Http::response(['data' => ['id' => 'env-production', 'slug' => 'production', 'region' => 'pl-waw']], 201),
        '*/provision' => Http::response(['message' => 'Invalid.', 'errors' => ['database' => ['Scaleway does not offer Serverless SQL Database in Warsaw. Choose a database that exists already, or none.']]], 422),
    ]);

    $this->artisan('init', ['--application' => 'shop', '--environments' => 'production', '--region' => 'pl-waw', '--database' => 'new', '--yes' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Scaleway does not offer Serverless SQL Database in Warsaw.')
        ->expectsOutputToContain('When that is settled: brewless provision production')
        ->expectsOutputToContain('Next: brewless provision production')
        ->assertSuccessful();
});

/**
 * A vapor.yml as a Laravel application on Vapor has it: production as it
 * runs, and a staging environment that uses most of what Vapor offers.
 */
function realisticVaporFile(): string
{
    return <<<'YAML'
        id: 76217
        name: privasearch
        ignore:
            - services
            - docs
        environments:
            production:
                memory: 1024
                cli-memory: 512
                gateway-version: 2
                runtime: 'php-8.4:al2023'
                database: regify
                storage: privasearch
                queue-timeout: 540
                domain:
                    - privasearch.eu
                    - '*.privasearch.eu'
                build:
                    - 'composer install --no-dev'
                    - 'npm ci && npm run build && rm -rf node_modules'
                deploy:
                    - 'php artisan migrate --force'
                    - 'php artisan tenants:migrate'
            staging:
                runtime: 'php-8.4:al2-arm'
                memory: 512
                timeout: 5
                warm: 10
                concurrency: 120
                tmp-storage: 4096
                cli-timeout: 600
                queues:
                    - default
                    - emails: 3
                queue-memory: 1024
                queue-concurrency: 5
                scheduler: sub-minute
                cache: privasearch-cache
                octane: true
                domain: staging.privasearch.eu
                build:
                    - 'composer install'
                    - 'npm ci && npm run build'
                    - 'php artisan event:cache'
                deploy:
                    - 'php artisan migrate --force'
                    - 'echo done'
        YAML;
}

test('init --from-vapor writes every setting of vapor.yml that brewless.yml has, and says once what it could not carry over', function (): void {
    signedIn('acme');
    file_put_contents($this->project.'/composer.json', json_encode(['require' => ['laravel/framework' => '^13.0']]));
    file_put_contents($this->project.'/package-lock.json', '{}');
    file_put_contents($this->project.'/vapor.yml', realisticVaporFile());
    Http::fake(['*/cli/api/applications' => Http::response(applications())]);

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('init', ['--application' => 'shop', '--from-vapor' => true, '--environments' => 'none', '--no-interaction' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($this->project.'/brewless.yml'))->toBe(<<<'YAML'
            # Which application this is at Brewless. No secrets belong in this file.
            organisation: acme
            application: shop
            framework: laravel
            php: '8.4'

            release:
              - 'migrate --force'
              - 'tenants:migrate'

            environments:
              production:
                kind: production
                web:
                  memory: 1024
                release:
                  memory: 512
                worker:
                  timeout: 540
                database: regify
                queue: sqs
                storage: privasearch
                domains:
                  - privasearch.eu
                  - '*.privasearch.eu'
              staging:
                kind: staging
                web:
                  memory: 512
                  timeout: 10
                  min: 1
                  max: 3
                  tmp-storage: 2048
                release:
                  timeout: 600
                worker:
                  memory: 1024
                  queues:
                    - default
                    - emails
                scheduler: true
                queue: sqs
                domains:
                  - staging.privasearch.eu

            YAML);

    // Each once, in plain words, in the terminal only.
    expect($output)
        ->toContain('Not carried over from vapor.yml as it was:')
        ->toContain('- `gateway-version` (production): There is no API Gateway at Brewless')
        ->toContain('- `build`: Left out, as Brewless does it itself: composer install --no-dev, npm ci, npm run build, rm -rf node_modules, composer install.')
        ->toContain('- `build`, `deploy` (staging): brewless.yml has one list for all environments, so production\'s is used.')
        ->toContain('- `deploy` (staging): A release command is a command of the framework\'s console, so this one is left out: echo done.')
        ->toContain('- `warm` (staging): Vapor kept 10 Lambda containers warm')
        ->toContain('- `concurrency` (staging): Vapor ran up to 120 requests at once')
        ->toContain('- `timeout` (staging): `web.timeout` is from 10 to 3600, so it is 10 instead of 5.')
        ->toContain('- `tmp-storage` (staging): Local disk is at most four times the memory')
        ->toContain('- `queue-concurrency` (staging)')
        ->toContain('- `scheduler` (staging)')
        ->toContain('- `cache` (staging): Not carried over: add `cache: <name>`')
        ->toContain('- `octane` (staging)')
        ->toContain('- `runtime` (staging): Brewless chooses the processor of the containers itself')
        ->toContain('- `ignore`: Brewless deploys what is committed')
        ->and(substr_count($output, 'There is no API Gateway'))->toBe(1)
        ->and(file_get_contents($this->project.'/brewless.yml'))->not->toContain('#  ', 'gateway', 'octane', 'cache');
});

test('init --from-vapor keeps the default environment, says what it cannot build and leaves out what Brewless cannot name', function (): void {
    signedIn('acme');
    file_put_contents($this->project.'/composer.json', json_encode(['require' => ['laravel/framework' => '^13.0']]));
    file_put_contents($this->project.'/vapor.yml', <<<'YAML'
        id: 1
        name: shop
        default-environment: staging
        separate-vendor: true
        environments:
            production:
                runtime: docker
                queues: false
                build:
                    - 'npm ci && npm run build'
            staging:
                runtime: 'php-8.2:al2'
                storage: Shop_Files
                domain: 'not a domain'
            Preview_Environment:
                memory: 1024
        YAML);
    Http::fake(['*/cli/api/applications' => Http::response(applications())]);

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('init', ['--application' => 'shop', '--from-vapor' => true, '--environments' => 'none', '--no-interaction' => true]);
    $output = Artisan::output();

    // Without a package-lock.json Brewless builds no frontend, so the step stays.
    expect($exitCode)->toBe(0)
        ->and(file_get_contents($this->project.'/brewless.yml'))->toBe(<<<'YAML'
            # Which application this is at Brewless. No secrets belong in this file.
            organisation: acme
            application: shop
            framework: laravel
            default-environment: staging

            build:
              - 'npm ci && npm run build'

            environments:
              production:
                kind: production
                queue: false
              staging:
                kind: staging
                queue: sqs

            YAML)
        ->and($output)
        ->toContain('- `separate-vendor`: Brewless has no such setting.')
        ->toContain('- `runtime` (production): Brewless builds the image itself, on its public base image')
        ->toContain('- `runtime` (staging): Brewless builds for PHP 8.3 and later, not for 8.2.')
        ->toContain('- `storage` (staging): A bucket name at Brewless is 3 to 63 lowercase letters')
        ->toContain('- `domain` (staging): Not a domain name Brewless takes: not a domain.')
        ->toContain('- `Preview_Environment`: An environment name at Brewless is 2 to 30 lowercase letters')
        ->not->toContain('one list for all environments');
});

test('the environments init adds from a vapor.yml get their region in brewless.yml', function (): void {
    signedIn('acme');
    freshOrganisation();
    file_put_contents($this->project.'/vapor.yml', "id: 1\nenvironments:\n    production:\n        runtime: 'php-8.4:al2023'\n        memory: 2048\n    staging:\n        queues: false\n");

    $this->artisan('init', ['--from-vapor' => true])
        ->expectsQuestion('Which application is this?', '+ a new application')
        ->expectsQuestion('What is the application called?', 'privasearch')
        ->expectsQuestion('Which environments should it have?', ['production'])
        ->expectsQuestion('In which region?', 'nl-ams')
        ->expectsConfirmation('Make this now?', 'no')
        ->assertSuccessful();

    // Staging was not added, so it has no region yet; it stays in the file as vapor.yml has it.
    expect(Yaml::parseFile($this->project.'/brewless.yml')['environments'])->toBe([
        'production' => ['kind' => 'production', 'region' => 'nl-ams', 'web' => ['memory' => 2048], 'queue' => 'sqs'],
        'staging' => ['kind' => 'staging', 'queue' => false],
    ]);
});

/**
 * An organisation with two Scaleway projects connected and no applications yet.
 */
function twoProjects(): void
{
    Http::fake([
        '*/cli/api/applications' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['data' => ['id' => 'app9', 'slug' => 'privasearch', 'environments' => []]], 201)
            : Http::response(['data' => [], 'options' => ['projects' => [['id' => 'p1', 'name' => 'Acme production'], ['id' => 'p2', 'name' => 'Client staging']]]]),
    ]);
}

test('with several scaleway projects a new application is asked which one it runs in', function (): void {
    signedIn('acme');
    twoProjects();

    $this->artisan('init', ['--environments' => 'none'])
        ->expectsQuestion('Which application is this?', '+ a new application')
        ->expectsQuestion('What is the application called?', 'privasearch')
        ->expectsQuestion('Which Scaleway project does it run in?', 'p2')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && ($request['provider_connection_id'] ?? null) === 'p2');
});

test('--project names it by name or id, and an unknown one is refused with the ones there are', function (): void {
    signedIn('acme');
    twoProjects();

    $this->artisan('init', ['--environments' => 'none', '--project' => 'client staging'])
        ->expectsQuestion('Which application is this?', '+ a new application')
        ->expectsQuestion('What is the application called?', 'privasearch')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && ($request['provider_connection_id'] ?? null) === 'p2');

    $this->artisan('init', ['--environments' => 'none', '--project' => 'nope', '--force' => true])
        ->expectsQuestion('Which application is this?', '+ a new application')
        ->expectsQuestion('What is the application called?', 'privasearch')
        ->expectsOutputToContain('There is no Scaleway project "nope". Connected: Acme production, Client staging.')
        ->assertFailed();
});

test('with one project nothing is asked and none is sent', function (): void {
    signedIn('acme');
    freshOrganisation();

    $this->artisan('init', ['--environments' => 'none'])
        ->expectsQuestion('Which application is this?', '+ a new application')
        ->expectsQuestion('What is the application called?', 'privasearch')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && ! array_key_exists('provider_connection_id', $request->data()));
});
