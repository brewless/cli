<?php

declare(strict_types=1);

use App\Support\Project;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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
        ->assertSuccessful();

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
