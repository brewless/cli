<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use App\Commands\Concerns\WritesExports;
use LaravelZero\Framework\Commands\Command;

final class DetachCommand extends Command
{
    use TalksToBrewless;
    use WritesExports;

    protected $signature = 'detach {environment : The environment Brewless stops managing}
        {--output= : The folder for the last export; default brewless-export-<application>-<environment>}
        {--force : Do not ask}';

    protected $description = 'Stop Brewless managing an environment. Everything keeps running in your own accounts';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $name = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $name);

            $this->line('Detaching '.$name.' ends what Brewless does for it:');
            $this->line('  stops    deploying, rolling back, scaling, checking for changes made elsewhere');
            $this->line('  stays    your containers, database, domains, certificates, the trigger of the worker, your secrets');
            $this->line('Nothing at your providers is touched. A last export is written first.');

            if (! $this->option('force') && ! $this->confirm('Detach '.$name.'?', false)) {
                $this->components->info('Nothing was detached.');

                return self::SUCCESS;
            }

            $result = $api->post('/api/environments/'.$environment['id'].'/detach', ['confirm' => $environment['slug'] ?? $name])['data'] ?? [];
            $folder = $this->writeExport($result, $this->option('output'));

            $this->components->info($name.' is detached: '.($result['released'] ?? 0).' resources are yours alone again. The last export is in '.$folder.'.');

            if (($result['resources_left'] ?? 1) === 0 && ($result['providers_connected'] ?? []) !== []) {
                $this->components->warn('Brewless manages nothing else for you and still holds a key of '.implode(', ', $result['providers_connected']).'. Disconnect on the Providers page, and delete the key at the provider to end its access for good.');
            }

            return self::SUCCESS;
        });
    }
}
