<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\FollowsImports;
use App\Commands\Concerns\TalksToBrewless;
use App\Support\Api;
use App\Support\S3;
use App\Support\SourceBucket;
use Dotenv\Dotenv;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

final class StorageImportCommand extends Command
{
    use FollowsImports;
    use TalksToBrewless;

    /** How many failed files are named at the end. */
    private const int NAMED_FAILURES = 5;

    protected $signature = 'storage:import {environment : The environment whose bucket the files go into}
        {source? : The bucket to copy from, as s3://<bucket> or s3://<bucket>/<folder>}
        {--from-env= : Read the source\'s AWS_* values from this .env file of the old application}
        {--source-profile= : Take the key and region of this profile of the AWS CLI}
        {--source-region= : The region of the source bucket; looked up at AWS when left out}
        {--source-endpoint= : The endpoint of an S3 service other than AWS}
        {--source-path-style : Address the source bucket in the path instead of the host name}
        {--local : Copy from this machine instead of a job in your Scaleway project}
        {--dry-run : Copy nothing; count what the source holds}
        {--no-follow : Start the copy in your project and return}
        {--force : Do not ask for confirmation}';

    protected $description = 'Copy the files of an S3 bucket (AWS or another) into an environment\'s bucket';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $source = $this->withWhatIsMissing(SourceBucket::gather($this->argument('source'), [
                'from-env' => $this->option('from-env'),
                'source-profile' => $this->option('source-profile'),
                'source-region' => $this->option('source-region'),
                'source-endpoint' => $this->option('source-endpoint'),
                'source-path-style' => (bool) $this->option('source-path-style'),
            ]));
            $dryRun = (bool) $this->option('dry-run');
            $local = (bool) $this->option('local');

            $this->newLine();
            $this->line('  From  <options=bold>'.$source->location().'</>'.($source->isAws() ? ' at AWS, '.$source->region : ' on '.$source->endpoint.', '.$source->region));
            $this->line('  Into  the bucket of <options=bold>'.$slug.'</>'.($local ? ', from this machine' : ', by a job in your Scaleway project'));
            $this->line('  Key   from '.$source->keysFrom);
            $this->newLine();

            if ($dryRun) {
                $this->line('  A dry run: nothing is copied.');
            } else {
                $this->line('  Nothing is removed, at the source or in the bucket. A file that is there already is left alone.');

                if ($source->isAws()) {
                    $this->line('  AWS bills the data that leaves it.');
                }

                if (! $this->option('force') && ! $this->confirm('Copy these files into the bucket of '.$slug.'?', true)) {
                    return self::SUCCESS;
                }
            }

            return $local
                ? $this->copyHere($api, $environment, $source, $dryRun)
                : $this->copyInProject($api, $environment, $slug, $source, $dryRun);
        });
    }

    /**
     * Ask for what is missing: the key in a terminal, the region at AWS.
     */
    private function withWhatIsMissing(SourceBucket $source): SourceBucket
    {
        if (! $source->hasKeys()) {
            if (! $this->input->isInteractive()) {
                throw new RuntimeException('No key for '.$source->location().'. Set SOURCE_AWS_ACCESS_KEY_ID and SOURCE_AWS_SECRET_ACCESS_KEY, or pass --source-profile or --from-env.');
            }

            $source = $source->withKeys(
                text('Access key that may read '.$source->bucket, required: true),
                password('Its secret key', required: true),
                'what you typed',
            );
        }

        $source->checkKeys();

        if ($source->region === null) {
            $region = $source->isAws() ? S3::regionOf($source->bucket) : null;

            if ($region === null) {
                throw new RuntimeException($source->isAws()
                    ? 'AWS did not say which region '.$source->bucket.' is in. Pass it with --source-region.'
                    : 'Give the region the S3 service expects with --source-region, such as ams3 or auto.');
            }

            $source = $source->withRegion($region);
        }

        return $source;
    }

    /**
     * Start the copy in the customer's project and follow it.
     *
     * @param  array<string, mixed>  $environment
     */
    private function copyInProject(Api $api, array $environment, string $slug, SourceBucket $source, bool $dryRun): int
    {
        $operation = $api->post('/api/environments/'.$environment['id'].'/storage/imports', [
            'key' => 'cli-'.Str::lower(Str::random(24)),
            'source' => [
                'bucket' => $source->bucket,
                'prefix' => $source->prefix,
                'region' => $source->region,
                'endpoint' => $source->endpoint,
                'path_style' => $source->pathStyle,
                'access_key' => $source->accessKey,
                'secret_key' => $source->secretKey,
            ],
            'dry_run' => $dryRun,
        ])['data'] ?? [];

        $this->components->info('The copy started in your Scaleway project.');

        if ($this->option('no-follow')) {
            $this->line('  Follow it with: brewless storage:status '.$slug);

            return self::SUCCESS;
        }

        $this->line('  It goes on when you stop following it (Ctrl+C); look again with: brewless storage:status '.$slug);
        $this->newLine();

        return $this->followImport($api, $operation, $slug) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Copy every file through this machine, with the environment's own key
     * for its bucket. The source's key never leaves this machine.
     *
     * @param  array<string, mixed>  $environment
     */
    private function copyHere(Api $api, array $environment, SourceBucket $source, bool $dryRun): int
    {
        $file = $api->get('/api/environments/'.$environment['id'].'/env-file', ['via' => 'cli'])['data'] ?? [];
        $variables = Dotenv::parse(is_string($file['content'] ?? null) ? $file['content'] : '');
        $bucket = (string) ($variables['AWS_BUCKET'] ?? '');

        if ($bucket === '' || ($variables['AWS_ACCESS_KEY_ID'] ?? '') === '' || ($variables['AWS_SECRET_ACCESS_KEY'] ?? '') === '') {
            throw new RuntimeException('This environment has no bucket with a key of its own in its variables (AWS_BUCKET, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY). Give it one with storage in brewless.yml or brewless provision --bucket.');
        }

        $endpoint = (string) ($variables['AWS_ENDPOINT'] ?? '');
        $region = (string) ($variables['AWS_DEFAULT_REGION'] ?? 'fr-par');
        $destination = new S3(
            $endpoint === '' ? 'https://s3.'.$region.'.amazonaws.com' : $endpoint,
            $region,
            (string) $variables['AWS_ACCESS_KEY_ID'],
            (string) $variables['AWS_SECRET_ACCESS_KEY'],
            in_array(strtolower((string) ($variables['AWS_USE_PATH_STYLE_ENDPOINT'] ?? '')), ['1', 'true'], true),
        );
        $from = $source->isAws()
            ? S3::aws((string) $source->region, (string) $source->accessKey, (string) $source->secretKey)
            : new S3((string) $source->endpoint, (string) $source->region, (string) $source->accessKey, (string) $source->secretKey, $source->pathStyle);

        if ($source->bucket === $bucket && $source->endpoint !== null && str_contains($source->endpoint, (string) parse_url($endpoint, PHP_URL_HOST))) {
            throw new RuntimeException('That is the bucket the files would be copied into. Name the bucket the files are in now.');
        }

        $described = ['bucket' => $source->bucket, 'prefix' => $source->prefix, 'endpoint' => $source->endpoint];
        $this->record($api, $environment, 'started', $described, $bucket, $dryRun);

        $totals = ['copied' => 0, 'bytes' => 0, 'checked' => 0, 'too_large' => 0, 'errors' => 0];
        $tooLarge = [];
        $failures = [];
        $page = null;
        $temporary = (string) tempnam(sys_get_temp_dir(), 'brewless-import-');

        try {
            do {
                $listed = $from->list($source->bucket, $source->prefix, $page);

                foreach ($listed['objects'] as $object) {
                    if ($object['size'] > S3::LARGEST_PUT) {
                        $totals['too_large']++;
                        $tooLarge[] = $object['key'];

                        continue;
                    }

                    try {
                        if ($destination->size($bucket, $object['key']) === $object['size']) {
                            $totals['checked']++;
                        } elseif ($dryRun) {
                            $totals['copied']++;
                            $totals['bytes'] += $object['size'];
                        } else {
                            $headers = $from->download($source->bucket, $object['key'], $temporary);
                            $destination->upload($bucket, $object['key'], $temporary, $headers);
                            $totals['copied']++;
                            $totals['bytes'] += $object['size'];
                        }
                    } catch (RuntimeException $exception) {
                        $totals['errors']++;
                        $failures[] = $exception->getMessage();
                    }

                    $this->localProgress($totals, $dryRun);
                }

                $page = $listed['next'];
            } while ($page !== null);
        } catch (Throwable $exception) {
            $this->record($api, $environment, 'finished', $described, $bucket, $dryRun, $totals);

            throw $exception;
        } finally {
            @unlink($temporary);
        }

        $this->localProgress($totals, $dryRun, done: true);
        $this->record($api, $environment, 'finished', $described, $bucket, $dryRun, $totals);

        $this->newLine();
        $this->components->info($dryRun
            ? number_format($totals['copied']).' files ('.$this->size($totals['bytes']).') would be copied; '.number_format($totals['checked']).' are there already.'
            : number_format($totals['copied']).' files copied ('.$this->size($totals['bytes']).'); '.number_format($totals['checked']).' were there already.');

        if ($tooLarge !== []) {
            $this->components->warn(count($tooLarge).' files are larger than 5 GB and were skipped. Copy them without --local, in your Scaleway project:');

            foreach (array_slice($tooLarge, 0, self::NAMED_FAILURES) as $key) {
                $this->line('  - '.$key);
            }
        }

        if ($failures !== []) {
            $this->components->error(count($failures).' files could not be copied. Run the import again to try them again:');

            foreach (array_slice($failures, 0, self::NAMED_FAILURES) as $message) {
                $this->line('  - '.$message);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{copied: int, bytes: int, checked: int, too_large: int, errors: int}  $totals
     */
    private function localProgress(array $totals, bool $dryRun, bool $done = false): void
    {
        $line = '  '.number_format($totals['copied']).($dryRun ? ' to copy' : ' copied').' ('.$this->size($totals['bytes']).'), '.number_format($totals['checked']).' there already'.($totals['errors'] > 0 ? ', '.$totals['errors'].' failed' : '');

        if ($this->live()) {
            $this->output->write("\r\033[2K".$line.($done ? "\n" : ''));

            return;
        }

        $seen = $totals['copied'] + $totals['checked'] + $totals['errors'];

        if ($done || $seen % 100 === 0) {
            $this->line($line);
        }
    }

    /**
     * Tell the audit trail about a copy on this machine; never a key. A
     * Brewless that does not know the route yet does not stop the copy.
     *
     * @param  array<string, mixed>  $environment
     * @param  array<string, string|null>  $source
     * @param  array<string, int>  $totals
     */
    private function record(Api $api, array $environment, string $phase, array $source, string $destination, bool $dryRun, array $totals = []): void
    {
        try {
            $api->post('/api/environments/'.$environment['id'].'/storage/imports/local', [
                'phase' => $phase,
                'source' => $source,
                'destination' => $destination,
                'dry_run' => $dryRun,
                ...$totals === [] ? [] : ['totals' => $totals],
            ]);
        } catch (RuntimeException) {
            // Only the record of it; the copy is the customer's own.
        }
    }
}
