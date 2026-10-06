<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\Api;
use App\Support\ApiException;
use App\Support\Credentials;
use App\Support\Project;
use RuntimeException;

/**
 * What every command that calls Brewless shares: the project it is run in,
 * the sign-in of this machine, and one way to say what went wrong.
 */
trait TalksToBrewless
{
    protected function project(): Project
    {
        return Project::read((string) getcwd());
    }

    /**
     * The api of an organisation, signed in as whoever ran `brewless login` for it.
     */
    protected function api(string $organisation): Api
    {
        $credentials = resolve(Credentials::class)->for((string) config('brewless.host'), $organisation);

        if ($credentials === null) {
            throw new RuntimeException('You are not signed in to '.$organisation.'. Run: brewless login '.$organisation);
        }

        return new Api($organisation, $credentials['token']);
    }

    /**
     * The application of the project and one of its environments, as Brewless knows them.
     *
     * @return array{application: array<string, mixed>, environment: array<string, mixed>}
     */
    protected function environment(Api $api, Project $project, string $slug): array
    {
        foreach ($api->get('/api/applications')['data'] ?? [] as $application) {
            if (($application['slug'] ?? null) !== $project->application) {
                continue;
            }

            foreach ($application['environments'] ?? [] as $environment) {
                if (($environment['slug'] ?? null) === $slug) {
                    return ['application' => $application, 'environment' => $environment];
                }
            }

            $known = implode(', ', array_column($application['environments'] ?? [], 'slug'));

            throw new RuntimeException('The application '.$project->application.' has no environment "'.$slug.'".'.($known === '' ? ' Add one in the console first.' : ' It has: '.$known.'.'));
        }

        throw new RuntimeException('The organisation '.$project->organisation.' has no application "'.$project->application.'". Check brewless.yml, or run brewless init.');
    }

    /**
     * Run a command's work and turn what can go wrong into one line and a failing exit code.
     *
     * @param  callable(): int  $work
     */
    protected function attempt(callable $work): int
    {
        try {
            return $work();
        } catch (ApiException|RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
