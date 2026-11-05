<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\FollowsReleases;
use App\Commands\Concerns\ShowsLocalTimes;
use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class ReleasesCommand extends Command
{
    use FollowsReleases;
    use ShowsLocalTimes;
    use TalksToBrewless;

    protected $signature = 'releases {environment : The environment whose releases to list}';

    protected $description = 'List the latest releases of an environment';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $releases = $api->get('/api/environments/'.$environment['id'].'/releases')['data'] ?? [];

            if ($releases === []) {
                $this->components->info('No releases yet. Run: brewless deploy '.$slug);

                return self::SUCCESS;
            }

            $this->table(['Release', 'Status', 'Commit', 'By', 'Started ('.$this->localZone()->getName().')'], array_map(fn (array $release): array => [
                $release['number'].(($release['source'] ?? '') === 'rollback' ? ' (rollback)' : ''),
                $release['status'],
                substr((string) $release['commit'], 0, 12),
                $release['actor_name'] ?? '',
                $this->localMoment($release['created_at'] ?? null),
            ], $releases));

            $this->underWay($releases, $slug);

            return self::SUCCESS;
        });
    }

    /**
     * What the release under way is at, and what it waits for, as Brewless
     * says it: otherwise only the console shows why a release takes long.
     *
     * @param  list<array<string, mixed>>  $releases
     */
    private function underWay(array $releases, string $slug): void
    {
        $release = $this->releaseUnderWay($releases);
        $step = $release === null ? null : $this->runningStep($release);

        if ($release === null || $step === null) {
            return;
        }

        $hint = $step['error_message'] ?? null;

        $this->newLine();
        $this->line('  Release '.$release['number'].' is at: '.$this->stepLabel((string) $step['name'], $release)
            .(is_string($hint) && $hint !== '' ? ' <fg=gray>· '.OutputFormatter::escape($hint).'</>' : ''));
        $this->line('  <fg=gray>Stop it with: brewless cancel '.$slug.'</>');
    }
}
