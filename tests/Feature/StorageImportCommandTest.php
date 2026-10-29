<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const SOURCE_SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
const BUCKET_SECRET = '99999999-8888-4777-8666-555555555555';
const DESTINATION = 'shop-production-7b1d4e2a';

/**
 * A copy as Brewless answers it, from start to end.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function import(array $overrides = []): array
{
    return [
        'id' => 'op1',
        'kind' => 'storage.import',
        'status' => 'queued',
        'actor_name' => 'Ada',
        'result' => null,
        'error_message' => null,
        'created_at' => '2026-10-29T12:00:00Z',
        'steps' => [
            ['name' => 'start', 'status' => 'pending', 'progress' => null, 'error_message' => null],
            ['name' => 'copy', 'status' => 'pending', 'progress' => null, 'error_message' => null],
            ['name' => 'clean', 'status' => 'pending', 'progress' => null, 'error_message' => null],
        ],
        ...$overrides,
    ];
}

/**
 * @param  array<string, int>  $progress
 * @return array<string, mixed>
 */
function copying(array $progress): array
{
    return import(['status' => 'queued', 'steps' => [
        ['name' => 'start', 'status' => 'succeeded', 'progress' => null, 'error_message' => null],
        ['name' => 'copy', 'status' => 'pending', 'progress' => $progress + ['eta_seconds' => 90], 'error_message' => 'Copying: …'],
    ]]);
}

/**
 * @param  list<array<string, mixed>>  $states  what GET /operations/op1 answers, one per look
 */
function brewlessImports(array $states = []): void
{
    $states = $states === [] ? [import(['status' => 'succeeded', 'result' => ['summary' => '10 files copied (9.5 MB); 4 were there already.']])] : $states;

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/storage/imports' => Http::response(['data' => import()], 201),
        'https://acme.brewless.eu/cli/api/operations/op1' => Http::sequence(array_map(static fn (array $state) => Http::response(['data' => $state]), $states)),
        'https://my-vapor-bucket.s3.amazonaws.com' => Http::response('', 403, ['x-amz-bucket-region' => 'eu-central-1']),
    ]);
}

