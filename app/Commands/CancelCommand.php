<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\FollowsReleases;
use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class CancelCommand extends Command
{
    use FollowsReleases;
    use TalksToBrewless;

    protected $signature = 'cancel {environment : The environment whose release under way to stop}
        {--yes : Do not ask for confirmation}';

    protected $description = 'Cancel the release that is under way in an environment';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $release = $this->releaseUnderWay($api->get('/api/environments/'.$environment['id'].'/releases')['data'] ?? []);

            if ($release === null) {
                $this->components->info('No release of '.$slug.' is under way. Nothing was cancelled.');

                return self::SUCCESS;
            }

            $step = $this->runningStep($release);
            $hint = $step['error_message'] ?? null;

            $this->newLine();
            $this->line('  Release '.$release['number'].' (commit '.substr((string) $release['commit'], 0, 12).') is '.$release['status']
                .($step === null ? '' : ', at: '.$this->stepLabel((string) $step['name'], $release))
                .(is_string($hint) && $hint !== '' ? ' <fg=gray>· '.OutputFormatter::escape($hint).'</>' : ''));
            $this->line('  What earlier steps did is not undone. The release that is live now stays live.');

            if (! $this->option('yes') && ! $this->confirm('Cancel release '.$release['number'].' of '.$slug.'?')) {
                return self::SUCCESS;
            }

            $this->reportCancel($api->post('/api/releases/'.$release['id'].'/cancel')['data'] ?? $release, $slug);

            return self::SUCCESS;
        });
    }
}
