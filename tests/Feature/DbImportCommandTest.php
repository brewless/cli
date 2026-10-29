<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

const DB_SOURCE_PASSWORD = 'p@ss:w"o\rd$1 source';
const DB_APP_PASSWORD = 'app-password-of-production';

/**
 * A copy of a database as Brewless answers it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dbImport(array $overrides = []): array
{
    return [
        'id' => 'op1',
        'kind' => 'database.import',
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
 * @param  list<array<string, mixed>>  $states  what GET /operations/op1 answers, one per look
 */
function brewlessDbImports(array $states = []): void
{
    $states = $states === [] ? [dbImport(['status' => 'succeeded', 'result' => ['summary' => '12 tables copied (15.3 MB at the source) in 41 seconds.']])] : $states;

    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/database/imports' => Http::response(['data' => dbImport()], 201),
        'https://acme.brewless.eu/cli/api/environments/env1/database/imports/local' => Http::response(null, 204),
        'https://acme.brewless.eu/cli/api/environments/env1/database/imports/script' => Http::response(['data' => ['script' => 'echo the import script', 'image' => 'ghcr.io/brewless/brewless-builder:php8.4@sha256:abc']]),
        'https://acme.brewless.eu/cli/api/environments/env1/env-file*' => Http::response(['data' => ['content' => "APP_NAME=Shop\nDB_CONNECTION=pgsql\nDB_HOST=0f1e2d3c.pg.sdb.fr-par.scw.cloud\nDB_PORT=5432\nDB_DATABASE=shop\nDB_USERNAME=5c1e7d2a\nDB_PASSWORD=".DB_APP_PASSWORD."\nDB_SSLMODE=require\n"]]),
        'https://acme.brewless.eu/cli/api/operations/op1' => Http::sequence(array_map(static fn (array $state) => Http::response(['data' => $state]), $states)),
    ]);
}

/**
 * Docker on this machine: whether it answers, and what a run of the import prints.
 */
function fakeDocker(bool $answers = true, string $output = '', int $exitCode = 0): void
{
    Process::fake(static function (PendingProcess $process) use ($answers, $output, $exitCode) {
        $command = is_array($process->command) ? $process->command : explode(' ', (string) $process->command);

        return match ($command[1] ?? null) {
            'version' => $answers ? Process::result('29.0.0') : Process::result('', 'Cannot connect to the Docker daemon', 1),
            'run' => Process::result($output, '', $exitCode),
            default => Process::result(),
        };
    });
}

