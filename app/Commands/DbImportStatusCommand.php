<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\FollowsImports;
use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

final class DbImportStatusCommand extends Command
{
    use FollowsImports;
    use TalksToBrewless;

    protected $signature = 'db:import-status {environment : The environment whose database the copy goes into}';

    protected $description = 'Show the last copy of a database into an environment\'s database';

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

            $operation = $api->get('/api/environments/'.$environment['id'].'/operations', ['kind' => 'database.import'])['data'][0] ?? null;

            if (! is_array($operation)) {
                $this->components->info('No database was copied into '.$slug.' in your Scaleway project yet.');
                $this->line('  A copy with --local is written to the audit trail only.');

                return self::SUCCESS;
            }

            $this->newLine();
            $this->line('  Copy into the database of <options=bold>'.$slug.'</>, started '.($operation['created_at'] ?? '').(is_string($operation['actor_name'] ?? null) ? ' by '.$operation['actor_name'] : ''));

            if (in_array($operation['status'] ?? '', ['queued', 'running'], true)) {
                $this->line('  '.$this->importLine($operation));
                $this->line('  It is still under way. Cancel it in the console, on the page of the environment.');

                return self::SUCCESS;
            }

            return $this->importEnded($operation) ? self::SUCCESS : self::FAILURE;
        });
    }
}
