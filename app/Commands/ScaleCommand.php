<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

final class ScaleCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'scale {environment : The environment to scale}
        {--min= : The fewest replicas; 0 lets the container sleep when idle}
        {--max= : The most replicas your provider may start}';

    protected $description = 'Set between how many replicas your provider scales an environment';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            if (! is_numeric($this->option('min')) || ! is_numeric($this->option('max'))) {
                throw new RuntimeException('Give both bounds: brewless scale '.$this->argument('environment').' --min=1 --max=20');
            }

            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, (string) $this->argument('environment'));

            $operation = $api->post('/api/environments/'.$environment['id'].'/scale', [
                'min' => (int) $this->option('min'),
                'max' => (int) $this->option('max'),
                'key' => 'cli-'.Str::lower(Str::random(24)),
            ])['data'] ?? [];

            // The provider rolls the container to its new bounds; that takes a moment.
            while (! in_array($operation['status'] ?? '', ['succeeded', 'failed', 'cancelled'], true)) {
                Sleep::for(3)->seconds();

                $operation = $api->get('/api/operations/'.$operation['id'])['data'] ?? $operation;
            }

            if (($operation['status'] ?? '') !== 'succeeded') {
                $this->components->error($operation['error_message'] ?? 'The scale was not changed.');

                return self::FAILURE;
            }

            $this->components->info($this->argument('environment').' now scales between '.(int) $this->option('min').' and '.(int) $this->option('max').' replicas.');

            return self::SUCCESS;
        });
    }
}