function startedDbImport(): ?Request
{
    return Http::recorded(static fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/database/imports'))->first()[0] ?? null;
}

beforeEach(function (): void {
    signedIn();
    project();
});

afterEach(function (): void {
    foreach (['SOURCE_DB_PASSWORD', 'SOURCE_DB_HOST', 'SOURCE_DB_USERNAME', 'SOURCE_DB_DATABASE', 'SOURCE_DB_CONNECTION', 'SOURCE_DATABASE_URL', 'DB_PASSWORD', 'DB_HOST'] as $name) {
        putenv($name);
    }
});

test('a copy starts in the project with the source from the address and the password from SOURCE_DB_PASSWORD, and is followed to its end', function (): void {
    brewlessDbImports([
        dbImport(['steps' => [['name' => 'copy', 'status' => 'pending', 'progress' => ['tables_done' => 3, 'tables' => 12], 'error_message' => 'Copying: table 3 of 12.']]]),
        dbImport(['status' => 'succeeded', 'result' => ['summary' => '12 tables copied (15.3 MB at the source) in 41 seconds.']]),
    ]);
    putenv('SOURCE_DB_PASSWORD='.DB_SOURCE_PASSWORD);

    $this->artisan('db:import production mysql://brewless-import@shop-db.abc123.eu-west-1.rds.amazonaws.com/vapor')
        ->expectsOutputToContain('mysql://brewless-import@shop-db.abc123.eu-west-1.rds.amazonaws.com:3306/vapor')
        ->expectsOutputToContain('Password from SOURCE_DB_PASSWORD')
        ->expectsConfirmation('Copy this database into production?', 'yes')
        ->expectsOutputToContain('Copying: table 3 of 12.')
        ->expectsOutputToContain('12 tables copied (15.3 MB at the source) in 41 seconds.')
        ->expectsOutputToContain('The passwords of the copy were deleted from your Secret Manager.')
        ->doesntExpectOutputToContain(DB_SOURCE_PASSWORD)
        ->assertSuccessful();

    expect(startedDbImport()?->data())->toMatchArray([
        'source' => ['engine' => 'mysql', 'host' => 'shop-db.abc123.eu-west-1.rds.amazonaws.com', 'port' => 3306, 'database' => 'vapor', 'username' => 'brewless-import', 'password' => DB_SOURCE_PASSWORD, 'sslmode' => 'require'],
        'replace' => false,
        'dry_run' => false,
    ])->and(startedDbImport()?->data()['key'])->toStartWith('cli-');
});

test('the source comes from the DB_* lines of an old .env, and never from the shell\'s own DB_*', function (): void {
    brewlessDbImports();
    putenv('DB_HOST=should-not-be-used.example');
    putenv('DB_PASSWORD=not-this-one');
    file_put_contents($this->project.'/old.env', "APP_KEY=base64:x\nAWS_SECRET_ACCESS_KEY=nope\nDB_CONNECTION=pgsql\nDB_HOST=old-db.example.eu\nDB_DATABASE=shop\nDB_USERNAME=forge\nDB_PASSWORD=from-the-file\n");

    $this->artisan('db:import production --from-env=old.env --force --dry-run')
        ->expectsOutputToContain('postgres://forge@old-db.example.eu:5432/shop')
        ->expectsOutputToContain('Password from old.env')
        ->assertSuccessful();

    expect(startedDbImport()?->data())->toMatchArray([
        'source' => ['engine' => 'pgsql', 'host' => 'old-db.example.eu', 'port' => 5432, 'database' => 'shop', 'username' => 'forge', 'password' => 'from-the-file', 'sslmode' => 'require'],
        'dry_run' => true,
    ]);
});

test('a password in the address is refused before anything is sent', function (): void {
    brewlessDbImports();

    $this->artisan('db:import production mysql://vapor:secret@shop-db.example.eu/vapor --force')
        ->expectsOutputToContain('Leave the password out of the address')
        ->assertFailed();

    expect(startedDbImport())->toBeNull();
});

test('without a password in a terminal it is asked, and Vapor gets a word on where to find it', function (): void {
    brewlessDbImports();
    file_put_contents($this->project.'/vapor.yml', "id: 1\nname: shop\nenvironments:\n  production:\n    database: shop-db\n");

    $this->artisan('db:import production postgres://vapor@shop-db.example.eu/vapor')
        ->expectsOutputToContain('Vapor dashboard')
        ->expectsQuestion('Password of vapor at shop-db.example.eu', 'typed-secret')
        ->expectsConfirmation('Copy this database into production?', 'yes')
        ->assertSuccessful();

    expect(startedDbImport()?->data()['source']['password'])->toBe('typed-secret');
});

test('replacing the data of production takes its name, typed', function (): void {
    brewlessDbImports();
    putenv('SOURCE_DB_PASSWORD='.DB_SOURCE_PASSWORD);

    $this->artisan('db:import production postgres://vapor@shop-db.example.eu/vapor --replace')
        ->expectsOutputToContain('is removed first')
        ->expectsQuestion('Type production to replace the data of production', 'prod')
        ->expectsOutputToContain('Nothing was copied.')
        ->assertSuccessful();

    expect(startedDbImport())->toBeNull();

    $this->artisan('db:import production postgres://vapor@shop-db.example.eu/vapor --replace')
        ->expectsQuestion('Type production to replace the data of production', 'production')
        ->assertSuccessful();

    expect(startedDbImport()?->data()['replace'])->toBeTrue();
});

test('a source on this machine or a private network is only copied with --local', function (): void {
    brewlessDbImports();
    putenv('SOURCE_DB_PASSWORD='.DB_SOURCE_PASSWORD);

    $this->artisan('db:import production mysql://vapor@127.0.0.1:3307/vapor --force')
        ->expectsOutputToContain('Copy from here with --local')
        ->assertFailed();

    expect(startedDbImport())->toBeNull();
});

test('a copy that is refused says why', function (): void {
    brewlessDbImports([dbImport(['status' => 'failed', 'error_message' => 'The database of Production holds 8 tables already, so nothing was copied. Start the copy with --replace to remove what it holds first.'])]);
    putenv('SOURCE_DB_PASSWORD='.DB_SOURCE_PASSWORD);

    $this->artisan('db:import production postgres://vapor@shop-db.example.eu/vapor --force')
        ->expectsOutputToContain('holds 8 tables already')
        ->assertFailed();
});

test('with --local the script of Brewless runs in its image with Docker, the passwords only in its environment', function (): void {
    brewlessDbImports();
    putenv('SOURCE_DB_PASSWORD='.DB_SOURCE_PASSWORD);
    fakeDocker(output: implode("\n", [
        '{"brewless_import":"checked","method":"pgloader","source_version":"8.0","tables":4,"bytes":10371072,"destination_tables":0}',
        '{"brewless_import":"progress","tables_done":2,"tables":4}',
        '{"brewless_import":"done","method":"pgloader","dry_run":0,"tables":4,"bytes":10371072,"seconds":3}',
    ])."\n");

    $this->artisan('db:import production mysql://vapor@127.0.0.1:3307/vapor?sslmode=disable --local --force')
        ->expectsOutputToContain('4 tables copied (10.4 MB at the source) in 3 s.')
        ->doesntExpectOutputToContain(DB_SOURCE_PASSWORD)
        ->assertSuccessful();

    Process::assertRan(static function (PendingProcess $process): bool {
        $command = is_array($process->command) ? $process->command : [];

        if (($command[1] ?? null) !== 'run') {
            return false;
        }

        $line = implode(' ', $command);
        $config = json_decode((string) ($process->environment['IMPORT_CONFIG'] ?? ''), true);

        return in_array('ghcr.io/brewless/brewless-builder:php8.4@sha256:abc', $command, true)
            && end($command) === 'echo the import script'
            && ! str_contains($line, DB_SOURCE_PASSWORD) && ! str_contains($line, DB_APP_PASSWORD)
            && $config === ['source_password' => DB_SOURCE_PASSWORD, 'destination_password' => DB_APP_PASSWORD]
            && $process->environment['IMPORT_METHOD'] === 'pgloader'
            && $process->environment['SOURCE_HOST'] === (PHP_OS_FAMILY === 'Linux' ? '127.0.0.1' : 'host.docker.internal')
            && $process->environment['SOURCE_SSLMODE'] === 'disable'
            && $process->environment['DESTINATION_HOST'] === '0f1e2d3c.pg.sdb.fr-par.scw.cloud'
            && $process->environment['DESTINATION_USER'] === '5c1e7d2a';
    });

    $records = Http::recorded(static fn (Request $request): bool => str_ends_with($request->url(), '/database/imports/local'))->values();

    expect($records)->toHaveCount(2)
        ->and($records[1][0]->data())->toMatchArray([
            'phase' => 'finished',
            'source' => ['engine' => 'mysql', 'host' => '127.0.0.1', 'port' => 3307, 'database' => 'vapor'],
            'method' => 'pgloader',
            'outcome' => 'done',
            'totals' => ['tables' => 4, 'bytes' => 10371072, 'seconds' => 3],
        ])
        ->and(json_encode($records->all()))->not->toContain(DB_SOURCE_PASSWORD);
});

test('a refusal of the copy on this machine is told in words', function (): void {
    brewlessDbImports();
    putenv('SOURCE_DB_PASSWORD='.DB_SOURCE_PASSWORD);
    fakeDocker(output: '{"brewless_import":"refused","reason":"destination_not_empty","destination_tables":7}'."\n", exitCode: 3);

    $this->artisan('db:import production postgres://vapor@shop-db.example.eu/vapor --local --force')
        ->expectsOutputToContain('holds 7 tables already')
        ->assertFailed();
});

test('without Docker --local says so and starts nothing', function (): void {
    brewlessDbImports();
    putenv('SOURCE_DB_PASSWORD='.DB_SOURCE_PASSWORD);
    fakeDocker(answers: false);

    $this->artisan('db:import production postgres://vapor@shop-db.example.eu/vapor --local --force')
        ->expectsOutputToContain('Docker does not answer')
        ->assertFailed();

    Process::assertDidntRun(static fn (PendingProcess $process): bool => is_array($process->command) && ($process->command[1] ?? null) === 'run');
});

test('a dump pg_dump wrote is copied from this machine, and only that file is given to the container', function (): void {
    brewlessDbImports();
    file_put_contents($this->project.'/shop-2026-10-29.sql', "-- PostgreSQL database dump\nCREATE TABLE public.users (id bigint);\n");
    fakeDocker(output: implode("\n", [
        '{"brewless_import":"checked","method":"pgfile","source_version":"16","tables":2,"bytes":9000000,"destination_tables":0}',
        '{"brewless_import":"progress","tables_done":2,"tables":2}',
        '{"brewless_import":"done","method":"pgfile","dry_run":0,"tables":2,"bytes":9000000,"seconds":5}',
    ])."\n");

    $this->artisan('db:import production --file=shop-2026-10-29.sql --force')
        ->expectsOutputToContain('file://shop-2026-10-29.sql')
        ->expectsOutputToContain('from this machine in Docker')
        ->expectsOutputToContain('2 tables copied (9.0 MB in the dump) in 5 s.')
        ->assertSuccessful();

    Process::assertRan(static function (PendingProcess $process): bool {
        $command = is_array($process->command) ? $process->command : [];

        if (($command[1] ?? null) !== 'run') {
            return false;
        }

        $config = json_decode((string) ($process->environment['IMPORT_CONFIG'] ?? ''), true);

        return in_array('-v', $command, true)
            && in_array(getcwd().'/shop-2026-10-29.sql:/dump/shop-2026-10-29.sql:ro', $command, true)
            && $process->environment['IMPORT_METHOD'] === 'pgfile'
            && $process->environment['SOURCE_FILE'] === '/dump/shop-2026-10-29.sql'
            && ! isset($process->environment['SOURCE_HOST'])
            && $config === ['destination_password' => DB_APP_PASSWORD]
            && $process->environment['DESTINATION_HOST'] === '0f1e2d3c.pg.sdb.fr-par.scw.cloud';
    });

    $records = Http::recorded(static fn (Request $request): bool => str_ends_with($request->url(), '/database/imports/local'))->values();

    expect(startedDbImport())->toBeNull()
        ->and($records)->toHaveCount(2)
        ->and($records[1][0]->data())->toMatchArray([
            'phase' => 'finished',
            'source' => ['engine' => 'pgsql', 'file' => 'shop-2026-10-29.sql'],
            'method' => 'pgfile',
            'outcome' => 'done',
            'totals' => ['tables' => 2, 'bytes' => 9000000, 'seconds' => 5],
        ]);
});

test('a dump that is not there, is empty, or comes with a database as well, starts nothing', function (string $arguments, string $message): void {
    brewlessDbImports();
    fakeDocker();
    file_put_contents($this->project.'/shop.sql', "-- PostgreSQL database dump\n");
    file_put_contents($this->project.'/empty.sql', '');

    $this->artisan('db:import production '.$arguments.' --force')
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(startedDbImport())->toBeNull();
    Process::assertDidntRun(static fn (PendingProcess $process): bool => is_array($process->command) && ($process->command[1] ?? null) === 'run');
})->with([
    'no such file' => ['--file=nothing-here.sql', 'There is no file to copy from'],
    'an empty file' => ['--file=empty.sql', 'is empty, so there is nothing to copy'],
    'a dump and a database' => ['postgres://vapor@shop-db.example.eu/vapor --file=shop.sql', 'give --file, or the address of a database, not both'],
    'a dump and a .env' => ['--file=shop.sql --from-env=old.env', 'give --file, or the address of a database, not both'],
]);

test('db:import-status shows the last copy into the environment', function (): void {
    Http::fake([
        'https://acme.brewless.eu/cli/api/applications' => Http::response(applications()),
        'https://acme.brewless.eu/cli/api/environments/env1/operations*' => Http::response(['data' => [dbImport(['status' => 'succeeded', 'result' => ['summary' => '12 tables copied (15.3 MB at the source) in 41 seconds.']])]]),
    ]);

    $this->artisan('db:import-status production')
        ->expectsOutputToContain('Copy into the database of production')
        ->expectsOutputToContain('12 tables copied')
        ->assertSuccessful();

    expect(Http::recorded(static fn (Request $request): bool => str_contains($request->url(), 'kind=database.import')))->toHaveCount(1);
});
