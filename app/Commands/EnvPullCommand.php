<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use App\Support\EnvFile;
use App\Support\SecretManager;
use LaravelZero\Framework\Commands\Command;

final class EnvPullCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'env:pull {environment : The environment whose variables to fetch}
        {--file= : Where to write them; .env.<environment> when left out}';

    protected $description = 'Fetch an environment\'s variables from your own Secret Manager';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            // Brewless only says where the variables are kept. They are read at Scaleway with your own key.
            $state = $api->get('/api/environments/'.$environment['id'].'/secrets')['data'] ?? [];
            $secrets = new SecretManager(SecretManager::key(), (string) $state['secret']['region'], (string) $state['secret']['project_id']);

            $id = $secrets->find((string) $state['secret']['name']);
            $latest = $id === null ? null : $secrets->latest($id);
            $path = EnvFile::path((string) getcwd(), $slug, $this->option('file'));

            if ($latest === null) {
                $this->components->info('No variables have been pushed for '.$slug.' yet.');
                $this->line('  Put them in '.basename($path).' and run: brewless env:push '.$slug);

                return self::SUCCESS;
            }

            // Created empty with the right mode first, so the values are never in a file others can read.
            touch($path);
            chmod($path, 0600);
            file_put_contents($path, EnvFile::withHeader($latest['content'], $latest['revision'], (string) $state['secret']['name']));

            $this->components->info('Wrote revision '.$latest['revision'].' ('.count(EnvFile::names($latest['content'])).' variables) to '.basename($path).'.');
            $this->line('  It holds secrets: keep it out of git.');

            return self::SUCCESS;
        });
    }
}
