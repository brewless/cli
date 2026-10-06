<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

final class ReleasesCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'releases {environment : The environment whose releases to list}';

    protected $description = 'List the latest releases of an environment';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, (string) $this->argument('environment'));

            $releases = $api->get('/api/environments/'.$environment['id'].'/releases')['data'] ?? [];

            if ($releases === []) {
                $this->components->info('No releases yet. Run: brewless deploy '.$this->argument('environment'));

                return self::SUCCESS;
            }

            $this->table(['Release', 'Status', 'Commit', 'By', 'Started'], array_map(static fn (array $release): array => [
                $release['number'].(($release['source'] ?? '') === 'rollback' ? ' (rollback)' : ''),
                $release['status'],
                substr((string) $release['commit'], 0, 12),
                $release['actor_name'] ?? '',
                $release['created_at'],
            ], $releases));

            return self::SUCCESS;
        });
    }
}
