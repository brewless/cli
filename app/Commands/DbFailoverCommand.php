<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\FollowsReleases;
use App\Commands\Concerns\TalksToBrewless;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

use function in_array;
use function is_array;
use function is_string;

final class DbFailoverCommand extends Command
{
    use FollowsReleases;
    use TalksToBrewless;

    /** What each step of the switch says once it is done. */
    private const array STEPS = [
        'promote' => 'Standby copy made a database of its own',
        'address' => 'New database ready',
        'variables' => 'DB_HOST and DB_PORT point at it',
        'records' => 'Old database let go; it keeps running at Scaleway',
        'release' => 'Release started with the new variables',
    ];

    protected $signature = 'db:failover {environment : The environment to switch to its standby database}
        {--force : Do not ask for confirmation}';

    protected $description = 'Switch an environment to the standby copy of its Managed Database';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $this->newLine();
            $this->line('  Switching <options=bold>'.$project->application.'</> on <options=bold>'.$slug.'</> to the standby copy of its database.');
            $this->line('  The copy runs in another zone and is kept up to date by Scaleway, a few seconds behind:');
            $this->line('  what was written in the last seconds before the switch can be missing.');
            $this->line('  The copy becomes a database of its own and cannot be turned back into a copy.');
            $this->line('  The database before it keeps running, and costing, at Scaleway until you remove it there.');

            if (! $this->option('force') && ! $this->confirm('Switch '.$slug.' to the standby database?')) {
                return self::SUCCESS;
            }

            $operation = $api->post('/api/environments/'.$environment['id'].'/database/failover', ['key' => 'cli-'.Str::lower(Str::random(24))])['data'] ?? [];
            $printed = [];

            while (true) {
                foreach ($operation['steps'] ?? [] as $step) {
                    $name = (string) ($step['name'] ?? '');

                    if (($step['status'] ?? '') === 'succeeded' && ! isset($printed[$name])) {
                        $printed[$name] = true;
                        $this->line('  <fg=green>✓</> '.(self::STEPS[$name] ?? $name));
                    }
                }

                if (in_array($operation['status'] ?? '', ['succeeded', 'failed', 'cancelled'], true)) {
                    break;
                }

                Sleep::for(5)->seconds();

                $operation = $api->get('/api/operations/'.$operation['id'])['data'] ?? $operation;
            }

            if (($operation['status'] ?? '') !== 'succeeded') {
                $this->newLine();
                $this->components->error(is_string($operation['error_message'] ?? null) ? $operation['error_message'] : 'The switch did not finish.');

                return self::FAILURE;
            }

            $result = is_array($operation['result'] ?? null) ? $operation['result'] : [];
            $release = is_string($result['release_id'] ?? null) ? $api->get('/api/releases/'.$result['release_id'])['data'] ?? [] : [];

            if ($release === []) {
                $this->components->warn('The database was switched. Deploy to put the new variables live.');

                return self::SUCCESS;
            }

            $this->newLine();
            $this->line('  Release '.$release['number']);

            return $this->conclude($api, $this->follow($api, $release), $environment) ? self::SUCCESS : self::FAILURE;
        });
    }
}
