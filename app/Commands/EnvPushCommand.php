<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use App\Support\EnvFile;
use App\Support\SecretManager;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

final class EnvPushCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'env:push {environment : The environment whose variables to replace}
        {--file= : The file to push; .env.<environment> when left out}
        {--force : Push without asking, also over a revision you did not pull}';

    protected $description = 'Write an environment\'s variables to your own Secret Manager';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $project = $this->project();
            $api = $this->api($project->organisation);
            $slug = (string) $this->argument('environment');
            ['environment' => $environment] = $this->environment($api, $project, $slug);

            $path = EnvFile::path((string) getcwd(), $slug, $this->option('file'));

            if (! is_file($path)) {
                throw new RuntimeException('There is no '.basename($path).' here. Run: brewless env:pull '.$slug);
            }

            $local = (string) file_get_contents($path);
            $content = EnvFile::withoutHeader($local);

            // Scaleway does not take an empty version, so "no variables" is said in a comment.
            if (trim($content) === '') {
                $content = EnvFile::NONE;
            }
            $names = EnvFile::names($content);

            $state = $api->get('/api/environments/'.$environment['id'].'/secrets')['data'] ?? [];
            $name = (string) $state['secret']['name'];
            $secrets = new SecretManager(SecretManager::key(), (string) $state['secret']['region'], (string) $state['secret']['project_id']);

            $id = $secrets->find($name);
            $latest = $id === null ? null : $secrets->latest($id);

            if ($latest !== null && ! $this->option('force')) {
                // Pushing replaces everything. Never do that over a revision you have not seen.
                if (EnvFile::revision($local) !== $latest['revision']) {
                    throw new RuntimeException('The variables of '.$slug.' are at revision '.$latest['revision'].' and this file was '
                        .(EnvFile::revision($local) === null ? 'not pulled' : 'pulled at revision '.EnvFile::revision($local))
                        .'. Pushing would overwrite what changed in between. Run brewless env:pull '.$slug.' first, or push with --force.');
                }

                $gone = array_values(array_diff(EnvFile::names($latest['content']), $names));

                if ($gone !== []) {
                    $this->components->warn('This push removes '.count($gone).' '.(count($gone) === 1 ? 'variable' : 'variables').': '.implode(', ', $gone));

                    if (! $this->confirm('Remove '.(count($gone) === 1 ? 'it' : 'them').' from '.$slug.'?')) {
                        return self::SUCCESS;
                    }
                }
            }

            if ($latest !== null && hash('sha256', $latest['content']) === hash('sha256', $content)) {
                $this->components->info('Nothing changed: '.$slug.' is at revision '.$latest['revision'].' already.');

                return self::SUCCESS;
            }

            $id ??= $secrets->create($name, (string) $state['secret']['path']);
            $revision = $secrets->write($id, $content);

            // Brewless gets the revision, the names and a checksum. Not one value.
            $api->post('/api/environments/'.$environment['id'].'/secrets', ['revision' => $revision, 'keys' => $names, 'checksum' => hash('sha256', $content)]);

            file_put_contents($path, EnvFile::withHeader($content, $revision, $name));

            $this->components->info('Pushed revision '.$revision.' ('.count($names).' variables) to your Secret Manager.');
            $this->line('  It goes live with the next deploy: brewless deploy '.$slug);

            return self::SUCCESS;
        });
    }
}
