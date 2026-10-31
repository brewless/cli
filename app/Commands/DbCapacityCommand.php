<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ManagesCapacity;
use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

use function in_array;
use function is_array;
use function is_numeric;
use function is_string;

final class DbCapacityCommand extends Command
{
    use ManagesCapacity;
    use TalksToBrewless;

    protected $signature = 'db:capacity {environment : The environment whose database to show}
        {--database= : One database of the environment, by name, when it has more than one}';

    protected $description = 'Show the vCPU bounds of an environment\'s Serverless SQL Database and its boosts';

    public function handle(): int
    {
        return $this->attemptCapacity(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);
            $database = is_string($this->option('database')) ? $this->option('database') : null;

            $capacity = $this->capacity($api, $environment, $database);

            $this->newLine();
            $this->describeDatabase($capacity, $slug);
            $this->newLine();

            $this->components->twoColumnDetail('Normal bounds', $this->bounds(is_array($capacity['baseline'] ?? null) ? $capacity['baseline'] : null));
            $this->components->twoColumnDetail('Wanted now', $this->bounds(is_array($capacity['effective'] ?? null) ? $capacity['effective'] : null));
            $this->components->twoColumnDetail('Scaleway gives now', (is_numeric($capacity['cpu_current'] ?? null) ? rtrim(rtrim(number_format((float) $capacity['cpu_current'], 2), '0'), '.') : '?').' vCPU');
            $this->components->twoColumnDetail('Status', (string) ($capacity['status'] ?? '?'));

            $this->capacityNotices($capacity);

            if (($capacity['allowed'] ?? true) === false) {
                $this->newLine();
                $this->line('  Boosts on top of these bounds come with the Business plan and up: '.$this->billing($project->organisation));

                return self::SUCCESS;
            }

            $boosts = array_values(array_filter(is_array($capacity['boosts'] ?? null) ? $capacity['boosts'] : [], is_array(...)));

            $this->newLine();

            if ($boosts === []) {
                $this->line('  No boosts. Start one with: brewless db:boost '.$slug.' --min=4 --max=15 --for=2h');

                return self::SUCCESS;
            }

            $this->table(['Boost', 'Key', 'Window', 'Status', 'Ready', 'vCPU', 'By'], array_map(fn (array $boost): array => [
                (string) ($boost['name'] ?? $boost['key'] ?? ''),
                (string) ($boost['key'] ?? ''),
                $this->window($boost),
                str_replace('_', ' ', (string) ($boost['status'] ?? '')),
                $this->ready($boost),
                ($boost['min_vcpu'] ?? '?').'–'.($boost['max_vcpu'] ?? '?'),
                (string) ($boost['source'] ?? ''),
            ], $boosts));

            foreach ($boosts as $boost) {
                if (($boost['status'] ?? '') === 'failed' && is_string($boost['failure_reason'] ?? null)) {
                    $this->components->warn('Boost '.($boost['key'] ?? '').' failed: '.$boost['failure_reason']);
                }
            }

            return self::SUCCESS;
        });
    }

    /**
     * Drift at Scaleway, a change that keeps failing, and a change under way.
     *
     * @param  array<string, mixed>  $capacity
     */
    private function capacityNotices(array $capacity): void
    {
        $drift = is_array($capacity['drift'] ?? null) ? $capacity['drift'] : null;
        $failure = is_array($capacity['failure'] ?? null) ? $capacity['failure'] : null;
        $operation = is_array($capacity['operation'] ?? null) ? $capacity['operation'] : null;

        if ($drift !== null) {
            $this->newLine();
            $this->components->warn('Since '.$this->when($drift['since'] ?? null).' Scaleway has '.$this->bounds(is_array($drift['at_scaleway'] ?? null) ? $drift['at_scaleway'] : null).', not the bounds Brewless wants: they were changed outside Brewless.');
        }

        if ($failure !== null) {
            $this->newLine();
            $this->components->warn('Since '.$this->when($failure['since'] ?? null).' Brewless could not give the database the bounds it wants'.(is_string($failure['code'] ?? null) ? ' ('.$failure['code'].')' : '').'. It keeps trying.');
        }

        if ($operation !== null && in_array($operation['status'] ?? '', ['queued', 'running'], true)) {
            $this->newLine();
            $this->line('  Brewless is changing the bounds at Scaleway right now.');
        } elseif ($operation !== null && ($operation['status'] ?? '') === 'failed' && $failure === null && is_string($operation['error_message'] ?? null)) {
            $this->newLine();
            $this->components->warn('The last change of the bounds failed: '.$operation['error_message']);
        }
    }

    /**
     * When the boost holds, and around it when it warms up and cools down.
     *
     * @param  array<string, mixed>  $boost
     */
    private function window(array $boost): string
    {
        $window = $this->when($boost['starts_at'] ?? null).' → '.$this->when($boost['ends_at'] ?? null, $boost['starts_at'] ?? null);
        $around = [];

        if ((int) ($boost['prewarm_minutes'] ?? 0) > 0) {
            $around[] = 'warm '.$this->when($boost['window_starts_at'] ?? null, $boost['starts_at'] ?? null);
        }

        if ((int) ($boost['cooldown_minutes'] ?? 0) > 0) {
            $around[] = 'cool to '.$this->when($boost['window_ends_at'] ?? null, $boost['ends_at'] ?? null);
        }

        return $window.($around === [] ? '' : ' ('.implode(', ', $around).')');
    }

    /**
     * @param  array<string, mixed>  $boost
     */
    private function ready(array $boost): string
    {
        if (($boost['ready'] ?? false) === true) {
            return '<fg=green>yes</>'.(is_numeric($boost['cpu_current'] ?? null) ? ' ('.$boost['cpu_current'].')' : '');
        }

        return in_array($boost['status'] ?? '', ['prewarming', 'active', 'ready'], true) ? 'not yet' : '';
    }
}