function startedImport(): ?Request
{
    return Http::recorded(static fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/storage/imports'))->first()[0] ?? null;
}

beforeEach(function (): void {
    signedIn();
    project();
});

afterEach(function (): void {
    foreach (['SOURCE_AWS_ACCESS_KEY_ID', 'SOURCE_AWS_SECRET_ACCESS_KEY', 'SOURCE_AWS_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SHARED_CREDENTIALS_FILE', 'AWS_CONFIG_FILE'] as $name) {
        putenv($name);
    }
});

test('the copy starts in the project with the key from SOURCE_AWS_*, is followed to its end, and the key is never printed', function (): void {
    putenv('SOURCE_AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE');
    putenv('SOURCE_AWS_SECRET_ACCESS_KEY='.SOURCE_SECRET);
    brewlessImports([
        copying(['copied' => 3, 'to_copy' => 10, 'bytes' => 3_000_000, 'total_bytes' => 10_000_000]),
        import(['status' => 'succeeded', 'result' => ['summary' => '10 files copied (9.5 MB); 4 were there already.']]),
    ]);

    $this->artisan('storage:import', ['environment' => 'production', 'source' => 's3://my-vapor-bucket/uploads', '--source-region' => 'eu-west-1'])
        ->expectsOutputToContain('From  s3://my-vapor-bucket/uploads at AWS, eu-west-1')
        ->expectsOutputToContain('Key   from SOURCE_AWS_ACCESS_KEY_ID and SOURCE_AWS_SECRET_ACCESS_KEY')
        ->expectsOutputToContain('Nothing is removed, at the source or in the bucket.')
        ->expectsConfirmation('Copy these files into the bucket of production?', 'yes')
        ->expectsOutputToContain('3 of 10 files, 3.0 MB of 10.0 MB, about 1 min to go')
        ->expectsOutputToContain('10 files copied (9.5 MB); 4 were there already.')
        ->doesntExpectOutputToContain(SOURCE_SECRET)
        ->assertSuccessful();

    expect(startedImport()?->data())->toMatchArray([
        'source' => ['bucket' => 'my-vapor-bucket', 'prefix' => 'uploads', 'region' => 'eu-west-1', 'endpoint' => null, 'path_style' => false, 'access_key' => 'AKIAIOSFODNN7EXAMPLE', 'secret_key' => SOURCE_SECRET],
        'dry_run' => false,
    ])->and(startedImport()?->data()['key'])->toStartWith('cli-');
});

test('--from-env reads the AWS_* lines of the old application, and AWS_* of the shell are left alone', function (): void {
    putenv('AWS_ACCESS_KEY_ID=AKIASHELLSHELLSHELL1');
    putenv('AWS_SECRET_ACCESS_KEY=shell-secret-shell-secret');
    file_put_contents($this->home.'/old.env', "APP_KEY=base64:nope\nAWS_BUCKET=old-uploads\nAWS_DEFAULT_REGION=eu-west-3\nAWS_ACCESS_KEY_ID=AKIAFILEFILEFILEFILE\nAWS_SECRET_ACCESS_KEY=".SOURCE_SECRET."\n");
    brewlessImports();

    $this->artisan('storage:import', ['environment' => 'production', '--from-env' => $this->home.'/old.env', '--force' => true])
        ->expectsOutputToContain('From  s3://old-uploads at AWS, eu-west-3')
        ->expectsOutputToContain('Key   from '.$this->home.'/old.env')
        ->assertSuccessful();

    expect(startedImport()?->data()['source'])->toMatchArray(['bucket' => 'old-uploads', 'region' => 'eu-west-3', 'access_key' => 'AKIAFILEFILEFILEFILE', 'secret_key' => SOURCE_SECRET])
        ->and(json_encode(startedImport()?->data()))->not->toContain('APP_KEY', 'shell-secret');
});

test('the .env of an application on Vapor has no key, so it is asked for in a terminal', function (): void {
    file_put_contents($this->home.'/vapor.env', "AWS_BUCKET=my-vapor-bucket\n");
    brewlessImports();

    $this->artisan('storage:import', ['environment' => 'production', '--from-env' => $this->home.'/vapor.env', '--force' => true])
        ->expectsQuestion('Access key that may read my-vapor-bucket', 'AKIAIOSFODNN7EXAMPLE')
        ->expectsQuestion('Its secret key', SOURCE_SECRET)
        // Not in the file and not given: AWS says where the bucket is.
        ->expectsOutputToContain('From  s3://my-vapor-bucket at AWS, eu-central-1')
        ->expectsOutputToContain('Key   from what you typed')
        ->assertSuccessful();

    expect(startedImport()?->data()['source']['region'])->toBe('eu-central-1');
});

test('without a key and without a terminal nothing starts', function (): void {
    brewlessImports();

    $this->artisan('storage:import', ['environment' => 'production', 'source' => 's3://my-vapor-bucket', '--no-interaction' => true])
        ->expectsOutputToContain('No key for s3://my-vapor-bucket. Set SOURCE_AWS_ACCESS_KEY_ID and SOURCE_AWS_SECRET_ACCESS_KEY')
        ->assertFailed();

    expect(startedImport())->toBeNull();
});

test('a profile of the AWS CLI gives the key and the region', function (): void {
    file_put_contents($this->home.'/credentials', "[default]\naws_access_key_id = AKIADEFAULTDEFAULT01\naws_secret_access_key = default-secret-default\n\n[migration]\naws_access_key_id = AKIAIOSFODNN7EXAMPLE\naws_secret_access_key = ".SOURCE_SECRET."\n");
    file_put_contents($this->home.'/config', "[profile migration]\nregion = eu-north-1\n");
    putenv('AWS_SHARED_CREDENTIALS_FILE='.$this->home.'/credentials');
    putenv('AWS_CONFIG_FILE='.$this->home.'/config');
    brewlessImports();

    $this->artisan('storage:import', ['environment' => 'production', 'source' => 's3://my-vapor-bucket', '--source-profile' => 'migration', '--force' => true])
        ->expectsOutputToContain('Key   from the AWS profile migration')
        ->assertSuccessful();

    expect(startedImport()?->data()['source'])->toMatchArray(['region' => 'eu-north-1', 'access_key' => 'AKIAIOSFODNN7EXAMPLE', 'secret_key' => SOURCE_SECRET]);
});

test('another S3 service needs its endpoint and region, and gets path style when asked', function (): void {
    putenv('SOURCE_AWS_ACCESS_KEY_ID=DO00EXAMPLEEXAMPLE00');
    putenv('SOURCE_AWS_SECRET_ACCESS_KEY='.SOURCE_SECRET);
    brewlessImports();

    $this->artisan('storage:import', ['environment' => 'production', 'source' => 's3://spaces-bucket', '--source-endpoint' => 'https://ams3.digitaloceanspaces.com', '--force' => true])
        ->expectsOutputToContain('Give the region the S3 service expects with --source-region')
        ->assertFailed();

    $this->artisan('storage:import', ['environment' => 'production', 'source' => 's3://spaces-bucket', '--source-endpoint' => 'https://ams3.digitaloceanspaces.com/', '--source-region' => 'ams3', '--source-path-style' => true, '--force' => true])
        ->expectsOutputToContain('From  s3://spaces-bucket on https://ams3.digitaloceanspaces.com, ams3')
        ->doesntExpectOutputToContain('AWS bills')
        ->assertSuccessful();

    expect(startedImport()?->data()['source'])->toMatchArray(['endpoint' => 'https://ams3.digitaloceanspaces.com', 'path_style' => true]);
});

test('a key that cannot be one is refused before it is sent', function (): void {
    putenv('SOURCE_AWS_ACCESS_KEY_ID=AKIA IOSFODNN7');
    putenv('SOURCE_AWS_SECRET_ACCESS_KEY='.SOURCE_SECRET);
    brewlessImports();

    $this->artisan('storage:import', ['environment' => 'production', 'source' => 's3://my-vapor-bucket', '--source-region' => 'eu-west-1', '--force' => true])
        ->expectsOutputToContain('does not look like an S3 key')
        ->assertFailed();

    expect(startedImport())->toBeNull();
});

test('saying no starts nothing, and a dry run asks nothing', function (): void {
    putenv('SOURCE_AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE');
    putenv('SOURCE_AWS_SECRET_ACCESS_KEY='.SOURCE_SECRET);
    brewlessImports([import(['status' => 'succeeded', 'result' => ['dry_run' => true, 'summary' => 'The bucket holds 1,234 files (4.7 GB). Nothing was copied.']])]);

    $this->artisan('storage:import', ['environment' => 'production', 'source' => 's3://my-vapor-bucket', '--source-region' => 'eu-west-1'])
        ->expectsConfirmation('Copy these files into the bucket of production?', 'no')
        ->assertSuccessful();

    expect(startedImport())->toBeNull();

    $this->artisan('storage:import', ['environment' => 'production', 'source' => 's3://my-vapor-bucket', '--source-region' => 'eu-west-1', '--dry-run' => true])
        ->expectsOutputToContain('A dry run: nothing is copied.')
        ->expectsOutputToContain('The bucket holds 1,234 files (4.7 GB). Nothing was copied.')
        ->assertSuccessful();

    expect(startedImport()?->data()['dry_run'])->toBeTrue();
});

test('--no-follow starts the copy and returns, and storage:status shows it', function (): void {
    putenv('SOURCE_AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE');
    putenv('SOURCE_AWS_SECRET_ACCESS_KEY='.SOURCE_SECRET);
    brewlessImports();
    Http::fake(['https://acme.brewless.eu/cli/api/environments/env1/operations?kind=storage.import' => Http::response(['data' => [copying(['copied' => 1200, 'to_copy' => 5000, 'bytes' => 2_000_000_000, 'total_bytes' => 9_000_000_000])]])]);

    $this->artisan('storage:import', ['environment' => 'production', 'source' => 's3://my-vapor-bucket', '--source-region' => 'eu-west-1', '--force' => true, '--no-follow' => true])
        ->expectsOutputToContain('Follow it with: brewless storage:status production')
        ->assertSuccessful();

    expect(Http::recorded(static fn (Request $request): bool => str_ends_with($request->url(), '/operations/op1')))->toBeEmpty();

    $this->artisan('storage:status', ['environment' => 'production'])
        ->expectsOutputToContain('Copy into the bucket of production, started 2026-10-29T12:00:00Z by Ada')
        ->expectsOutputToContain('1,200 of 5,000 files, 2.0 GB of 9.0 GB, about 1 min to go')
        ->assertSuccessful();
});

test('storage:status says how the last copy ended, and when there is none', function (): void {
    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/operations?kind=storage.import' => Http::sequence()
            ->push(['data' => []])
            ->push(['data' => [import(['status' => 'failed', 'error_message' => 'The copy stopped before it was done: AccessDenied.'])]]),
    ]);

    $this->artisan('storage:status', ['environment' => 'production'])
        ->expectsOutputToContain('No files were copied into the bucket of production')
        ->assertSuccessful();

    $this->artisan('storage:status', ['environment' => 'production'])
        ->expectsOutputToContain('The copy stopped before it was done: AccessDenied.')
        ->assertFailed();
});

