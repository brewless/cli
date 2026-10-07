<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\Api;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * What `init` and `provision` share: asking what a new environment should
 * have, saying it back in one line, and having Brewless make it.
 *
 * A plan is what the api takes: `database` (new, existing or none),
 * `database_resource` (which existing one), `queue` (database, sqs or none)
 * and `bucket`. `database_name` is only there to say the plan back.
 */
trait SetsEnvironmentsUp
{
    private const array DATABASES = ['new', 'existing', 'none'];

    private const array QUEUES = ['database', 'sqs', 'none'];

    private const array SETUP_STEPS = [
        'registry' => 'Registry for your images',
        'namespace' => 'Namespace for the containers',
        'database' => 'Database',
        'queue' => 'Queue',
        'bucket' => 'Bucket for files',
        'containers' => 'Web, release and worker container',
        'edge' => 'Edge in front, when bunny.net is connected',
        'variables' => 'First variables in your Secret Manager',
        'record' => 'Noted as resources of the environment',
    ];

    /**
     * Whether one of the options that say what to make was given.
     */
    protected function setupWasGiven(): bool
    {
        return $this->option('database') !== null || $this->option('database-resource') !== null || $this->option('queue') !== null || $this->option('bucket') === true
            || ($this->hasOption('no-database') && $this->option('no-database') === true);
    }

    /**
     * The plan the options describe. What is left out is the least: a new
     * database, its jobs in that database, no bucket.
     *
     * @param  array<string, mixed>  $environment
     * @return array{database: string, database_resource: string|null, database_name: string|null, queue: string, bucket: bool}
     */
    protected function setupFromOptions(Api $api, array $environment): array
    {
        $database = $this->hasOption('no-database') && $this->option('no-database') === true ? 'none' : ($this->option('database') ?? ($this->option('database-resource') === null ? 'new' : 'existing'));
        $queue = $this->option('queue') ?? ($database === 'none' ? 'none' : 'database');

        if (! in_array($database, self::DATABASES, true)) {
            throw new RuntimeException('Unknown database choice "'.$database.'". Use one of: '.implode(', ', self::DATABASES).'.');
        }

        if (! in_array($queue, self::QUEUES, true)) {
            throw new RuntimeException('Unknown queue "'.$queue.'". Use one of: '.implode(', ', self::QUEUES).'.');
        }

        if ($queue === 'database' && $database === 'none') {
            throw new RuntimeException('A queue in the database needs a database. Choose a database, or --queue=sqs or --queue=none.');
        }

        $resource = null;

        if ($database === 'existing') {
            $wanted = $this->option('database-resource');
            $resource = is_string($wanted) ? $this->existingDatabase($api, $environment, $wanted) : throw new RuntimeException('Say which database with --database-resource=<name>.');
        }

        return [
            'database' => (string) $database,
            'database_resource' => $resource['key'] ?? null,
            'database_name' => $resource['name'] ?? null,
            'queue' => (string) $queue,
            'bucket' => $this->option('bucket') === true,
        ];
    }

