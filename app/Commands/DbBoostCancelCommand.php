<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ManagesCapacity;
use App\Commands\Concerns\TalksToBrewless;
use LaravelZero\Framework\Commands\Command;

use function in_array;
use function is_array;

final class DbBoostCancelCommand extends Command
{
    use ManagesCapacity;
    use TalksToBrewless;

    protected $signature = 'db:boost:cancel {environment : The environment whose database is boosted}
        {key : The key of the boost, as db:capacity shows it}
        {--yes : Do not ask for confirmation}';

    protected $description = 'Cancel a boost of an environment\'s database, or end a running one now';

    public function handle(): int
    {
        return $this->attemptCapacity(function (): int {
            $slug = (string) $this->argument('environment');
            $key = (string) $this->argument('key');
            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $boost = $this->boost($this->capacity($api, $environment), $key);

            $this->newLine();
            $this->line(match (true) {
                in_array($boost['status'] ?? null, ['prewarming', 'ready', 'active'], true) => '  Boost '.$key.' is running. Cancelling ends it now: the database keeps the raised bounds for its cooldown of '.(int) ($boost['cooldown_minutes'] ?? 0).' minutes and then goes back to its normal bounds.',
                ($boost['status'] ?? null) === 'scheduled' => '  Boost '.$key.' has not begun. Cancelling it changes nothing at Scaleway.',
                default => '  A boost that has not begun is cancelled at once; a running one ends now and cools down first.',
            });

            if (! $this->option('yes') && ! $this->confirm('Cancel boost '.$key.' of '.$slug.'?')) {
                return self::SUCCESS;
            }

            $answer = $api->delete($this->capacityPath($environment).'/boosts/'.rawurlencode($key));
            $boost = is_array($answer['data'] ?? null) ? $answer['data'] : [];

            $this->components->info(match ($boost['status'] ?? '') {
                'cancelled' => 'Boost '.$key.' is cancelled.',
                'cooling_down' => 'Boost '.$key.' ended now. It cools down until '.$this->when($boost['window_ends_at'] ?? null).', then the database goes back to its normal bounds.',
                default => 'Boost '.$key.' was over already; nothing changed.',
            });

            return self::SUCCESS;
        });
    }
}
