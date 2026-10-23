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
        {--file= : Where to write them; .env.<environment> when left out}
        {--direct : Read them at Scaleway with a key of your own, so the values never pass through Brewless}';

    protected $description = 'Fetch an environment\'s variables from your Secret Manager';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);
            $path = EnvFile::path((string) getcwd(), $slug, $this->option('file'));

            if ($this->option('direct')) {
                // Brewless only says where the variables are kept. They are read at Scaleway with your own key.
                $state = $api->get('/api/environments/'.$environment['id'].'/secrets')['data'] ?? [];
                $secrets = new SecretManager(SecretManager::key(), (string) $state['secret']['region'], (string) $state['secret']['project_id']);
                $id = $secrets->find((string) $state['secret']['name']);
                $latest = $id === null ? null : $secrets->latest($id);
                $secret = (string) $state['secret']['name'];
            } else {
                // Brewless reads them from your Secret Manager with the connection it has, and passes them on without keeping them.
                $file = $api->get('/api/environments/'.$environment['id'].'/env-file', ['via' => 'cli'])['data'] ?? [];
                $latest = is_int($file['revision'] ?? null) ? ['revision' => $file['revision'], 'content' => (string) $file['content']] : null;
                $secret = (string) ($file['secret'] ?? '');
            }

            if ($latest === null) {
                $this->components->info('No variables have been pushed for '.$slug.' yet.');
                $this->line('  Put them in '.basename($path).' and run: brewless env:push '.$slug);

                return self::SUCCESS;
            }

            // Created empty with the right mode first, so the values are never in a file others can read.
            touch($path);
            chmod($path, 0600);
            file_put_contents($path, EnvFile::withHeader($latest['content'], $latest['revision'], $secret));

            $this->components->info('Wrote revision '.$latest['revision'].' ('.count(EnvFile::names($latest['content'])).' variables) to '.basename($path).'.');
            $this->line('  It holds secrets: keep it out of git.');
            $this->keepOutOfGit($path);

            return self::SUCCESS;
        });
    }

    /**
     * Laravel's own .gitignore names .env.production but no other
     * environment: a file this command wrote for staging would show up as
     * something to commit. Say so, and in a terminal offer to add it.
     */
    private function keepOutOfGit(string $path): void
    {
        $directory = dirname($path);
        exec('git -C '.escapeshellarg($directory).' check-ignore -q '.escapeshellarg(basename($path)).' 2>/dev/null', $output, $status);

        // 0: ignored. 128: not a git repository. Only 1 means git would offer to commit it.
        if ($status !== 1) {
            return;
        }

        $this->components->warn(basename($path).' is not in .gitignore, so git would offer to commit it.');

        if ($this->input->isInteractive() && $this->confirm('Add '.basename($path).' to .gitignore?', true)) {
            $gitignore = $directory.'/.gitignore';
            $current = is_file($gitignore) ? (string) file_get_contents($gitignore) : '';
            file_put_contents($gitignore, ($current === '' || str_ends_with($current, "\n") ? '' : "\n").basename($path)."\n", FILE_APPEND);
            $this->components->info('Added '.basename($path).' to .gitignore.');
        }
    }
}
