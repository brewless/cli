<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\FollowsImports;
use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

final class StorageStatusCommand extends Command
{
    use FollowsImports;
    use TalksToBrewless;

    protected $signature = 'storage:status {environment : The environment whose bucket the copy goes into}';

    protected $description = 'Show the last copy of files into an environment\'s bucket';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $operation = $api->get('/api/environments/'.$environment['id'].'/operations', ['kind' => 'storage.import'])['data'][0] ?? null;

            if (! is_array($operation)) {
                $this->components->info('No files were copied into the bucket of '.$slug.' in your Scaleway project yet.');
                $this->line('  A copy with --local is written to the audit trail only.');

                return self::SUCCESS;
            }

            $this->newLine();
            $this->line('  Copy into the bucket of <options=bold>'.$slug.'</>, started '.($operation['created_at'] ?? '').(is_string($operation['actor_name'] ?? null) ? ' by '.$operation['actor_name'] : ''));

            if (in_array($operation['status'] ?? '', ['queued', 'running'], true)) {
                $this->line('  '.$this->importLine($operation));
                $this->line('  It is still under way. Cancel it in the console, on the page of the environment.');

                return self::SUCCESS;
            }

            return $this->importEnded($operation) ? self::SUCCESS : self::FAILURE;
        });
    }
}
