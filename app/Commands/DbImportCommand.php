<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\FollowsImports;
use App\Commands\Concerns\TalksToBrewless;
use App\Support\Api;
use App\Support\SourceDatabase;
use App\Support\Vapor;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

final class DbImportCommand extends Command
{
    use FollowsImports;
    use TalksToBrewless;

    /** What the script says when it refuses, before it wrote anything. */
    private const array REFUSALS = [
        'destination_not_empty' => 'The database of :environment holds :tables tables already, so nothing was copied. Copy again with --replace to remove what it holds first.',
        'routines_need_trust' => 'The source has triggers or routines, and the destination only takes those from a user with SUPER while its binary log is on. Set log_bin_trust_function_creators to 1 in the settings of the Managed Database, then copy again. Nothing was written.',
        'source_authentication' => 'pgloader could not sign in to the source: the server asks for a way of signing in it does not know. Give the copy a user with mysql_native_password. Nothing was written.',
        'client_too_old' => 'The source runs a PostgreSQL newer than the pg_dump of the builder image. Nothing was written.',
        'tool_missing' => 'The builder image lacks a tool the copy needs. Update brewless/cli and try again. Nothing was written.',
    ];

    protected $signature = 'db:import {environment : The environment whose database the data goes into}
        {source? : The database to copy from, as postgres://<user>@<host>[:<port>]/<database> or mysql://…, without a password}
        {--from-env= : Read the source\'s DB_* values from this .env file of the old application}
        {--replace : Remove what the destination holds first}
        {--local : Copy from this machine, in Docker, instead of a job in your Scaleway project}
        {--dry-run : Copy nothing; sign in to both databases and count what they hold}
        {--no-follow : Start the copy in your project and return}
        {--force : Do not ask for confirmation}';

    protected $description = 'Copy a PostgreSQL or MySQL database into an environment\'s database';

    /**
     * @return array{status: string, deleted: string, cancelled: string}
     */
    protected function importWords(): array
    {
        return [
            'status' => 'db:import-status',
            'deleted' => 'The passwords of the copy were deleted from your Secret Manager.',
            'cancelled' => 'The copy was cancelled and its job stopped.',
        ];
    }

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $source = $this->withPassword(SourceDatabase::gather($this->argument('source'), $this->option('from-env')));
            $source->check();

            $local = (bool) $this->option('local');
            $dryRun = (bool) $this->option('dry-run');
            $replace = (bool) $this->option('replace');

            if (! $local && $source->isPrivate()) {
                throw new RuntimeException($source->host.' is this machine or a private network, which a job in your Scaleway project cannot reach. Copy from here with --local.');
            }

            $this->newLine();
            $this->line('  From  <options=bold>'.$source->location().'</> ('.$source->name().')');
            $this->line('  Into  the database of <options=bold>'.$slug.'</>'.($local ? ', from this machine in Docker' : ', by a job in your Scaleway project'));

            if ($source->passwordFrom !== null) {
                $this->line('  Password from '.$source->passwordFrom);
            }

            $this->newLine();

            if ($dryRun) {
                $this->line('  A dry run: nothing is copied.');
            } elseif (! $this->confirmed($slug, $replace)) {
                return self::SUCCESS;
            }

            return $local
                ? $this->copyHere($api, $environment, $slug, $source, $replace, $dryRun)
                : $this->copyInProject($api, $environment, $slug, $source, $replace, $dryRun);
        });
    }

    /**
     * Ask for the password when it was not given, in a terminal only.
     */
    private function withPassword(SourceDatabase $source): SourceDatabase
    {
        if ($source->password !== null) {
            return $source;
        }

        if (! $this->input->isInteractive()) {
            throw new RuntimeException('No password for '.$source->username.'. Set SOURCE_DB_PASSWORD, or pass --from-env.'.$this->vaporHint());
        }

        $hint = $this->vaporHint();

        if ($hint !== '') {
            $this->line(' '.$hint);
        }

        return $source->withPassword(password('Password of '.$source->username.' at '.$source->host, required: true), 'what you typed');
    }

    /**
     * On Vapor the DB_* values are not in `vapor env:pull`: say where they are.
     */
    private function vaporHint(): string
    {
        return Vapor::read((string) getcwd()) instanceof Vapor
            ? ' On Vapor the database\'s password is not in `vapor env:pull`: it is on the database\'s page in the Vapor dashboard, or make a user for the move with `vapor database:user`.'
            : '';
    }

    /**
     * Say what happens and ask. Replacing the data of production takes its name, typed.
     */
    private function confirmed(string $slug, bool $replace): bool
    {
        $this->line('  Nothing changes at the source.');
        $this->line($replace
            ? '  <fg=yellow>What the database of '.$slug.' holds now is removed first.</>'
            : '  The database of '.$slug.' has to be empty: a copy into one with tables stops before it writes anything.');

        if ($this->option('force')) {
            return true;
        }

        if ($replace && $slug === 'production') {
            if (text('Type production to replace the data of production', required: true) !== 'production') {
                $this->components->warn('Nothing was copied.');

                return false;
            }

            return true;
        }

        return $this->confirm('Copy this database into '.$slug.'?', true);
    }

    /**
     * Start the copy in the customer's project and follow it.
     *
     * @param  array<string, mixed>  $environment
     */
    private function copyInProject(Api $api, array $environment, string $slug, SourceDatabase $source, bool $replace, bool $dryRun): int
    {
        $operation = $api->post('/api/environments/'.$environment['id'].'/database/imports', [
            'key' => 'cli-'.Str::lower(Str::random(24)),
            'source' => $source->forBrewless(),
            'replace' => $replace,
            'dry_run' => $dryRun,
        ])['data'] ?? [];

        $this->components->info('The copy started in your Scaleway project.');

        if ($this->option('no-follow')) {
            $this->line('  Follow it with: brewless db:import-status '.$slug);

            return self::SUCCESS;
        }

        $this->line('  It goes on when you stop following it (Ctrl+C); look again with: brewless db:import-status '.$slug);
        $this->newLine();

        return $this->followImport($api, $operation, $slug) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Run the same script as the job, in the same image, with Docker on this
     * machine. The passwords stay here; only the start and the totals are
     * written to the audit trail.
     *
     * @param  array<string, mixed>  $environment
     */
    private function copyHere(Api $api, array $environment, string $slug, SourceDatabase $source, bool $replace, bool $dryRun): int
    {
        if (Process::run(['docker', 'version', '--format', '{{.Server.Version}}'])->failed()) {
            throw new RuntimeException('--local copies with Docker on this machine, and Docker does not answer. Start Docker and try again.');
        }

        $runner = $api->get('/api/environments/'.$environment['id'].'/database/imports/script')['data'] ?? [];
        $script = is_string($runner['script'] ?? null) ? $runner['script'] : '';
        $image = is_string($runner['image'] ?? null) ? $runner['image'] : '';

        if ($script === '' || $image === '' || str_contains($image, '{registry}')) {
            throw new RuntimeException('Brewless did not give the script and image of a copy. Copy without --local, in your Scaleway project.');
        }

        $destination = $this->destination($api, $environment, $slug);
        $method = match ([$source->engine, $destination['engine']]) {
            [SourceDatabase::POSTGRES, SourceDatabase::POSTGRES] => 'pg',
            [SourceDatabase::MYSQL, SourceDatabase::MYSQL] => 'mysql',
            [SourceDatabase::MYSQL, SourceDatabase::POSTGRES] => 'pgloader',
            default => throw new RuntimeException('A PostgreSQL database cannot be copied into a MySQL database. Copy it into a PostgreSQL database.'),
        };

        $linux = PHP_OS_FAMILY === 'Linux';
        // A tunnel on this machine is reached from Docker Desktop's containers at host.docker.internal.
        $sourceHost = $source->isThisMachine() && ! $linux ? 'host.docker.internal' : $source->host;
        $destinationHost = in_array($destination['host'], ['localhost', '127.0.0.1', '::1'], true) && ! $linux ? 'host.docker.internal' : $destination['host'];

        $variables = [
            'IMPORT_METHOD' => $method,
            'IMPORT_REPLACE' => $replace ? '1' : '0',
            'IMPORT_DRY_RUN' => $dryRun ? '1' : '0',
            'SOURCE_HOST' => $sourceHost,
            'SOURCE_PORT' => (string) $source->port,
            'SOURCE_DATABASE' => $source->database,
            'SOURCE_USER' => $source->username,
            'SOURCE_SSLMODE' => $source->sslmode,
            'DESTINATION_HOST' => $destinationHost,
            'DESTINATION_PORT' => (string) $destination['port'],
            'DESTINATION_DATABASE' => $destination['database'],
            'DESTINATION_USER' => $destination['username'],
            'DESTINATION_SSLMODE' => $destination['sslmode'],
            'IMPORT_CONFIG' => (string) json_encode(['source_password' => $source->password, 'destination_password' => $destination['password']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        // The values go through Docker's own environment, by name only: never on a command line.
        $command = ['docker', 'run', '--rm', '--platform', 'linux/amd64', ...($linux ? ['--network', 'host'] : [])];

        foreach (array_keys($variables) as $name) {
            $command[] = '-e';
            $command[] = $name;
        }

        $command = [...$command, $image, 'bash', '-c', $script];

        $this->record($api, $environment, 'started', $source, $method, $replace, $dryRun);
        $this->line('  Starting the copy in Docker; the image is fetched the first time.');

        $buffer = '';
        // As it comes: how far the copy is.
        $result = Process::forever()->env($variables)->run($command, function (string $type, string $output) use (&$buffer): void {
            if ($type !== 'out') {
                return;
            }

            $buffer .= $output;

            while (($end = strpos($buffer, "\n")) !== false) {
                $line = $this->said(substr($buffer, 0, $end));
                $buffer = substr($buffer, $end + 1);

                if (($line['brewless_import'] ?? null) === 'progress') {
                    $this->progress((int) ($line['tables_done'] ?? 0), (int) ($line['tables'] ?? 0));
                }
            }
        });

        // Afterwards, from all it said: what it found and how it ended.
        $outcome = null;
        $checked = null;

        foreach (explode("\n", $result->output()) as $text) {
            $line = $this->said($text);
            $kind = $line['brewless_import'] ?? null;

            if ($kind === 'checked') {
                $checked = $line;
            } elseif (in_array($kind, ['done', 'refused', 'error'], true)) {
                $outcome = $line;
            }
        }

        if ($this->live()) {
            $this->output->write("\r\033[2K");
        }

        $kind = is_array($outcome) ? (string) ($outcome['brewless_import'] ?? '') : '';
        $this->record($api, $environment, 'finished', $source, $method, $replace, $dryRun, in_array($kind, ['done', 'refused', 'error'], true) ? $kind : 'error', [
            'tables' => (int) ($outcome['tables'] ?? $checked['tables'] ?? 0),
            'bytes' => (int) ($outcome['bytes'] ?? $checked['bytes'] ?? 0),
            'seconds' => (int) ($outcome['seconds'] ?? 0),
        ]);

        return $this->ended($slug, $method, $dryRun, $result->successful() && $kind === 'done', $outcome, $checked);
    }

    /**
     * The environment's own database as its application signs in to it.
     *
     * @param  array<string, mixed>  $environment
     * @return array{engine: string, host: string, port: int, database: string, username: string, password: string, sslmode: string}
     */
    private function destination(Api $api, array $environment, string $slug): array
    {
        $file = $api->get('/api/environments/'.$environment['id'].'/env-file', ['via' => 'cli'])['data'] ?? [];
        $variables = Dotenv::parse(is_string($file['content'] ?? null) ? $file['content'] : '');
        $engine = match ((string) ($variables['DB_CONNECTION'] ?? '')) {
            'pgsql' => SourceDatabase::POSTGRES,
            'mysql', 'mariadb' => SourceDatabase::MYSQL,
            default => null,
        };
        $port = (string) ($variables['DB_PORT'] ?? '');
        $sslmode = (string) ($variables['DB_SSLMODE'] ?? '');

        if ($engine === null || ($variables['DB_HOST'] ?? '') === '' || ($variables['DB_DATABASE'] ?? '') === '' || ($variables['DB_USERNAME'] ?? '') === '') {
            throw new RuntimeException('The variables of '.$slug.' have no DB_CONNECTION, DB_HOST, DB_DATABASE and DB_USERNAME, so there is no database to copy into. Give the environment a database first.');
        }

        return [
            'engine' => $engine,
            'host' => (string) $variables['DB_HOST'],
            'port' => ctype_digit($port) ? (int) $port : ($engine === SourceDatabase::POSTGRES ? 5432 : 3306),
            'database' => (string) $variables['DB_DATABASE'],
            'username' => (string) $variables['DB_USERNAME'],
            'password' => (string) ($variables['DB_PASSWORD'] ?? ''),
            'sslmode' => in_array($sslmode, SourceDatabase::SSL_MODES, true) ? $sslmode : 'require',
        ];
    }

    /**
     * A line of the script as what it says, or null when it is not one of its own.
     *
     * @return array<array-key, mixed>|null
     */
    private function said(string $text): ?array
    {
        $line = json_decode(trim($text), true);

        return is_array($line) && is_string($line['brewless_import'] ?? null) ? $line : null;
    }

    private function progress(int $done, int $tables): void
    {
        $line = '  Copying: table '.number_format($done).' of '.number_format($tables);
        $this->live() ? $this->output->write("\r\033[2K".$line) : $this->line($line);
    }

    /**
     * Say how a copy on this machine ended. Success when it did.
     *
     * @param  array<array-key, mixed>|null  $outcome
     * @param  array<array-key, mixed>|null  $checked
     */
    private function ended(string $slug, string $method, bool $dryRun, bool $done, ?array $outcome, ?array $checked): int
    {
        $tables = number_format((int) ($outcome['tables'] ?? 0));
        $size = $this->size((int) ($outcome['bytes'] ?? 0));

        if ($done) {
            $this->components->info($dryRun
                ? 'The source holds '.$tables.' tables ('.$size.') and both databases answered; the destination holds '.number_format((int) ($checked['destination_tables'] ?? 0)).' tables. Nothing was copied.'
                : $tables.' tables copied ('.$size.' at the source) in '.$this->duration((int) ($outcome['seconds'] ?? 0)).'.');

            return self::SUCCESS;
        }

        $kind = $outcome['brewless_import'] ?? null;
        $reason = is_string($outcome['reason'] ?? null) ? $outcome['reason'] : '';

        if ($kind === 'refused' && isset(self::REFUSALS[$reason])) {
            $this->components->error(strtr(self::REFUSALS[$reason], [':environment' => $slug, ':tables' => number_format((int) ($outcome['destination_tables'] ?? 0))]));

            return self::FAILURE;
        }

        $message = is_string($outcome['message'] ?? null) ? $outcome['message'] : '';

        if ($kind === 'error' && $message !== '') {
            $side = ($outcome['side'] ?? null) === 'destination' ? 'the destination' : 'the source';
            $this->components->error('The copy stopped at '.$side.': '.$message.($method === 'pg'
                ? '. The copy is one transaction, so the database is as it was.'
                : '. What was copied so far stays; once that is fixed, copy again with --replace.'));

            return self::FAILURE;
        }

        $this->components->error('The copy in Docker stopped before it said how it ended. Is the image reachable, and Docker able to reach both databases?');

        return self::FAILURE;
    }

    /**
     * Tell the audit trail about a copy on this machine; never a password.
     * A Brewless that does not know the route yet does not stop the copy.
     *
     * @param  array<string, int>  $totals
     * @param  array<string, mixed>  $environment
     */
    private function record(Api $api, array $environment, string $phase, SourceDatabase $source, string $method, bool $replace, bool $dryRun, ?string $outcome = null, array $totals = []): void
    {
        try {
            $api->post('/api/environments/'.$environment['id'].'/database/imports/local', [
                'phase' => $phase,
                'source' => $source->described(),
                'method' => $method,
                'replace' => $replace,
                'dry_run' => $dryRun,
                ...$outcome === null ? [] : ['outcome' => $outcome],
                ...$totals === [] ? [] : ['totals' => $totals],
            ]);
        } catch (RuntimeException) {
            // Only the record of it; the copy is the customer's own.
        }
    }
}
