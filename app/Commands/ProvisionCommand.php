<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

final class ProvisionCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'provision {environment : The environment to set up}
        {--no-database : Do not make a Serverless SQL Database}';

    protected $description = 'Make what a new environment needs in your own cloud accounts';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            ['environment' => $environment] = $this->environment($api, $project, (string) $this->argument('environment'));

            $operation = $api->post('/api/environments/'.$environment['id'].'/provision', [
                'database' => ! $this->option('no-database'),
                'key' => 'cli-'.Str::lower(Str::random(24)),
            ])['data'] ?? [];

            $shown = [];

            // A namespace and a database take a moment to be made; each step is printed as it finishes.
            while (true) {
                foreach ($operation['steps'] ?? [] as $step) {
                    if (($step['status'] ?? '') === 'succeeded' && ! in_array($step['name'], $shown, true)) {
                        $shown[] = $step['name'];
                        $this->line('  '.$step['name']);
                    }
                }

                if (in_array($operation['status'] ?? '', ['succeeded', 'failed', 'cancelled'], true)) {
                    break;
                }

                Sleep::for(3)->seconds();

                $operation = $api->get('/api/operations/'.$operation['id'])['data'] ?? $operation;
            }

            if (($operation['status'] ?? '') !== 'succeeded') {
                $this->components->error($operation['error_message'] ?? 'The environment was not set up.');

                return self::FAILURE;
            }

            $result = $operation['result'] ?? [];

            $this->components->info($this->argument('environment').' is set up. After your first deploy it answers on '.($result['address'] ?? 'its container').'.');

            if (($result['variables_to_set'] ?? []) !== []) {
                $this->components->warn('Before you deploy, give these a value with `brewless env:pull` and `brewless env:push`: '.implode(', ', $result['variables_to_set']).'.');
            }

            return self::SUCCESS;
        });
    }
}