test('a copy that fails in the project says why and fails the command', function (): void {
    putenv('SOURCE_AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE');
    putenv('SOURCE_AWS_SECRET_ACCESS_KEY='.SOURCE_SECRET);
    brewlessImports([import(['status' => 'failed', 'error_message' => 'The copy stopped before it was done: Failed to copy: AccessDenied.'])]);

    $this->artisan('storage:import', ['environment' => 'production', 'source' => 's3://my-vapor-bucket', '--source-region' => 'eu-west-1', '--force' => true])
        ->expectsOutputToContain('Failed to copy: AccessDenied.')
        ->assertFailed();
});

test('--local copies through this machine with the environment\'s own key, leaves what is there alone, and tells the audit trail the totals', function (): void {
    putenv('SOURCE_AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE');
    putenv('SOURCE_AWS_SECRET_ACCESS_KEY='.SOURCE_SECRET);

    $source = 'https://my-vapor-bucket.s3.eu-west-1.amazonaws.com';
    $destination = 'https://'.DESTINATION.'.s3.fr-par.scw.cloud';
    $put = [];

    Http::fake(function (Request $request) use ($source, $destination, &$put) {
        $url = $request->url();

        return match (true) {
            $url === 'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
            str_starts_with($url, 'https://acme.brewless.eu/cli/api/environments/env1/env-file') => Http::response(['data' => ['revision' => 3, 'secret' => 'shop-production-env', 'content' => 'AWS_BUCKET='.DESTINATION."\nAWS_DEFAULT_REGION=fr-par\nAWS_ENDPOINT=https://s3.fr-par.scw.cloud\nAWS_ACCESS_KEY_ID=SCWFILESKEY000000001\nAWS_SECRET_ACCESS_KEY=".BUCKET_SECRET."\n"]]),
            $url === 'https://acme.brewless.eu/cli/api/environments/env1/storage/imports/local' => Http::response('', 204),
            // Two pages of the source.
            str_starts_with($url, $source.'/?') && ! Str::contains($url, 'continuation-token') => Http::response('<ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken>page2</NextContinuationToken><Contents><Key>uploads/a.jpg</Key><Size>5</Size></Contents><Contents><Key>uploads/</Key><Size>0</Size></Contents></ListBucketResult>'),
            str_starts_with($url, $source.'/?') => Http::response('<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>uploads/b c.pdf</Key><Size>3</Size></Contents><Contents><Key>uploads/huge.mov</Key><Size>6000000000</Size></Contents></ListBucketResult>'),
            $url === $source.'/uploads/a.jpg' => Http::response('hello', 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'max-age=60', 'x-amz-meta-owner' => '7', 'ETag' => '"x"']),
            // In the bucket already, with the same size.
            $url === $destination.'/uploads/b%20c.pdf' && $request->method() === 'HEAD' => Http::response('', 200, ['Content-Length' => '3']),
            $url === $destination.'/uploads/a.jpg' && $request->method() === 'HEAD' => Http::response('', 404),
            $url === $destination.'/uploads/a.jpg' && $request->method() === 'PUT' => (function () use ($request, &$put) {
                $put[] = $request;

                return Http::response('', 200);
            })(),
            default => Http::response('not faked: '.$request->method().' '.$url, 500),
        };
    });

    $this->artisan('storage:import', ['environment' => 'production', 'source' => 's3://my-vapor-bucket/uploads', '--source-region' => 'eu-west-1', '--local' => true, '--force' => true])
        ->expectsOutputToContain('Into  the bucket of production, from this machine')
        ->expectsOutputToContain('1 files copied (5 B); 1 were there already.')
        ->expectsOutputToContain('1 files are larger than 5 GB and were skipped')
        ->expectsOutputToContain('uploads/huge.mov')
        ->doesntExpectOutputToContain(BUCKET_SECRET)
        ->assertSuccessful();

    expect($put)->toHaveCount(1)
        ->and($put[0]->body())->toBe('hello')
        ->and($put[0]->header('Content-Type'))->toBe(['image/jpeg'])
        ->and($put[0]->header('Cache-Control'))->toBe(['max-age=60'])
        ->and($put[0]->header('x-amz-meta-owner'))->toBe(['7'])
        ->and($put[0]->header('Authorization')[0])->toStartWith('AWS4-HMAC-SHA256 Credential=SCWFILESKEY000000001/')
        ->and($put[0]->header('Authorization')[0])->toContain('/fr-par/s3/aws4_request', 'x-amz-meta-owner');

    $audit = Http::recorded(static fn (Request $request): bool => str_ends_with($request->url(), '/storage/imports/local'))->map(static fn (array $pair): array => $pair[0]->data())->values()->all();

    expect($audit)->toHaveCount(2)
        ->and($audit[0])->toMatchArray(['phase' => 'started', 'destination' => DESTINATION, 'source' => ['bucket' => 'my-vapor-bucket', 'prefix' => 'uploads', 'endpoint' => null]])
        ->and($audit[1])->toMatchArray(['phase' => 'finished', 'totals' => ['copied' => 1, 'bytes' => 5, 'checked' => 1, 'too_large' => 1, 'errors' => 0]])
        ->and(json_encode($audit))->not->toContain(SOURCE_SECRET, BUCKET_SECRET, 'AKIAIOSFODNN7EXAMPLE')
        // The source's key went to AWS only.
        ->and(Http::recorded(static fn (Request $request): bool => Str::contains($request->header('Authorization')[0] ?? '', 'AKIAIOSFODNN7EXAMPLE') && ! str_starts_with($request->url(), $source)))->toBeEmpty();
});

test('--local without a bucket in the variables says how to get one', function (): void {
    putenv('SOURCE_AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE');
    putenv('SOURCE_AWS_SECRET_ACCESS_KEY='.SOURCE_SECRET);
    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/env-file*' => Http::response(['data' => ['revision' => 1, 'secret' => 'shop-production-env', 'content' => "APP_NAME=Shop\n"]]),
    ]);

    $this->artisan('storage:import', ['environment' => 'production', 'source' => 's3://my-vapor-bucket', '--source-region' => 'eu-west-1', '--local' => true, '--force' => true])
        ->expectsOutputToContain('This environment has no bucket with a key of its own')
        ->assertFailed();
});