    /**
     * Ask what the environment should have. Null when the organisation's
     * Scaleway project cannot be read, which is said here.
     *
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>|null  $before  what was chosen for the environment before this one
     * @return array{database: string, database_resource: string|null, database_name: string|null, queue: string, bucket: bool}|null
     */
    protected function askSetup(Api $api, array $environment, ?array $before = null): ?array
    {
        $name = (string) ($environment['slug'] ?? '');
        $found = $api->get('/api/environments/'.$environment['id'].'/databases');
        $unavailable = (array) ($found['unavailable'] ?? []);

        if ($unavailable !== []) {
            $this->components->warn(match (reset($unavailable)) {
                'not_connected' => 'Scaleway is not connected to this organisation yet. Connect it on the Providers page of the console, then run: brewless provision '.$name,
                'key_rejected' => 'Scaleway no longer accepts the key Brewless has. Replace it on the Providers page of the console, then run: brewless provision '.$name,
                default => 'Scaleway could not be read just now. Try again in a moment with: brewless provision '.$name,
            });

            return null;
        }

        if ($before !== null && confirm('Set '.$name.' up the same way ('.$this->describeSetup($before).')?')) {
            return $before;
        }

        $existing = [];

        foreach ($found['data'] ?? [] as $database) {
            $existing[(string) $database['key']] = $database;
        }

        $options = ['new' => 'A new Serverless SQL Database, which sleeps while nobody uses it'];

        foreach ($existing as $key => $database) {
            $options[$key] = 'Existing: '.$database['name'].' ('.(($database['kind'] ?? '') === 'database' ? 'Managed Database' : 'Serverless SQL Database').(($database['in_use'] ?? false) === true ? ', used by another environment' : '').')';
        }

        $database = (string) select(label: 'Which database should '.$name.' use?', options: $options + ['none' => 'No database'], default: 'new');

        $queues = ($database === 'none' ? [] : ['database' => 'In the database: nothing extra to make'])
            + ['sqs' => 'A queue at Scaleway Queues (SQS)', 'none' => 'No queue: a job runs during the request'];

        $queue = (string) select(label: 'Where should the jobs of '.$name.' wait?', options: $queues, default: array_key_first($queues));
        $bucket = confirm('A private bucket at Scaleway Object Storage for the files of '.$name.'? A container keeps no files of its own.');

        return [
            'database' => isset($existing[$database]) ? 'existing' : $database,
            'database_resource' => isset($existing[$database]) ? $database : null,
            'database_name' => isset($existing[$database]) ? (string) $existing[$database]['name'] : null,
            'queue' => $queue,
            'bucket' => $bucket,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function describeSetup(array $plan): string
    {
        return implode(', ', [
            'database: '.match ($plan['database']) {
                'existing' => 'the existing '.($plan['database_name'] ?? $plan['database_resource']),
                'new' => 'a new one',
                default => 'none',
            },
            'queue: '.match ($plan['queue']) {
                'database' => 'in the database',
                'sqs' => 'Scaleway Queues',
                default => 'none',
            },
            'bucket: '.($plan['bucket'] ? 'yes' : 'no'),
        ]);
    }

    /**
     * The options that describe a plan, for saying how to do it later.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function setupAsOptions(array $plan): string
    {
        return implode(' ', array_filter([
            '--database='.$plan['database'],
            $plan['database'] === 'existing' ? '--database-resource='.escapeshellarg((string) ($plan['database_name'] ?? $plan['database_resource'])) : null,
            '--queue='.$plan['queue'],
            $plan['bucket'] ? '--bucket' : null,
        ]));
    }

    /**
     * Have Brewless make what the plan says, in the customer's own accounts,
     * and print each step as it finishes.
     *
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $plan
     */
    protected function setUp(Api $api, array $environment, array $plan): bool
    {
        $name = (string) ($environment['slug'] ?? '');

        $operation = $api->post('/api/environments/'.$environment['id'].'/provision', [
            'database' => $plan['database'],
            'database_resource' => $plan['database_resource'],
            'queue' => $plan['queue'],
            'bucket' => $plan['bucket'],
            'key' => 'cli-'.Str::lower(Str::random(24)),
        ])['data'] ?? [];

        // A step that has nothing to do for this plan is not worth a line.
        $silent = array_filter(['database' => $plan['database'] === 'none', 'queue' => $plan['queue'] !== 'sqs', 'bucket' => ! $plan['bucket']]);
        $shown = [];

        // A namespace and a database take a moment to be made; each step is printed as it finishes.
        while (true) {
            foreach ($operation['steps'] ?? [] as $step) {
                $stepName = (string) ($step['name'] ?? '');

                if (($step['status'] ?? '') === 'succeeded' && ! in_array($stepName, $shown, true)) {
                    $shown[] = $stepName;

                    if (! isset($silent[$stepName])) {
                        $this->line('  '.(self::SETUP_STEPS[$stepName] ?? $stepName));
                    }
                }
            }

            if (in_array($operation['status'] ?? '', ['succeeded', 'failed', 'cancelled'], true)) {
                break;
            }

            Sleep::for(3)->seconds();

            $operation = $api->get('/api/operations/'.$operation['id'])['data'] ?? $operation;
        }

        if (($operation['status'] ?? '') !== 'succeeded') {
            $this->components->error($operation['error_message'] ?? 'The environment '.$name.' was not set up.');

            return false;
        }

        $result = $operation['result'] ?? [];

        $this->components->info($name.' is set up. After your first deploy it answers on '.($result['address'] ?? 'its container').'.');

        if (($result['variables_to_set'] ?? []) !== []) {
            $this->components->warn('Before you deploy '.$name.', give these a value with `brewless env:pull` and `brewless env:push`: '.implode(', ', $result['variables_to_set']).'.');
        }

        return true;
    }

    /**
     * One of the project's databases, by its name or by its key.
     *
     * @param  array<string, mixed>  $environment
     * @return array<string, mixed>
     */
    private function existingDatabase(Api $api, array $environment, string $wanted): array
    {
        $databases = $api->get('/api/environments/'.$environment['id'].'/databases')['data'] ?? [];

        foreach ($databases as $database) {
            if (in_array($wanted, [$database['key'] ?? null, $database['name'] ?? null], true)) {
                return $database;
            }
        }

        throw new RuntimeException('Your Scaleway project has no database "'.$wanted.'" in the region of this environment.'.($databases === [] ? '' : ' It has: '.implode(', ', array_column($databases, 'name')).'.'));
    }
}
