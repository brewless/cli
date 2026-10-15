<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TalksToBrewless;
use App\Support\Api;
use App\Support\EnvFile;
use App\Support\SecretManager;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

final class EnvPushCommand extends Command
{
    use TalksToBrewless;

    protected $signature = 'env:push {environment : The environment whose variables to replace}
        {--file= : The file to push; .env.<environment> when left out}
        {--force : Push without asking, also over a revision you did not pull}
        {--direct : Write them at Scaleway with a key of your own, so the values never pass through Brewless}';

    protected $description = 'Write an environment\'s variables to your Secret Manager';

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

            return $this->option('direct')
                ? $this->direct($api, (string) $environment['id'], $slug, $path, $local, $content, $names, $state)
                : $this->throughBrewless($api, (string) $environment['id'], $slug, $path, $local, $content, $names, $state);
        });
    }

    /**
     * Brewless writes the file to your Secret Manager with the connection it
     * has, and keeps the names and a checksum. It refuses a push over a
     * revision this file was not pulled at, unless forced.
     *
     * @param  list<string>  $names
     * @param  array<string, mixed>  $state
     */
    private function throughBrewless(Api $api, string $environmentId, string $slug, string $path, string $local, string $content, array $names, array $state): int
    {
        $known = is_int($state['revision'] ?? null) ? $state['revision'] : null;

        if ($known !== null && ! $this->option('force')) {
            $this->refuseUnseen($slug, $local, $known);

            if (! $this->confirmRemovals($slug, array_values(array_diff((array) ($state['keys'] ?? []), $names)))) {
                return self::SUCCESS;
            }
        }

        $answer = $api->put('/api/environments/'.$environmentId.'/env-file', [
            'content' => $content,
            'base_revision' => EnvFile::revision($local),
            'force' => (bool) $this->option('force'),
            'via' => 'cli',
        ]);
        $revision = (int) ($answer['data']['revision'] ?? 0);

        if (($answer['changed'] ?? true) === false) {
            $this->components->info('Nothing changed: '.$slug.' is at revision '.$revision.' already.');

            return self::SUCCESS;
        }

        file_put_contents($path, EnvFile::withHeader($content, $revision, (string) ($state['secret']['name'] ?? '')));

        $this->components->info('Pushed revision '.$revision.' ('.count($names).' variables) to your Secret Manager.');
        $this->line('  It goes live with the next deploy: brewless deploy '.$slug);

        return self::SUCCESS;
    }

    /**
     * Written at Scaleway with your own key. Brewless gets the revision, the
     * names and a checksum. Not one value.
     *
     * @param  list<string>  $names
     * @param  array<string, mixed>  $state
     */
    private function direct(Api $api, string $environmentId, string $slug, string $path, string $local, string $content, array $names, array $state): int
    {
        $name = (string) $state['secret']['name'];
        $secrets = new SecretManager(SecretManager::key(), (string) $state['secret']['region'], (string) $state['secret']['project_id']);

        $id = $secrets->find($name);
        $latest = $id === null ? null : $secrets->latest($id);

        if ($latest !== null && ! $this->option('force')) {
            $this->refuseUnseen($slug, $local, $latest['revision']);

            if (! $this->confirmRemovals($slug, array_values(array_diff(EnvFile::names($latest['content']), $names)))) {
                return self::SUCCESS;
            }
        }

        if ($latest !== null && hash('sha256', $latest['content']) === hash('sha256', $content)) {
            $this->components->info('Nothing changed: '.$slug.' is at revision '.$latest['revision'].' already.');

            return self::SUCCESS;
        }

        $id ??= $secrets->create($name, (string) $state['secret']['path']);
        $revision = $secrets->write($id, $content);

        $api->post('/api/environments/'.$environmentId.'/secrets', ['revision' => $revision, 'keys' => $names, 'checksum' => hash('sha256', $content)]);

        file_put_contents($path, EnvFile::withHeader($content, $revision, $name));

        $this->components->info('Pushed revision '.$revision.' ('.count($names).' variables) to your Secret Manager.');
        $this->line('  It goes live with the next deploy: brewless deploy '.$slug);

        return self::SUCCESS;
    }

    /**
     * Pushing replaces everything. Never do that over a revision you have not seen.
     */
    private function refuseUnseen(string $slug, string $local, int $latest): void
    {
        if (EnvFile::revision($local) !== $latest) {
            throw new RuntimeException('The variables of '.$slug.' are at revision '.$latest.' and this file was '
                .(EnvFile::revision($local) === null ? 'not pulled' : 'pulled at revision '.EnvFile::revision($local))
                .'. Pushing would overwrite what changed in between. Run brewless env:pull '.$slug.' first, or push with --force.');
        }
    }

    /**
     * @param  list<string>  $gone
     */
    private function confirmRemovals(string $slug, array $gone): bool
    {
        if ($gone === []) {
            return true;
        }

        $this->components->warn('This push removes '.count($gone).' '.(count($gone) === 1 ? 'variable' : 'variables').': '.implode(', ', $gone));

        return $this->confirm('Remove '.(count($gone) === 1 ? 'it' : 'them').' from '.$slug.'?');
    }
}
