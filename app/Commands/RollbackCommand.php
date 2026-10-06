<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\FollowsReleases;
use App\Commands\Concerns\TalksToBrewless;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

final class RollbackCommand extends Command
{
    use FollowsReleases;
    use TalksToBrewless;

    protected $signature = 'rollback {environment : The environment to roll back}
        {--release= : The number of the release to put back; the one before the live one when left out}
        {--force : Do not ask for confirmation}';

    protected $description = 'Put the image of an earlier release live again';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $releases = $api->get('/api/environments/'.$environment['id'].'/releases')['data'] ?? [];
            $target = $this->target($releases);

            $this->newLine();
            $this->line('  Rolling <options=bold>'.$project->application.'</> on <options=bold>'.$slug.'</> back to release '.$target['number'].' (commit '.substr((string) $target['commit'], 0, 12).')');
            $this->line('  The database is left as it is: nothing is migrated back.');

            if ($target['migrations_since'] ?? false) {
                $this->components->warn('Releases after this one changed the database. Its code has to work with that newer schema.');
            }

            if (! $this->option('force') && ! $this->confirm('Put release '.$target['number'].' live again?')) {
                return self::SUCCESS;
            }

            $release = $api->post('/api/releases/'.$target['id'].'/rollback', ['key' => 'cli-'.Str::lower(Str::random(24))])['data'] ?? [];

            $this->line('  Release '.$release['number']);

            return $this->conclude($api, $this->follow($api, $release), $environment) ? self::SUCCESS : self::FAILURE;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $releases  newest first
     * @return array<string, mixed>
     */
    private function target(array $releases): array
    {
        $number = $this->option('release');

        foreach ($releases as $release) {
            if (! ($release['can_roll_back'] ?? false)) {
                if ($number !== null && (int) $number === (int) $release['number']) {
                    throw new RuntimeException('Release '.$number.' cannot be put back: it is live now, or it never was.');
                }

                continue;
            }

            if ($number === null || (int) $number === (int) $release['number']) {
                return $release;
            }
        }

        throw new RuntimeException($number === null
            ? 'There is no earlier release to go back to.'
            : 'This environment has no release '.$number.' among its last 30.');
    }
}
