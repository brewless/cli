<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ManagesCapacity;
use App\Commands\Concerns\TalksToBrewless;
use Carbon\CarbonImmutable;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function is_array;
use function is_string;

final class DbBoostExtendCommand extends Command
{
    use ManagesCapacity;
    use TalksToBrewless;

    protected $signature = 'db:boost:extend {environment : The environment whose database is boosted}
        {key : The key of the boost, as db:capacity shows it}
        {--until= : The new end: a time on the day the boost ends now, such as 14:00, or a date and time}';

    protected $description = 'Let a boost of an environment\'s database end later';

    public function handle(): int
    {
        return $this->attemptCapacity(function (): int {
            if (! is_string($this->option('until')) || $this->option('until') === '') {
                throw new RuntimeException('Say until when: brewless db:boost:extend '.$this->argument('environment').' '.$this->argument('key').' --until=14:00');
            }

            $slug = (string) $this->argument('environment');
            $key = (string) $this->argument('key');
            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            // A time alone is on the day the boost ends now.
            $boost = $this->boost($this->capacity($api, $environment), $key);
            $end = is_string($boost['ends_at'] ?? null) ? CarbonImmutable::parse($boost['ends_at'])->setTimezone($this->localZone()) : $this->localNow();
            $until = $this->until($this->option('until'), $end->startOfDay());

            $answer = $api->post($this->capacityPath($environment).'/boosts/'.rawurlencode($key).'/extend', ['ends_at' => $until->toIso8601String()]);
            $boost = is_array($answer['data'] ?? null) ? $answer['data'] : [];
            $operation = is_array($answer['operation'] ?? null) ? $answer['operation'] : null;

            if ($operation !== null) {
                $operation = $this->followCapacity($api, $operation);

                if (($operation['status'] ?? '') !== 'succeeded') {
                    $this->components->error(is_string($operation['error_message'] ?? null) ? $operation['error_message'] : 'Scaleway did not take the new bounds.');

                    return self::FAILURE;
                }
            }

            $this->components->info('Boost '.$key.' now ends at '.$this->when($boost['ends_at'] ?? $until->toIso8601String()).((int) ($boost['cooldown_minutes'] ?? 0) > 0 ? ' and cools down until '.$this->when($boost['window_ends_at'] ?? null, $boost['ends_at'] ?? null) : '').'.');

            return self::SUCCESS;
        });
    }
}
