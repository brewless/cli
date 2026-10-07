<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    signedIn();
    project();
});

test('provision sets the environment up, prints each step and says what is left to do', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/provision' => Http::response(['data' => ['id' => 'op1', 'status' => 'queued', 'steps' => [['name' => 'registry', 'status' => 'pending']]]], 201),
        '*/cli/api/operations/op1' => Http::sequence()
            ->push(['data' => ['id' => 'op1', 'status' => 'running', 'steps' => [['name' => 'registry', 'status' => 'succeeded'], ['name' => 'namespace', 'status' => 'running']]]])
            ->push(['data' => [
                'id' => 'op1',
                'status' => 'succeeded',
                'steps' => [['name' => 'registry', 'status' => 'succeeded'], ['name' => 'namespace', 'status' => 'succeeded']],
                'result' => ['address' => 'https://acme-shop-production.b-cdn.net', 'variables_to_set' => ['DB_USERNAME', 'DB_PASSWORD']],
            ]]),
    ]);

    $this->artisan('provision', ['environment' => 'production', '--no-interaction' => true])
        ->expectsOutputToContain('Registry for your images')
        ->expectsOutputToContain('Namespace for the containers')
        ->expectsOutputToContain('After your first deploy it answers on https://acme-shop-production.b-cdn.net.')
        ->expectsOutputToContain('DB_USERNAME, DB_PASSWORD')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/environments/env1/provision') && $request['database'] === 'new' && $request['queue'] === 'database' && $request['bucket'] === false && strlen($request['key']) >= 8);
});

test('provision can leave the database out, and passes on what stops it', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/provision' => Http::response(['data' => ['id' => 'op1', 'status' => 'failed', 'steps' => [], 'error_message' => 'Scaleway is not connected any more.']], 201),
    ]);

    $this->artisan('provision', ['environment' => 'production', '--no-database' => true])
        ->expectsOutputToContain('Scaleway is not connected any more.')
        ->assertFailed();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/provision') && $request['database'] === 'none' && $request['queue'] === 'none');
});

test('an environment that has containers already is refused by the server', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/provision' => Http::response(['errors' => ['environment' => ['This environment has containers already. Nothing was made.']]], 422),
    ]);

    $this->artisan('provision', ['environment' => 'production', '--no-interaction' => true])
        ->expectsOutputToContain('This environment has containers already.')
        ->assertFailed();
});

test('provision asks what the environment should have when nothing was said', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/databases' => Http::response(['data' => [['key' => 'scaleway:database:rdb-1', 'kind' => 'database', 'name' => 'main', 'in_use' => true]], 'unavailable' => []]),
        '*/environments/env1/provision' => Http::response(['data' => ['id' => 'op1', 'status' => 'succeeded', 'steps' => [['name' => 'database', 'status' => 'succeeded'], ['name' => 'queue', 'status' => 'succeeded'], ['name' => 'bucket', 'status' => 'succeeded']], 'result' => ['address' => 'https://x.b-cdn.net', 'variables_to_set' => ['DB_DATABASE']]]], 201),
    ]);

    $this->artisan('provision', ['environment' => 'production'])
        ->expectsQuestion('Which database should production use?', 'scaleway:database:rdb-1')
        ->expectsQuestion('Where should the jobs of production wait?', 'sqs')
        ->expectsConfirmation('A private bucket at Scaleway Object Storage for the files of production? A container keeps no files of its own.', 'no')
        ->expectsOutputToContain('Queue')
        ->doesntExpectOutputToContain('Bucket for files')
        ->expectsOutputToContain('DB_DATABASE')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/provision')
        && $request['database'] === 'existing' && $request['database_resource'] === 'scaleway:database:rdb-1' && $request['queue'] === 'sqs' && $request['bucket'] === false);
});

test('provision takes an existing database by its name, and says which there are when the name is wrong', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/databases' => Http::response(['data' => [['key' => 'scaleway:database:rdb-1', 'kind' => 'database', 'name' => 'main', 'in_use' => false]], 'unavailable' => []]),
        '*/environments/env1/provision' => Http::response(['data' => ['id' => 'op1', 'status' => 'succeeded', 'steps' => [], 'result' => []]], 201),
    ]);

    $this->artisan('provision', ['environment' => 'production', '--database-resource' => 'main', '--queue' => 'none', '--bucket' => true])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/provision')
        && $request['database'] === 'existing' && $request['database_resource'] === 'scaleway:database:rdb-1' && $request['queue'] === 'none' && $request['bucket'] === true);

    $this->artisan('provision', ['environment' => 'production', '--database-resource' => 'other'])->expectsOutputToContain('has no database "other"')->assertFailed();
    $this->artisan('provision', ['environment' => 'production', '--no-database' => true, '--queue' => 'database'])->expectsOutputToContain('needs a database')->assertFailed();
    $this->artisan('provision', ['environment' => 'production', '--queue' => 'kafka'])->expectsOutputToContain('Unknown queue "kafka"')->assertFailed();
});

test('without Scaleway connected nothing is asked and it says where to connect it', function (): void {
    Http::fake([
        '*/cli/api/applications' => Http::response(applications()),
        '*/environments/env1/databases' => Http::response(['data' => [], 'unavailable' => ['scaleway' => 'not_connected']]),
    ]);

    $this->artisan('provision', ['environment' => 'production'])
        ->expectsOutputToContain('Scaleway is not connected to this organisation yet.')
        ->assertFailed();
});
